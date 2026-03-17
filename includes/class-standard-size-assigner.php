<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITWC_Standard_Size_Assigner {

    /**
     * Valor de la talla estándar a asignar.
     */
    private $size_value;

    /**
     * Log de debug.
     */
    private $debug_log = array();

    /**
     * @param string $size_value Valor de la talla estándar (ej: "Talla Única").
     */
    public function __construct( $size_value = 'Talla Única' ) {
        $this->size_value = sanitize_text_field( $size_value );
    }

    /**
     * Procesa la asignación de talla estándar.
     *
     * @return array Resultado con 'success', 'message', y 'results'.
     */
    public function process() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $this->error( 'No tienes permisos para realizar esta acción.' );
        }

        if ( ! isset( $_POST['itwc_nonce_size'] ) || ! wp_verify_nonce( $_POST['itwc_nonce_size'], 'itwc_assign_standard_size' ) ) {
            return $this->error( 'Error de seguridad. Recarga la página e intenta de nuevo.' );
        }

        if ( empty( $this->size_value ) ) {
            return $this->error( 'Debes indicar un valor para la talla estándar.' );
        }

        return $this->assign_standard_size();
    }

    /**
     * Busca productos sin el atributo pa_tallas y les asigna la talla estándar.
     *
     * @return array
     */
    private function assign_standard_size() {
        global $wpdb;

        $this->debug_log[] = '=== ASIGNAR TALLA ESTÁNDAR ===';
        $this->debug_log[] = 'Valor de talla: "' . $this->size_value . '"';

        // Asegurar que el término de talla exista en la taxonomía pa_tallas
        $term = term_exists( $this->size_value, 'pa_tallas' );
        if ( ! $term ) {
            $term = wp_insert_term( $this->size_value, 'pa_tallas' );
            if ( is_wp_error( $term ) ) {
                return $this->error( 'Error al crear el término de talla: ' . $term->get_error_message() );
            }
            $this->debug_log[] = 'Término creado: ' . wp_json_encode( $term );
        } else {
            $this->debug_log[] = 'Término ya existía: ' . wp_json_encode( $term );
        }

        // Obtener todos los productos padre (no variaciones) de tipo simple o variable
        $all_products = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = 'product'
             AND post_status IN ('publish', 'draft', 'private')
             AND post_parent = 0
             ORDER BY post_title ASC"
        );

        $this->debug_log[] = 'Total productos encontrados: ' . count( $all_products );

        $results = array();
        $success = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ( $all_products as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) {
                continue;
            }

            // Verificar si ya tiene el atributo pa_tallas
            $attributes = $product->get_attributes();
            $has_tallas = false;

            foreach ( $attributes as $attr_key => $attr ) {
                if ( 'pa_tallas' === $attr_key || ( is_object( $attr ) && method_exists( $attr, 'get_name' ) && 'pa_tallas' === $attr->get_name() ) ) {
                    $has_tallas = true;
                    break;
                }
            }

            if ( $has_tallas ) {
                $this->debug_log[] = sprintf( 'SKIP ID=%d "%s" - ya tiene tallas', $post->ID, $post->post_title );
                $skipped++;
                continue;
            }

            // Asignar la talla estándar como atributo
            $this->debug_log[] = sprintf( 'ASIGNANDO ID=%d "%s"', $post->ID, $post->post_title );

            $term_slug = sanitize_title( $this->size_value );
            $term_info = get_term_by( 'slug', $term_slug, 'pa_tallas' );
            if ( ! $term_info ) {
                $term_info = get_term_by( 'name', $this->size_value, 'pa_tallas' );
            }

            if ( ! $term_info ) {
                $this->debug_log[] = sprintf( '  ERROR: no se encontró el término "%s"', $this->size_value );
                $results[] = array(
                    'id'      => $post->ID,
                    'title'   => $post->post_title,
                    'status'  => 'error',
                    'message' => 'Término de talla no encontrado.',
                );
                $errors++;
                continue;
            }

            // Asignar el término al producto
            wp_set_object_terms( $post->ID, array( $term_info->term_id ), 'pa_tallas', false );

            // Crear o actualizar el atributo en el producto
            $new_attr = new WC_Product_Attribute();
            $new_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_tallas' ) );
            $new_attr->set_name( 'pa_tallas' );
            $new_attr->set_options( array( $term_info->term_id ) );
            $new_attr->set_visible( true );
            $new_attr->set_variation( false );

            $current_attributes = $product->get_attributes();
            $current_attributes['pa_tallas'] = $new_attr;
            $product->set_attributes( $current_attributes );
            $product->save();

            $this->debug_log[] = sprintf( '  OK: talla "%s" asignada', $this->size_value );

            $results[] = array(
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'status'  => 'success',
                'message' => 'Talla "' . $this->size_value . '" asignada.',
            );
            $success++;
        }

        return array(
            'success'   => true,
            'message'   => sprintf(
                'Asignación completada: %d actualizado(s), %d ya tenían talla, %d error(es).',
                $success,
                $skipped,
                $errors
            ),
            'results'   => $results,
            'summary'   => array(
                'total'   => $success + $errors,
                'success' => $success,
                'skipped' => $skipped,
                'errors'  => $errors,
            ),
            'debug_log' => $this->debug_log,
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
