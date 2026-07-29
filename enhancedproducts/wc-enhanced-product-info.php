<?php
/**
 * Plugin Name: WooCommerce Enhanced Product Info
 * Plugin URI: https://shinyprints.com
 * Description: Enhanced product information display with free shipping badges, stock status, custom dimensions, specifications, downloads, and warranty information.
 * Version: 1.3.0
 * Author: ShinyPrints
 * Author URI: https://shinyprints.com
 * Text Domain: wc-enhanced-product-info
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.5
 *
 * @package WC_Enhanced_Product_Info
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WCEPI_VERSION', '1.3.0');
define('WCEPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCEPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCEPI_PLUGIN_BASENAME', plugin_basename(__FILE__));

function wcepi_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce Enhanced Product Info requires WooCommerce to be installed and active.', 'wc-enhanced-product-info'); ?></p>
    </div>
    <?php
}

/**
 * Declare compatibility with WooCommerce features (HPOS, cart/checkout blocks)
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Main Plugin Class
 */
class WC_Enhanced_Product_Info {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes() {
        require_once WCEPI_PLUGIN_DIR . 'includes/class-wcepi-admin.php';
        require_once WCEPI_PLUGIN_DIR . 'includes/class-wcepi-meta-boxes.php';
        require_once WCEPI_PLUGIN_DIR . 'includes/class-wcepi-frontend.php';
        require_once WCEPI_PLUGIN_DIR . 'includes/class-wcepi-settings.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Allow SVG uploads for payment icons (admins only, content-checked)
        add_filter('upload_mimes', array($this, 'allow_svg_upload'));
        add_filter('wp_check_filetype_and_ext', array($this, 'fix_svg_mime_type'), 10, 5);
        add_filter('wp_handle_upload_prefilter', array($this, 'check_svg_upload_safety'));

        // Initialize classes
        WCEPI_Admin::get_instance();
        WCEPI_Meta_Boxes::get_instance();
        WCEPI_Frontend::get_instance();
        WCEPI_Settings::get_instance();
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-enhanced-product-info', false, dirname(WCEPI_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Load on single product pages
        if (is_product()) {
            wp_enqueue_style('wcepi-frontend', WCEPI_PLUGIN_URL . 'assets/css/frontend.css', array(), WCEPI_VERSION);
            wp_enqueue_script('wcepi-frontend', WCEPI_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), WCEPI_VERSION, true);
            if (get_option('wcepi_enable_payment_methods', 'no') === 'yes') {
                wp_enqueue_script('wcepi-payment-icons', WCEPI_PLUGIN_URL . 'assets/js/payment-icons-responsive.js', array('jquery'), WCEPI_VERSION, true);
            }

            // Add dynamic table styles inline after the main CSS
            $this->add_table_inline_styles();
        }

        // Load on archive pages if any archive badge option is enabled
        $archive_badges_enabled = get_option('wcepi_archive_show_free_shipping', 'no') === 'yes' ||
                                  get_option('wcepi_archive_show_warranty', 'no') === 'yes' ||
                                  get_option('wcepi_archive_show_ships_in', 'no') === 'yes';

        if ($archive_badges_enabled && (is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy())) {
            wp_enqueue_style('wcepi-frontend', WCEPI_PLUGIN_URL . 'assets/css/frontend.css', array(), WCEPI_VERSION);
        }
    }

    /**
     * Add dynamic table styling as inline CSS
     */
    private function add_table_inline_styles() {
        $table_border_color = get_option('wcepi_table_border_color', '#eeeeee');
        $table_border_width = get_option('wcepi_table_border_width', '1');
        $table_cell_padding = get_option('wcepi_table_cell_padding', '12');

        $border_width_val = $table_border_width !== '' ? intval($table_border_width) : 1;
        $border_color_val = $table_border_color ? esc_attr($table_border_color) : '#eeeeee';
        $padding_val = $table_cell_padding !== '' ? intval($table_cell_padding) : 12;

        $inline_css = "
            /* Table structure */
            html body .wcepi-dimensions-table,
            html body .wcepi-specifications-table,
            html body .wcepi-custom-section-table {
                border-collapse: collapse !important;
                border-spacing: 0 !important;
                width: 100% !important;
            }
            /* Table cell padding and borders - ultra high specificity */
            html body .woocommerce-Tabs-panel .wcepi-dimensions-table th,
            html body .woocommerce-Tabs-panel .wcepi-dimensions-table td,
            html body .woocommerce-Tabs-panel .wcepi-specifications-table th,
            html body .woocommerce-Tabs-panel .wcepi-specifications-table td,
            html body .woocommerce-Tabs-panel .wcepi-custom-section-table th,
            html body .woocommerce-Tabs-panel .wcepi-custom-section-table td,
            html body .wcepi-dimensions-table th,
            html body .wcepi-dimensions-table td,
            html body .wcepi-specifications-table th,
            html body .wcepi-specifications-table td,
            html body .wcepi-custom-section-table th,
            html body .wcepi-custom-section-table td,
            html body table.wcepi-dimensions-table th,
            html body table.wcepi-dimensions-table td,
            html body table.wcepi-specifications-table th,
            html body table.wcepi-specifications-table td,
            html body table.wcepi-custom-section-table th,
            html body table.wcepi-custom-section-table td {
                padding-top: {$padding_val}px !important;
                padding-bottom: {$padding_val}px !important;
                padding-left: 15px !important;
                padding-right: 15px !important;
                border-bottom-width: {$border_width_val}px !important;
                border-bottom-style: solid !important;
                border-bottom-color: {$border_color_val} !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }
            /* Remove bottom border from last row */
            html body .woocommerce-Tabs-panel .wcepi-dimensions-table tr:last-child td,
            html body .woocommerce-Tabs-panel .wcepi-specifications-table tr:last-child td,
            html body .woocommerce-Tabs-panel .wcepi-custom-section-table tr:last-child td,
            html body .wcepi-dimensions-table tr:last-child td,
            html body .wcepi-specifications-table tr:last-child td,
            html body .wcepi-custom-section-table tr:last-child td,
            html body table.wcepi-dimensions-table tr:last-child td,
            html body table.wcepi-specifications-table tr:last-child td,
            html body table.wcepi-custom-section-table tr:last-child td {
                border-bottom: none !important;
            }
        ";

        wp_add_inline_style('wcepi-frontend', $inline_css);
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Product edit pages
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            global $post_type;
            if ('product' === $post_type) {
                wp_enqueue_style('wcepi-admin', WCEPI_PLUGIN_URL . 'assets/css/admin.css', array(), WCEPI_VERSION);
                wp_enqueue_script('jquery-ui-sortable');
                wp_enqueue_script('wcepi-admin', WCEPI_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'jquery-ui-sortable', 'wp-color-picker'), WCEPI_VERSION, true);
                wp_enqueue_media();

                // Templates for the product edit page toolbars
                wp_localize_script('wcepi-admin', 'wcepi_admin', array(
                    'warranty_template_nonce' => wp_create_nonce('wcepi_warranty_templates'),
                    'warranty_templates' => WCEPI_Meta_Boxes::get_warranty_templates(),
                    'content_template_nonce' => wp_create_nonce('wcepi_content_templates'),
                    'content_templates' => array(
                        'shipping' => WCEPI_Meta_Boxes::get_content_templates('shipping'),
                        'returns' => WCEPI_Meta_Boxes::get_content_templates('returns'),
                    ),
                ));
            }
        }

        // Settings page - check both hook name and GET parameter
        $is_settings_page = (isset($_GET['page']) && $_GET['page'] === 'wcepi-settings') ||
                           (strpos($hook, 'wcepi-settings') !== false) ||
                           ($hook === 'woocommerce_page_wcepi-settings');

        if ($is_settings_page) {
            wp_enqueue_style('wcepi-admin', WCEPI_PLUGIN_URL . 'assets/css/admin.css', array(), WCEPI_VERSION);
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('wcepi-admin', WCEPI_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'jquery-ui-sortable', 'wp-color-picker'), WCEPI_VERSION, true);
            wp_enqueue_editor();
            wp_enqueue_media();

            // Localize script with nonce for bulk sync
            wp_localize_script('wcepi-admin', 'wcepi_admin', array(
                'bulk_sync_nonce' => wp_create_nonce('wcepi_bulk_sync_nonce')
            ));
        }
    }

    /**
     * Allow SVG file uploads.
     * SVGs can carry scripts, so only trusted users (unfiltered_html) may upload them,
     * and uploads are content-checked in check_svg_upload_safety().
     */
    public function allow_svg_upload($mimes) {
        if (current_user_can('unfiltered_html')) {
            $mimes['svg'] = 'image/svg+xml';
        }
        return $mimes;
    }

    /**
     * Fix SVG mime type detection (WordPress doesn't always detect it correctly)
     */
    public function fix_svg_mime_type($data, $file, $filename, $mimes, $real_mime = '') {
        // If filetype detection failed for SVG
        if (empty($data['type']) && current_user_can('unfiltered_html')) {
            $filetype = wp_check_filetype($filename, $mimes);
            if ($filetype['ext'] === 'svg') {
                $data['ext'] = 'svg';
                $data['type'] = 'image/svg+xml';
            }
        }
        return $data;
    }

    /**
     * Reject SVG uploads containing scripts, event handlers, or external references.
     */
    public function check_svg_upload_safety($file) {
        if (empty($file['name']) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'svg') {
            return $file;
        }

        if (!current_user_can('unfiltered_html')) {
            $file['error'] = __('Sorry, you are not allowed to upload SVG files.', 'wc-enhanced-product-info');
            return $file;
        }

        $contents = @file_get_contents($file['tmp_name']);
        if ($contents === false) {
            $file['error'] = __('Unable to read the SVG file for a security check.', 'wc-enhanced-product-info');
            return $file;
        }

        // Block script tags, inline event handlers, javascript: URLs, foreignObject, and embedded documents
        $dangerous = '/<script|[\s"\'<]on[a-z]+\s*=|javascript\s*:|<foreignObject|<iframe|<embed|<object|data:text\/html/i';
        if (preg_match($dangerous, $contents)) {
            $file['error'] = __('This SVG contains scripts or embedded content and was blocked for security. Please upload a plain vector image.', 'wc-enhanced-product-info');
        }

        return $file;
    }
}

/**
 * Initialize the plugin
 */
function wcepi_init() {
    // class_exists works for single-site, multisite network activation, and mu-plugins
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wcepi_woocommerce_missing_notice');
        return null;
    }
    return WC_Enhanced_Product_Info::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'wcepi_init');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, 'wcepi_activate');
function wcepi_activate() {
    // Set default options
    $defaults = array(
        'enable_free_shipping_badge' => 'yes',
        'enable_stock_status' => 'yes',
        'enable_custom_dimensions' => 'yes',
        'enable_specifications' => 'yes',
        'enable_downloads' => 'yes',
        'enable_shipping_returns' => 'yes',
        'enable_returns' => 'yes',
        'enable_warranty' => 'yes',
        'enable_faq' => 'yes',
        'enable_custom_sections' => 'yes',
        'enable_payment_methods' => 'no',
        'enable_product_schema' => 'yes',
        'display_mode' => 'tabs',
        'shipping_returns_content' => '',
        'returns_content' => '',
    );
    
    foreach ($defaults as $key => $value) {
        if (get_option('wcepi_' . $key) === false) {
            add_option('wcepi_' . $key, $value);
        }
    }
}

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, 'wcepi_deactivate');
function wcepi_deactivate() {
    // Cleanup if needed
}