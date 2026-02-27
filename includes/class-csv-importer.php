<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITWC_CSV_Importer {

    /**
     * Columnas requeridas en el CSV.
     */
    private const REQUIRED_COLUMNS = array( 'ID', 'Title', 'Product Tags' );

    /**
     * Separador de etiquetas.
     */
    private $separator;

    /**
     * @param string $separator Carácter separador de etiquetas.
     */
    public function __construct( $separator = ',' ) {
        $this->separator = $separator;
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

        $rows = $this->parse_csv( $file['tmp_name'] );

        if ( is_string( $rows ) ) {
            return $this->error( $rows );
        }

        if ( empty( $rows ) ) {
            return $this->error( 'El archivo CSV está vacío o no contiene filas de datos.' );
        }

        return $this->import_tags( $rows );
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

        $id_index   = array_search( 'ID', $header, true );
        $title_index = array_search( 'Title', $header, true );
        $tags_index  = array_search( 'Product Tags', $header, true );

        $rows = array();
        $line = 1;

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $line++;

            if ( count( $data ) < count( $header ) ) {
                continue;
            }

            $id   = trim( $data[ $id_index ] );
            $title = trim( $data[ $title_index ] );
            $tags  = trim( $data[ $tags_index ] );

            if ( empty( $id ) ) {
                continue;
            }

            $rows[] = array(
                'line'  => $line,
                'id'    => intval( $id ),
                'title' => sanitize_text_field( $title ),
                'tags'  => $tags,
            );
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Importa las etiquetas a los productos de WooCommerce.
     *
     * @param array $rows Filas parseadas del CSV.
     * @return array Resultado de la importación.
     */
    private function import_tags( $rows ) {
        $results  = array();
        $success  = 0;
        $errors   = 0;

        foreach ( $rows as $row ) {
            $product = wc_get_product( $row['id'] );

            if ( ! $product ) {
                $results[] = array(
                    'id'     => $row['id'],
                    'title'  => $row['title'],
                    'tags'   => $row['tags'],
                    'status' => 'error',
                    'message' => 'Producto no encontrado.',
                );
                $errors++;
                continue;
            }

            if ( empty( $row['tags'] ) ) {
                $results[] = array(
                    'id'     => $row['id'],
                    'title'  => $product->get_name(),
                    'tags'   => '',
                    'status' => 'skipped',
                    'message' => 'Sin etiquetas para importar.',
                );
                continue;
            }

            // Separar etiquetas por el separador configurado y limpiar
            $tags = array_map( 'trim', explode( $this->separator, $row['tags'] ) );
            $tags = array_filter( $tags );
            $tags = array_map( 'sanitize_text_field', $tags );

            if ( empty( $tags ) ) {
                $results[] = array(
                    'id'     => $row['id'],
                    'title'  => $product->get_name(),
                    'tags'   => $row['tags'],
                    'status' => 'skipped',
                    'message' => 'Sin etiquetas válidas.',
                );
                continue;
            }

            $result = wp_set_object_terms( $row['id'], $tags, 'product_tag', true );

            if ( is_wp_error( $result ) ) {
                $results[] = array(
                    'id'     => $row['id'],
                    'title'  => $product->get_name(),
                    'tags'   => implode( ', ', $tags ),
                    'status' => 'error',
                    'message' => $result->get_error_message(),
                );
                $errors++;
            } else {
                $results[] = array(
                    'id'     => $row['id'],
                    'title'  => $product->get_name(),
                    'tags'   => implode( ', ', $tags ),
                    'status' => 'success',
                    'message' => count( $tags ) . ' etiqueta(s) importada(s).',
                );
                $success++;
            }
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
