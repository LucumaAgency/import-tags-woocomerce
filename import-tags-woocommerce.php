<?php
/**
 * Plugin Name: Import Tags WooCommerce
 * Plugin URI: https://github.com/LucumaAgency/import-tags-woocomerce
 * Description: Importa etiquetas de productos WooCommerce desde un archivo CSV.
 * Version: 1.0.0
 * Author: Lucuma Agency
 * Author URI: https://lucuma.agency
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: import-tags-woocommerce
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ITWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ITWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ITWC_VERSION', '1.0.0' );

/**
 * Verifica que WooCommerce esté activo antes de inicializar el plugin.
 */
function itwc_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'itwc_woocommerce_missing_notice' );
        return;
    }
    itwc_init();
}
add_action( 'plugins_loaded', 'itwc_check_woocommerce' );

/**
 * Muestra aviso si WooCommerce no está activo.
 */
function itwc_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>Import Tags WooCommerce</strong> requiere que WooCommerce esté instalado y activo.</p>
    </div>
    <?php
}

/**
 * Inicializa el plugin.
 */
function itwc_init() {
    require_once ITWC_PLUGIN_DIR . 'includes/class-csv-importer.php';

    add_action( 'admin_menu', 'itwc_add_admin_menu' );
    add_action( 'admin_enqueue_scripts', 'itwc_enqueue_admin_styles' );
}

/**
 * Registra la página del plugin bajo el menú de WooCommerce.
 */
function itwc_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'Importar Etiquetas',
        'Importar Etiquetas',
        'manage_woocommerce',
        'itwc-import-tags',
        'itwc_render_admin_page'
    );
}

/**
 * Renderiza la página de administración.
 */
function itwc_render_admin_page() {
    require_once ITWC_PLUGIN_DIR . 'admin/admin-page.php';
}

/**
 * Carga los estilos CSS en la página del plugin.
 */
function itwc_enqueue_admin_styles( $hook ) {
    if ( 'woocommerce_page_itwc-import-tags' !== $hook ) {
        return;
    }
    wp_enqueue_style(
        'itwc-admin-styles',
        ITWC_PLUGIN_URL . 'admin/admin-styles.css',
        array(),
        ITWC_VERSION
    );
}
