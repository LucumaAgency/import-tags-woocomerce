<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITWC_Variation_Order_Sync {

    /**
     * Log de debug.
     */
    private $debug_log = array();

    /**
     * Procesa la sincronización del orden.
     *
     * @return array Resultado.
     */
    public function process() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $this->error( 'No tienes permisos para realizar esta acción.' );
        }

        if ( ! isset( $_POST['itwc_nonce_sync_order'] ) || ! wp_verify_nonce( $_POST['itwc_nonce_sync_order'], 'itwc_sync_variation_order' ) ) {
            return $this->error( 'Error de seguridad. Recarga la página e intenta de nuevo.' );
        }

        return $this->sync_order();
    }

    /**
     * Sincroniza el orden de las tallas en todos los productos variables.
     *
     * @return array
     */
    private function sync_order() {
        global $wpdb;

        $this->debug_log[] = '=== SINCRONIZAR ORDEN DE VARIACIONES ===';

        // 1. Obtener el orden actual de los términos de pa_tallas según la taxonomía
        $terms = get_terms( array(
            'taxonomy'   => 'pa_tallas',
            'orderby'    => 'menu_order',
            'order'      => 'ASC',
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return $this->error( 'No se encontraron términos en la taxonomía pa_tallas.' );
        }

        // Mapa de slug => posición según el orden de la taxonomía
        $term_order_map = array();
        $this->debug_log[] = 'Orden de tallas en taxonomía:';
        foreach ( $terms as $index => $term ) {
            $term_order_map[ $term->slug ] = $index;
            $this->debug_log[] = sprintf( '  %d. %s (slug: %s, menu_order: %d)', $index + 1, $term->name, $term->slug, $term->term_order ?? 0 );
        }

        // Mapa de term_id => slug para reordenar las opciones del atributo
        $term_id_to_slug = array();
        $slug_to_term_id = array();
        foreach ( $terms as $term ) {
            $term_id_to_slug[ $term->term_id ] = $term->slug;
            $slug_to_term_id[ $term->slug ]    = $term->term_id;
        }

        // 2. Obtener todos los productos variables
        $variable_ids = wc_get_products( array(
            'type'   => 'variable',
            'status' => array( 'publish', 'draft', 'private' ),
            'limit'  => -1,
            'return' => 'ids',
        ) );

        $this->debug_log[] = 'Total productos variables: ' . count( $variable_ids );

        $results = array();
        $updated = 0;
        $skipped = 0;

        foreach ( $variable_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $attributes = $product->get_attributes();

            if ( ! isset( $attributes['pa_tallas'] ) ) {
                $skipped++;
                continue;
            }

            $tallas_attr    = $attributes['pa_tallas'];
            $current_options = $tallas_attr->get_options(); // Array de term_ids

            // Convertir a slugs para comparar orden
            $current_slugs = array();
            foreach ( $current_options as $tid ) {
                if ( isset( $term_id_to_slug[ $tid ] ) ) {
                    $current_slugs[] = $term_id_to_slug[ $tid ];
                }
            }

            // Reordenar según el orden de la taxonomía
            usort( $current_slugs, function ( $a, $b ) use ( $term_order_map ) {
                $pos_a = isset( $term_order_map[ $a ] ) ? $term_order_map[ $a ] : 999;
                $pos_b = isset( $term_order_map[ $b ] ) ? $term_order_map[ $b ] : 999;
                return $pos_a - $pos_b;
            } );

            // Convertir slugs reordenados a term_ids
            $sorted_ids = array();
            foreach ( $current_slugs as $slug ) {
                if ( isset( $slug_to_term_id[ $slug ] ) ) {
                    $sorted_ids[] = $slug_to_term_id[ $slug ];
                }
            }

            $changed = ( $current_options !== $sorted_ids );

            $this->debug_log[] = sprintf(
                'ID=%d "%s" | Antes: %s | Después: %s | %s',
                $product_id,
                $product->get_name(),
                implode( ', ', $current_slugs ),
                implode( ', ', array_map( function ( $id ) use ( $term_id_to_slug ) {
                    return $term_id_to_slug[ $id ] ?? $id;
                }, $sorted_ids ) ),
                $changed ? 'ACTUALIZADO' : 'sin cambios'
            );

            // Actualizar el atributo con el orden correcto
            $tallas_attr->set_options( $sorted_ids );
            $attributes['pa_tallas'] = $tallas_attr;
            $product->set_attributes( $attributes );
            $product->save();

            // 3. Reordenar las variaciones en la BD según el orden de tallas
            $this->reorder_variations( $product_id, $term_order_map );

            // 4. Sincronizar el producto variable
            WC_Product_Variable::sync( $product_id );

            // 5. Limpiar transients de este producto
            wc_delete_product_transients( $product_id );

            $updated++;
            $results[] = array(
                'id'      => $product_id,
                'title'   => $product->get_name(),
                'tallas'  => implode( ', ', $current_slugs ),
                'status'  => $changed ? 'success' : 'synced',
                'message' => $changed ? 'Orden actualizado y variaciones sincronizadas.' : 'Orden ya correcto, cache limpiado.',
            );
        }

        // 6. Limpiar transients globales de WooCommerce
        wc_delete_product_transients();
        delete_transient( 'wc_attribute_taxonomies' );

        return array(
            'success'   => true,
            'message'   => sprintf(
                'Sincronización completada: %d producto(s) procesado(s), %d omitido(s) (sin pa_tallas).',
                $updated,
                $skipped
            ),
            'results'   => $results,
            'summary'   => array(
                'total'   => $updated,
                'updated' => $updated,
                'skipped' => $skipped,
            ),
            'debug_log' => $this->debug_log,
        );
    }

    /**
     * Reordena las variaciones de un producto según el orden de tallas.
     * Ajusta el menu_order de cada variación para que coincida con el orden de la taxonomía.
     *
     * @param int   $product_id     ID del producto padre.
     * @param array $term_order_map Mapa de slug => posición.
     */
    private function reorder_variations( $product_id, $term_order_map ) {
        global $wpdb;

        // Obtener todas las variaciones con su talla
        $variations = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS talla_slug
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_parent = %d
             AND p.post_type = 'product_variation'
             AND pm.meta_key = 'attribute_pa_tallas'
             ORDER BY p.menu_order ASC",
            $product_id
        ) );

        if ( empty( $variations ) ) {
            return;
        }

        // Ordenar por la posición de la talla en la taxonomía
        usort( $variations, function ( $a, $b ) use ( $term_order_map ) {
            $pos_a = isset( $term_order_map[ $a->talla_slug ] ) ? $term_order_map[ $a->talla_slug ] : 999;
            $pos_b = isset( $term_order_map[ $b->talla_slug ] ) ? $term_order_map[ $b->talla_slug ] : 999;
            return $pos_a - $pos_b;
        } );

        // Actualizar menu_order de cada variación
        foreach ( $variations as $index => $var ) {
            $wpdb->update(
                $wpdb->posts,
                array( 'menu_order' => $index ),
                array( 'ID' => $var->ID ),
                array( '%d' ),
                array( '%d' )
            );
            $this->debug_log[] = sprintf(
                '  Variación ID=%d talla="%s" => menu_order=%d',
                $var->ID,
                $var->talla_slug,
                $index
            );
        }
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
