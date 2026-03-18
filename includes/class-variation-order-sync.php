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
     * Sincroniza el orden de las tallas eliminando y recreando variaciones
     * en el orden correcto para que los IDs queden secuenciales.
     *
     * @return array
     */
    private function sync_order() {
        global $wpdb;

        $this->debug_log[] = '=== SINCRONIZAR ORDEN DE VARIACIONES (RECREAR IDs) ===';

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

        $term_order_map  = array();
        $term_id_to_slug = array();
        $slug_to_term_id = array();

        $this->debug_log[] = 'Orden de tallas en taxonomía:';
        foreach ( $terms as $index => $term ) {
            $term_order_map[ $term->slug ]       = $index;
            $term_id_to_slug[ $term->term_id ]   = $term->slug;
            $slug_to_term_id[ $term->slug ]      = $term->term_id;
            $this->debug_log[] = sprintf( '  %d. %s (slug: %s)', $index + 1, $term->name, $term->slug );
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
        $errors  = 0;

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

            // Reordenar opciones del atributo
            $tallas_attr     = $attributes['pa_tallas'];
            $current_options = $tallas_attr->get_options();

            $current_slugs = array();
            foreach ( $current_options as $tid ) {
                if ( isset( $term_id_to_slug[ $tid ] ) ) {
                    $current_slugs[] = $term_id_to_slug[ $tid ];
                }
            }

            usort( $current_slugs, function ( $a, $b ) use ( $term_order_map ) {
                $pos_a = isset( $term_order_map[ $a ] ) ? $term_order_map[ $a ] : 999;
                $pos_b = isset( $term_order_map[ $b ] ) ? $term_order_map[ $b ] : 999;
                return $pos_a - $pos_b;
            } );

            $sorted_term_ids = array();
            foreach ( $current_slugs as $slug ) {
                if ( isset( $slug_to_term_id[ $slug ] ) ) {
                    $sorted_term_ids[] = $slug_to_term_id[ $slug ];
                }
            }

            $tallas_attr->set_options( $sorted_term_ids );
            $attributes['pa_tallas'] = $tallas_attr;
            $product->set_attributes( $attributes );
            $product->save();

            // 3. Obtener variaciones actuales con todos sus datos
            $existing_variations = $this->get_variation_data( $product_id );

            if ( empty( $existing_variations ) ) {
                $this->debug_log[] = sprintf( 'ID=%d "%s" - sin variaciones, omitido', $product_id, $product->get_name() );
                $skipped++;
                continue;
            }

            // Ordenar por el orden de la taxonomía
            usort( $existing_variations, function ( $a, $b ) use ( $term_order_map ) {
                $pos_a = isset( $term_order_map[ $a['talla_slug'] ] ) ? $term_order_map[ $a['talla_slug'] ] : 999;
                $pos_b = isset( $term_order_map[ $b['talla_slug'] ] ) ? $term_order_map[ $b['talla_slug'] ] : 999;
                return $pos_a - $pos_b;
            } );

            // Verificar si ya están en orden por ID
            $already_ordered = true;
            for ( $i = 1; $i < count( $existing_variations ); $i++ ) {
                if ( $existing_variations[ $i ]['id'] < $existing_variations[ $i - 1 ]['id'] ) {
                    $already_ordered = false;
                    break;
                }
            }

            if ( $already_ordered ) {
                $this->debug_log[] = sprintf( 'ID=%d "%s" - IDs ya en orden correcto', $product_id, $product->get_name() );

                // Igual limpiamos cache y sincronizamos
                WC_Product_Variable::sync( $product_id );
                wc_delete_product_transients( $product_id );

                $results[] = array(
                    'id'      => $product_id,
                    'title'   => $product->get_name(),
                    'tallas'  => implode( ', ', array_column( $existing_variations, 'talla_slug' ) ),
                    'detail'  => $this->format_variation_ids( $existing_variations ),
                    'status'  => 'synced',
                    'message' => 'IDs ya en orden correcto. Cache limpiado.',
                );
                $updated++;
                continue;
            }

            $this->debug_log[] = sprintf( 'ID=%d "%s" - RECREANDO variaciones', $product_id, $product->get_name() );

            $old_ids_str = implode( ', ', array_map( function ( $v ) {
                return '#' . $v['id'] . ' (' . $v['talla_slug'] . ')';
            }, $existing_variations ) );
            $this->debug_log[] = '  Orden actual: ' . $old_ids_str;

            // 4. Eliminar todas las variaciones existentes
            foreach ( $existing_variations as $var_data ) {
                $this->debug_log[] = sprintf( '  Eliminando variación #%d (talla: %s)', $var_data['id'], $var_data['talla_slug'] );
                $variation_obj = wc_get_product( $var_data['id'] );
                if ( $variation_obj ) {
                    $variation_obj->delete( true );
                }
            }

            // 5. Recrear las variaciones en el orden correcto
            $new_variations_info = array();
            foreach ( $existing_variations as $menu_order => $var_data ) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id( $product_id );
                $variation->set_attributes( array( 'pa_tallas' => $var_data['talla_slug'] ) );
                $variation->set_status( $var_data['status'] );

                if ( '' !== $var_data['regular_price'] ) {
                    $variation->set_regular_price( $var_data['regular_price'] );
                }
                if ( '' !== $var_data['sale_price'] ) {
                    $variation->set_sale_price( $var_data['sale_price'] );
                }

                $variation->set_manage_stock( $var_data['manage_stock'] );
                if ( $var_data['manage_stock'] ) {
                    $variation->set_stock_quantity( $var_data['stock_quantity'] );
                }
                $variation->set_stock_status( $var_data['stock_status'] );

                if ( ! empty( $var_data['sku'] ) ) {
                    $variation->set_sku( $var_data['sku'] );
                }
                if ( ! empty( $var_data['weight'] ) ) {
                    $variation->set_weight( $var_data['weight'] );
                }
                if ( ! empty( $var_data['length'] ) ) {
                    $variation->set_length( $var_data['length'] );
                }
                if ( ! empty( $var_data['width'] ) ) {
                    $variation->set_width( $var_data['width'] );
                }
                if ( ! empty( $var_data['height'] ) ) {
                    $variation->set_height( $var_data['height'] );
                }
                if ( $var_data['image_id'] ) {
                    $variation->set_image_id( $var_data['image_id'] );
                }
                if ( ! empty( $var_data['description'] ) ) {
                    $variation->set_description( $var_data['description'] );
                }

                $variation->set_menu_order( $menu_order );
                $variation->save();

                // Restaurar metadatos adicionales (campos ACF, etc.)
                foreach ( $var_data['extra_meta'] as $meta_key => $meta_value ) {
                    update_post_meta( $variation->get_id(), $meta_key, $meta_value );
                }

                $new_variations_info[] = array(
                    'id'         => $variation->get_id(),
                    'talla_slug' => $var_data['talla_slug'],
                );

                $this->debug_log[] = sprintf(
                    '  Creada variación #%d (talla: %s) | precio: %s | stock: %s | menu_order: %d',
                    $variation->get_id(),
                    $var_data['talla_slug'],
                    $var_data['regular_price'] ?: '—',
                    $var_data['manage_stock'] ? $var_data['stock_quantity'] : 'no gestionado',
                    $menu_order
                );
            }

            // 6. Sincronizar producto variable y limpiar cache
            WC_Product_Variable::sync( $product_id );
            wc_delete_product_transients( $product_id );

            $new_ids_str = implode( ', ', array_map( function ( $v ) {
                return '#' . $v['id'] . ' (' . $v['talla_slug'] . ')';
            }, $new_variations_info ) );

            $results[] = array(
                'id'      => $product_id,
                'title'   => $product->get_name(),
                'tallas'  => implode( ', ', array_column( $new_variations_info, 'talla_slug' ) ),
                'detail'  => $new_ids_str,
                'status'  => 'success',
                'message' => 'Variaciones recreadas en orden. Antes: ' . $old_ids_str,
            );
            $updated++;
        }

        // 7. Limpiar transients globales
        wc_delete_product_transients();
        delete_transient( 'wc_attribute_taxonomies' );

        return array(
            'success'   => true,
            'message'   => sprintf(
                'Sincronización completada: %d producto(s) procesado(s), %d omitido(s), %d error(es).',
                $updated,
                $skipped,
                $errors
            ),
            'results'   => $results,
            'summary'   => array(
                'total'   => $updated,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors'  => $errors,
            ),
            'debug_log' => $this->debug_log,
        );
    }

    /**
     * Extrae todos los datos de las variaciones de un producto para poder recrearlas.
     *
     * @param int $product_id ID del producto padre.
     * @return array Array de datos de cada variación.
     */
    private function get_variation_data( $product_id ) {
        global $wpdb;

        $variation_posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS talla_slug
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_parent = %d
             AND p.post_type = 'product_variation'
             AND pm.meta_key = 'attribute_pa_tallas'",
            $product_id
        ) );

        if ( empty( $variation_posts ) ) {
            return array();
        }

        $data = array();

        // Claves de meta que WooCommerce maneja internamente (no copiar como extra_meta)
        $wc_internal_keys = array(
            '_price', '_regular_price', '_sale_price', '_sale_price_dates_from',
            '_sale_price_dates_to', '_sku', '_manage_stock', '_stock',
            '_stock_status', '_backorders', '_low_stock_amount', '_weight',
            '_length', '_width', '_height', '_thumbnail_id', '_virtual',
            '_downloadable', '_download_limit', '_download_expiry',
            '_variation_description', 'attribute_pa_tallas',
            '_product_version', '_wp_old_date',
        );

        foreach ( $variation_posts as $vp ) {
            $variation = wc_get_product( $vp->ID );
            if ( ! $variation ) {
                continue;
            }

            // Recoger metadatos extra (campos ACF, etc.)
            $all_meta   = get_post_meta( $vp->ID );
            $extra_meta = array();
            foreach ( $all_meta as $key => $values ) {
                if ( ! in_array( $key, $wc_internal_keys, true ) && strpos( $key, '_wp_' ) !== 0 && strpos( $key, '_edit_' ) !== 0 ) {
                    $extra_meta[ $key ] = $values[0];
                }
            }

            $data[] = array(
                'id'              => $vp->ID,
                'talla_slug'      => $vp->talla_slug,
                'regular_price'   => $variation->get_regular_price( 'edit' ),
                'sale_price'      => $variation->get_sale_price( 'edit' ),
                'manage_stock'    => $variation->get_manage_stock(),
                'stock_quantity'  => $variation->get_stock_quantity(),
                'stock_status'    => $variation->get_stock_status(),
                'sku'             => $variation->get_sku( 'edit' ),
                'weight'          => $variation->get_weight( 'edit' ),
                'length'          => $variation->get_length( 'edit' ),
                'width'           => $variation->get_width( 'edit' ),
                'height'          => $variation->get_height( 'edit' ),
                'image_id'        => $variation->get_image_id(),
                'description'     => $variation->get_description( 'edit' ),
                'status'          => $variation->get_status(),
                'extra_meta'      => $extra_meta,
            );
        }

        return $data;
    }

    /**
     * Formatea los IDs de las variaciones para mostrar en la tabla.
     */
    private function format_variation_ids( $variations ) {
        return implode( ', ', array_map( function ( $v ) {
            return '#' . $v['id'] . ' (' . $v['talla_slug'] . ')';
        }, $variations ) );
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
