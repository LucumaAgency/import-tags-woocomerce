<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITWC_CSV_Importer {

    /**
     * Columnas requeridas en el CSV.
     */
    private const REQUIRED_COLUMNS = array( 'ID', 'Title' );

    /**
     * Separador de etiquetas/recomendaciones.
     */
    private $separator;

    /**
     * Nombre del campo ACF Relationship.
     */
    private $acf_field_name;

    /**
     * Cache de búsqueda de productos por nombre => ID.
     */
    private $product_name_cache = array();

    /**
     * Log de debug.
     */
    private $debug_log = array();

    /**
     * @param string $separator      Carácter separador.
     * @param string $acf_field_name Nombre del campo ACF Relationship.
     */
    public function __construct( $separator = ',', $acf_field_name = '' ) {
        $this->separator      = $separator;
        $this->acf_field_name = sanitize_text_field( $acf_field_name );
    }

    /**
     * Procesa el archivo CSV subido.
     *
     * @return array Resultado con 'success', 'message', y 'results'.
     */
    public function process_upload() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $this->error( 'No tienes permisos para realizar esta acción.' );
        }

        if ( ! isset( $_POST['itwc_nonce'] ) || ! wp_verify_nonce( $_POST['itwc_nonce'], 'itwc_import_tags' ) ) {
            return $this->error( 'Error de seguridad. Recarga la página e intenta de nuevo.' );
        }

        if ( empty( $_FILES['itwc_csv_file']['tmp_name'] ) ) {
            return $this->error( 'No se ha seleccionado ningún archivo.' );
        }

        $file = $_FILES['itwc_csv_file'];

        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( 'csv' !== $extension ) {
            return $this->error( 'El archivo debe ser un CSV (.csv).' );
        }

        $parsed = $this->parse_csv( $file['tmp_name'] );

        if ( is_string( $parsed ) ) {
            return $this->error( $parsed );
        }

        $rows        = $parsed['rows'];
        $has_tags    = $parsed['has_tags'];
        $has_recommendations = $parsed['has_recommendations'];

        if ( empty( $rows ) ) {
            return $this->error( 'El archivo CSV está vacío o no contiene filas de datos.' );
        }

        if ( $has_recommendations && empty( $this->acf_field_name ) ) {
            return $this->error( 'El CSV contiene la columna "Recommended Products" pero no se indicó el nombre del campo ACF.' );
        }

        return $this->import_rows( $rows, $has_tags, $has_recommendations );
    }

    /**
     * Lee y parsea el archivo CSV.
     *
     * @param string $filepath Ruta al archivo temporal.
     * @return array|string Array de filas o mensaje de error.
     */
    private function parse_csv( $filepath ) {
        $handle = fopen( $filepath, 'r' );
        if ( false === $handle ) {
            return 'No se pudo leer el archivo CSV.';
        }

        // Detectar BOM UTF-8 y saltarlo
        $bom = fread( $handle, 3 );
        if ( "\xEF\xBB\xBF" !== $bom ) {
            rewind( $handle );
        }

        $header = fgetcsv( $handle );
        if ( false === $header || null === $header ) {
            fclose( $handle );
            return 'El archivo CSV no contiene encabezados.';
        }

        // Limpiar espacios en los encabezados
        $header = array_map( 'trim', $header );

        // Verificar columnas requeridas
        foreach ( self::REQUIRED_COLUMNS as $col ) {
            if ( ! in_array( $col, $header, true ) ) {
                fclose( $handle );
                return sprintf( 'Falta la columna requerida: "%s". Las columnas necesarias son: %s', $col, implode( ', ', self::REQUIRED_COLUMNS ) );
            }
        }

        $id_index    = array_search( 'ID', $header, true );
        $title_index = array_search( 'Title', $header, true );

        // Columnas opcionales
        $tags_index  = array_search( 'Product Tags', $header, true );
        $rec_index   = array_search( 'Recommended Products', $header, true );

        $has_tags            = false !== $tags_index;
        $has_recommendations = false !== $rec_index;

        $this->debug_log[] = '=== PARSE CSV ===';
        $this->debug_log[] = 'Headers encontrados: ' . wp_json_encode( $header );
        $this->debug_log[] = 'Columna Product Tags: ' . ( $has_tags ? 'SI (index ' . $tags_index . ')' : 'NO' );
        $this->debug_log[] = 'Columna Recommended Products: ' . ( $has_recommendations ? 'SI (index ' . $rec_index . ')' : 'NO' );
        $this->debug_log[] = 'Separador configurado: "' . $this->separator . '"';
        $this->debug_log[] = 'Campo ACF: "' . $this->acf_field_name . '"';

        if ( ! $has_tags && ! $has_recommendations ) {
            fclose( $handle );
            return 'El CSV debe contener al menos una de estas columnas: "Product Tags", "Recommended Products".';
        }

        $rows = array();
        $line = 1;

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $line++;

            if ( count( $data ) <= max( $id_index, $title_index ) ) {
                continue;
            }

            $id   = trim( $data[ $id_index ] );
            $title = trim( $data[ $title_index ] );

            if ( empty( $id ) ) {
                continue;
            }

            $tags_val = $has_tags && isset( $data[ $tags_index ] ) ? trim( $data[ $tags_index ] ) : '';
            $rec_val  = $has_recommendations && isset( $data[ $rec_index ] ) ? trim( $data[ $rec_index ] ) : '';

            $this->debug_log[] = sprintf(
                'Fila %d: ID=%s | Title="%s" | Tags="%s" | Rec="%s" | Raw data=%s',
                $line, $id, $title, $tags_val, $rec_val, wp_json_encode( $data )
            );

            $row_data = array(
                'line'  => $line,
                'id'    => intval( $id ),
                'title' => sanitize_text_field( $title ),
                'tags'  => $tags_val,
                'recommendations' => $rec_val,
            );

            $rows[] = $row_data;
        }

        fclose( $handle );

        return array(
            'rows'                => $rows,
            'has_tags'            => $has_tags,
            'has_recommendations' => $has_recommendations,
        );
    }

    /**
     * Importa etiquetas y/o recomendaciones a los productos.
     *
     * @param array $rows                Filas parseadas del CSV.
     * @param bool  $has_tags            Si el CSV tiene columna Product Tags.
     * @param bool  $has_recommendations Si el CSV tiene columna Recommended Products.
     * @return array Resultado de la importación.
     */
    private function import_rows( $rows, $has_tags, $has_recommendations ) {
        $results = array();
        $success = 0;
        $errors  = 0;

        $this->debug_log[] = '=== IMPORT ROWS ===';

        foreach ( $rows as $row ) {
            $this->debug_log[] = sprintf( '--- Procesando fila: ID=%d ---', $row['id'] );

            // Debug: verificar que el post existe en la BD
            global $wpdb;
            $post_check = $wpdb->get_row( $wpdb->prepare(
                "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE ID = %d",
                $row['id']
            ) );
            if ( $post_check ) {
                $this->debug_log[] = sprintf(
                    '  [DB POST] ID=%s type="%s" status="%s" title="%s"',
                    $post_check->ID, $post_check->post_type, $post_check->post_status, $post_check->post_title
                );
            } else {
                $this->debug_log[] = sprintf( '  [DB POST] ID=%d NO EXISTE en wp_posts', $row['id'] );
            }

            $product = wc_get_product( $row['id'] );
            $this->debug_log[] = '  [wc_get_product] ' . ( $product ? 'OK tipo=' . $product->get_type() . ' name="' . $product->get_name() . '"' : 'FALSE (null/no encontrado)' );

            if ( ! $product ) {
                $results[] = array(
                    'id'              => $row['id'],
                    'title'           => $row['title'],
                    'tags'            => $row['tags'],
                    'recommendations' => $row['recommendations'],
                    'status'          => 'error',
                    'message'         => 'Producto no encontrado.',
                );
                $errors++;
                continue;
            }

            // Resolver al producto padre si es una variación
            $parent_id = $row['id'];
            if ( $product->is_type( 'variation' ) ) {
                $parent_id = $product->get_parent_id();
            }

            $messages    = array();
            $row_success = true;

            // --- Importar etiquetas ---
            if ( $has_tags && ! empty( $row['tags'] ) ) {
                $tags = array_map( 'trim', explode( $this->separator, $row['tags'] ) );
                $tags = array_filter( $tags );
                $tags = array_map( 'sanitize_text_field', $tags );

                if ( ! empty( $tags ) ) {
                    $tag_result = wp_set_object_terms( $parent_id, $tags, 'product_tag', true );
                    if ( is_wp_error( $tag_result ) ) {
                        $messages[]  = 'Tags error: ' . $tag_result->get_error_message();
                        $row_success = false;
                    } else {
                        $messages[] = count( $tags ) . ' etiqueta(s)';
                    }
                }
            }

            // --- Importar recomendaciones ACF ---
            if ( $has_recommendations && ! empty( $row['recommendations'] ) ) {
                $rec_names = array_map( 'trim', explode( $this->separator, $row['recommendations'] ) );
                $rec_names = array_filter( $rec_names );

                if ( ! empty( $rec_names ) ) {
                    $rec_ids    = array();
                    $not_found  = array();

                    $this->debug_log[] = sprintf( '--- Producto ID %d: buscando recomendaciones ---', $row['id'] );
                    $this->debug_log[] = 'Nombres a buscar: ' . wp_json_encode( $rec_names );

                    foreach ( $rec_names as $name ) {
                        $found_id = $this->find_product_id_by_name( $name );
                        if ( $found_id ) {
                            $rec_ids[] = $found_id;
                            $this->debug_log[] = sprintf( '  "%s" => encontrado ID %d', $name, $found_id );
                        } else {
                            $not_found[] = $name;
                            $this->debug_log[] = sprintf( '  "%s" => NO ENCONTRADO', $name );
                        }
                    }

                    if ( ! empty( $rec_ids ) ) {
                        update_field( $this->acf_field_name, $rec_ids, $parent_id );
                        $messages[] = count( $rec_ids ) . ' recomendación(es)';
                    }

                    if ( ! empty( $not_found ) ) {
                        $messages[]  = 'No encontrados: ' . implode( ', ', $not_found );
                        $row_success = empty( $rec_ids ) ? false : $row_success;
                    }
                }
            }

            if ( empty( $messages ) ) {
                $results[] = array(
                    'id'              => $row['id'],
                    'title'           => $product->get_name(),
                    'tags'            => $row['tags'],
                    'recommendations' => $row['recommendations'],
                    'status'          => 'skipped',
                    'message'         => 'Sin datos para importar.',
                );
                continue;
            }

            $status = $row_success ? 'success' : 'error';
            if ( $row_success ) {
                $success++;
            } else {
                $errors++;
            }

            $results[] = array(
                'id'              => $row['id'],
                'title'           => $product->get_name(),
                'tags'            => $row['tags'],
                'recommendations' => $row['recommendations'],
                'status'          => $status,
                'message'         => implode( ' | ', $messages ),
            );
        }

        return array(
            'success' => true,
            'message' => sprintf(
                'Importación completada: %d exitoso(s), %d error(es), %d total.',
                $success,
                $errors,
                count( $rows )
            ),
            'results'   => $results,
            'summary'   => array(
                'total'   => count( $rows ),
                'success' => $success,
                'errors'  => $errors,
            ),
            'debug_log' => $this->debug_log,
        );
    }

    /**
     * Busca un producto por nombre y retorna el ID del producto padre.
     * Busca en productos padres y variaciones. Usa cache.
     *
     * @param string $name Nombre del producto.
     * @return int|false ID del producto padre o false.
     */
    private function find_product_id_by_name( $name ) {
        $original_name = $name;
        $name = sanitize_text_field( $name );
        $cache_key = mb_strtolower( $name );

        $this->debug_log[] = sprintf( '    [BUSCAR] original="%s" | sanitized="%s" | len=%d | hex=%s',
            $original_name, $name, mb_strlen( $name ), bin2hex( mb_substr( $original_name, 0, 50 ) )
        );

        if ( isset( $this->product_name_cache[ $cache_key ] ) ) {
            $this->debug_log[] = '    [CACHE] hit => ' . var_export( $this->product_name_cache[ $cache_key ], true );
            return $this->product_name_cache[ $cache_key ];
        }

        global $wpdb;

        // 1. Buscar match exacto en productos padres
        $sql1 = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_title = %s
             AND post_type = 'product'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 1",
            $name
        );
        $row1 = $wpdb->get_row( $sql1 );
        $this->debug_log[] = '    [Q1 exact product] SQL: ' . $sql1;
        $this->debug_log[] = '    [Q1 result] ' . ( $row1 ? sprintf( 'ID=%s title="%s"', $row1->ID, $row1->post_title ) : 'NULL' );

        if ( $row1 ) {
            return $this->cache_and_return( $cache_key, intval( $row1->ID ) );
        }

        // 2. Buscar match exacto en variaciones → resolver al padre
        $sql2 = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_title = %s
             AND post_type = 'product_variation'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 1",
            $name
        );
        $row2 = $wpdb->get_row( $sql2 );
        $this->debug_log[] = '    [Q2 exact variation] ' . ( $row2 ? sprintf( 'ID=%s title="%s"', $row2->ID, $row2->post_title ) : 'NULL' );

        if ( $row2 ) {
            $parent_id = wp_get_post_parent_id( $row2->ID );
            if ( $parent_id ) {
                return $this->cache_and_return( $cache_key, intval( $parent_id ) );
            }
        }

        // 3. Búsqueda LIKE flexible en productos padres
        $like_pattern = '%' . $wpdb->esc_like( $name ) . '%';
        $sql3 = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_title LIKE %s
             AND post_type = 'product'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 5",
            $like_pattern
        );
        $rows3 = $wpdb->get_results( $sql3 );
        $this->debug_log[] = '    [Q3 LIKE product] pattern="' . $like_pattern . '" results=' . count( $rows3 );
        foreach ( $rows3 as $r ) {
            $this->debug_log[] = sprintf( '      -> ID=%s title="%s"', $r->ID, $r->post_title );
        }

        if ( ! empty( $rows3 ) ) {
            return $this->cache_and_return( $cache_key, intval( $rows3[0]->ID ) );
        }

        // 4. Búsqueda LIKE flexible en variaciones → resolver al padre
        $sql4 = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_title LIKE %s
             AND post_type = 'product_variation'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 5",
            $like_pattern
        );
        $rows4 = $wpdb->get_results( $sql4 );
        $this->debug_log[] = '    [Q4 LIKE variation] results=' . count( $rows4 );
        foreach ( $rows4 as $r ) {
            $this->debug_log[] = sprintf( '      -> ID=%s title="%s"', $r->ID, $r->post_title );
        }

        if ( ! empty( $rows4 ) ) {
            $parent_id = wp_get_post_parent_id( $rows4[0]->ID );
            if ( $parent_id ) {
                return $this->cache_and_return( $cache_key, intval( $parent_id ) );
            }
        }

        $this->debug_log[] = '    [RESULTADO] NO ENCONTRADO';
        $this->product_name_cache[ $cache_key ] = false;
        return false;
    }

    /**
     * Guarda en cache y retorna el ID.
     */
    private function cache_and_return( $cache_key, $id ) {
        $this->product_name_cache[ $cache_key ] = $id;
        return $id;
    }

    /**
     * Retorna un array de error estandarizado.
     */
    private function error( $message ) {
        return array(
            'success' => false,
            'message' => $message,
            'results' => array(),
        );
    }
}
