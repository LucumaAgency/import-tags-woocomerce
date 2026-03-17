<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITWC_Simple_To_Variable_Converter {

    /**
     * Log de debug.
     */
    private $debug_log = array();

    /**
     * Stock por defecto para las variaciones.
     */
    private $default_stock;

    /**
     * @param int $default_stock Stock por defecto para cada variación.
     */
    public function __construct( $default_stock = 10 ) {
        $this->default_stock = intval( $default_stock );
    }

    /**
     * Procesa la conversión.
     *
     * @return array Resultado con 'success', 'message', y 'results'.
     */
    public function process() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $this->error( 'No tienes permisos para realizar esta acción.' );
        }

        if ( ! isset( $_POST['itwc_nonce_convert'] ) || ! wp_verify_nonce( $_POST['itwc_nonce_convert'], 'itwc_convert_to_variable' ) ) {
            return $this->error( 'Error de seguridad. Recarga la página e intenta de nuevo.' );
        }

        return $this->convert_simple_to_variable();
    }

    /**
     * Convierte productos simples con pa_tallas a variables y crea variaciones.
     *
     * @return array
     */
    private function convert_simple_to_variable() {
        $this->debug_log[] = '=== CONVERTIR SIMPLES A VARIABLES ===';
        $this->debug_log[] = 'Stock por defecto: ' . $this->default_stock;

        $args = array(
            'type'   => 'simple',
            'status' => array( 'publish', 'draft', 'private' ),
            'limit'  => -1,
            'return' => 'ids',
        );
        $simple_ids = wc_get_products( $args );

        $this->debug_log[] = 'Total productos simples encontrados: ' . count( $simple_ids );

        $results = array();
        $success = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ( $simple_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $attributes = $product->get_attributes();

            // Verificar si tiene el atributo pa_tallas
            $tallas_attr = null;
            foreach ( $attributes as $attr_key => $attr ) {
                if ( 'pa_tallas' === $attr_key ) {
                    $tallas_attr = $attr;
                    break;
                }
            }

            if ( ! $tallas_attr ) {
                $this->debug_log[] = sprintf( 'SKIP ID=%d "%s" - no tiene atributo pa_tallas', $product_id, $product->get_name() );
                $skipped++;
                continue;
            }

            // Obtener los términos de talla asignados
            $talla_term_ids = $tallas_attr->get_options();
            if ( empty( $talla_term_ids ) ) {
                $this->debug_log[] = sprintf( 'SKIP ID=%d "%s" - atributo pa_tallas sin valores', $product_id, $product->get_name() );
                $skipped++;
                continue;
            }

            $talla_terms = array();
            foreach ( $talla_term_ids as $term_id ) {
                $term = get_term( $term_id, 'pa_tallas' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $talla_terms[] = $term;
                }
            }

            if ( empty( $talla_terms ) ) {
                $this->debug_log[] = sprintf( 'SKIP ID=%d "%s" - términos de talla no válidos', $product_id, $product->get_name() );
                $skipped++;
                continue;
            }

            $this->debug_log[] = sprintf(
                'CONVIRTIENDO ID=%d "%s" | Precio=%s | Tallas=%s',
                $product_id,
                $product->get_name(),
                $product->get_regular_price(),
                implode( ', ', wp_list_pluck( $talla_terms, 'name' ) )
            );

            $original_price = $product->get_regular_price();
            $sale_price     = $product->get_sale_price();

            // 1. Cambiar el tipo de producto a variable
            $classname = WC_Product_Factory::get_classname_from_product_type( 'variable' );
            $variable  = new $classname( $product_id );

            // 2. Marcar el atributo pa_tallas como usado para variaciones
            $updated_attrs = array();
            foreach ( $attributes as $attr_key => $attr ) {
                if ( 'pa_tallas' === $attr_key ) {
                    $attr->set_variation( true );
                }
                $updated_attrs[ $attr_key ] = $attr;
            }
            $variable->set_attributes( $updated_attrs );
            $variable->save();

            // Forzar el tipo en la BD por si WooCommerce no lo cambió
            wp_set_object_terms( $product_id, 'variable', 'product_type' );

            // 3. Crear una variación por cada talla
            $variations_created = 0;
            foreach ( $talla_terms as $term ) {
                // Verificar si ya existe una variación con esta talla
                $existing = $this->variation_exists( $product_id, $term->slug );
                if ( $existing ) {
                    $this->debug_log[] = sprintf( '  Variación ya existe para talla "%s" (ID=%d)', $term->name, $existing );
                    continue;
                }

                $variation = new WC_Product_Variation();
                $variation->set_parent_id( $product_id );
                $variation->set_attributes( array( 'pa_tallas' => $term->slug ) );

                // Heredar precio del producto simple
                if ( $original_price ) {
                    $variation->set_regular_price( $original_price );
                }
                if ( $sale_price ) {
                    $variation->set_sale_price( $sale_price );
                }

                // Stock = default_stock
                $variation->set_manage_stock( true );
                $variation->set_stock_quantity( $this->default_stock );
                $variation->set_stock_status( 'instock' );

                $variation->set_status( 'publish' );
                $variation->save();

                $variations_created++;
                $this->debug_log[] = sprintf(
                    '  Variación creada: ID=%d | Talla="%s" | Precio=%s | Stock=%d',
                    $variation->get_id(),
                    $term->name,
                    $original_price,
                    $this->default_stock
                );
            }

            // 4. Limpiar precio del producto padre (los variables no tienen precio propio)
            delete_post_meta( $product_id, '_price' );
            delete_post_meta( $product_id, '_regular_price' );
            delete_post_meta( $product_id, '_sale_price' );

            // 5. Sincronizar el producto variable
            WC_Product_Variable::sync( $product_id );

            $results[] = array(
                'id'      => $product_id,
                'title'   => $product->get_name(),
                'price'   => $original_price,
                'tallas'  => implode( ', ', wp_list_pluck( $talla_terms, 'name' ) ),
                'created' => $variations_created,
                'status'  => 'success',
                'message' => sprintf(
                    'Convertido a variable. %d variación(es) creada(s). Precio: %s. Stock: %d c/u.',
                    $variations_created,
                    $original_price ?: '(sin precio)',
                    $this->default_stock
                ),
            );
            $success++;
        }

        return array(
            'success'   => true,
            'message'   => sprintf(
                'Conversión completada: %d convertido(s), %d omitido(s) (sin tallas), %d error(es).',
                $success,
                $skipped,
                $errors
            ),
            'results'   => $results,
            'summary'   => array(
                'total'     => $success + $errors,
                'success'   => $success,
                'skipped'   => $skipped,
                'errors'    => $errors,
            ),
            'debug_log' => $this->debug_log,
        );
    }

    /**
     * Verifica si ya existe una variación para un producto con una talla específica.
     *
     * @param int    $parent_id ID del producto padre.
     * @param string $talla_slug Slug de la talla.
     * @return int|false ID de la variación existente o false.
     */
    private function variation_exists( $parent_id, $talla_slug ) {
        global $wpdb;

        $variation_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_parent = %d
             AND p.post_type = 'product_variation'
             AND pm.meta_key = 'attribute_pa_tallas'
             AND pm.meta_value = %s
             LIMIT 1",
            $parent_id,
            $talla_slug
        ) );

        return $variation_id ? intval( $variation_id ) : false;
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
