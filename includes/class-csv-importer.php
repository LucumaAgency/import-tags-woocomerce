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

            $row_data = array(
                'line'  => $line,
                'id'    => intval( $id ),
                'title' => sanitize_text_field( $title ),
                'tags'  => $has_tags && isset( $data[ $tags_index ] ) ? trim( $data[ $tags_index ] ) : '',
                'recommendations' => $has_recommendations && isset( $data[ $rec_index ] ) ? trim( $data[ $rec_index ] ) : '',
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

        foreach ( $rows as $row ) {
            $product = wc_get_product( $row['id'] );

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

                    foreach ( $rec_names as $name ) {
                        $found_id = $this->find_product_id_by_name( $name );
                        if ( $found_id ) {
                            $rec_ids[] = $found_id;
                        } else {
                            $not_found[] = $name;
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
            'results' => $results,
            'summary' => array(
                'total'   => count( $rows ),
                'success' => $success,
                'errors'  => $errors,
            ),
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
        $name = sanitize_text_field( $name );
        $cache_key = mb_strtolower( $name );

        if ( isset( $this->product_name_cache[ $cache_key ] ) ) {
            return $this->product_name_cache[ $cache_key ];
        }

        global $wpdb;

        // 1. Buscar match exacto en productos padres
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_title = %s
             AND post_type = 'product'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 1",
            $name
        ) );

        if ( $product_id ) {
            return $this->cache_and_return( $cache_key, intval( $product_id ) );
        }

        // 2. Buscar match exacto en variaciones → resolver al padre
        $variation_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_title = %s
             AND post_type = 'product_variation'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 1",
            $name
        ) );

        if ( $variation_id ) {
            $parent_id = wp_get_post_parent_id( $variation_id );
            if ( $parent_id ) {
                return $this->cache_and_return( $cache_key, intval( $parent_id ) );
            }
        }

        // 3. Búsqueda LIKE flexible en productos padres
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_title LIKE %s
             AND post_type = 'product'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 1",
            '%' . $wpdb->esc_like( $name ) . '%'
        ) );

        if ( $product_id ) {
            return $this->cache_and_return( $cache_key, intval( $product_id ) );
        }

        // 4. Búsqueda LIKE flexible en variaciones → resolver al padre
        $variation_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_title LIKE %s
             AND post_type = 'product_variation'
             AND post_status IN ('publish', 'draft', 'private')
             LIMIT 1",
            '%' . $wpdb->esc_like( $name ) . '%'
        ) );

        if ( $variation_id ) {
            $parent_id = wp_get_post_parent_id( $variation_id );
            if ( $parent_id ) {
                return $this->cache_and_return( $cache_key, intval( $parent_id ) );
            }
        }

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
