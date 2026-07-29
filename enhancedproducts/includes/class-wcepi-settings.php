<?php
/**
 * Settings Class
 *
 * @package WC_Enhanced_Product_Info
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCEPI_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wcepi_bulk_sync_attributes', array($this, 'ajax_bulk_sync_attributes'));
    }
    
    /**
     * Add settings page to WooCommerce menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Enhanced Product Info', 'wc-enhanced-product-info'),
            __('Enhanced Product Info', 'wc-enhanced-product-info'),
            'manage_woocommerce',
            'wcepi-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // General settings
        register_setting('wcepi_settings', 'wcepi_enable_free_shipping_badge');
        register_setting('wcepi_settings', 'wcepi_enable_stock_status');
        register_setting('wcepi_settings', 'wcepi_enable_custom_dimensions');
        register_setting('wcepi_settings', 'wcepi_sync_dimensions_to_attributes');
        register_setting('wcepi_settings', 'wcepi_enable_specifications');
        register_setting('wcepi_settings', 'wcepi_sync_specs_to_attributes');
        register_setting('wcepi_settings', 'wcepi_enable_downloads');
        register_setting('wcepi_settings', 'wcepi_enable_shipping_returns');
        register_setting('wcepi_settings', 'wcepi_enable_returns');
        register_setting('wcepi_settings', 'wcepi_enable_warranty');
        register_setting('wcepi_settings', 'wcepi_enable_faq');
        register_setting('wcepi_settings', 'wcepi_enable_custom_sections');
        register_setting('wcepi_settings', 'wcepi_enable_payment_methods');
        register_setting('wcepi_settings', 'wcepi_payment_methods');
        register_setting('wcepi_settings', 'wcepi_custom_payment_methods');
        register_setting('wcepi_settings', 'wcepi_display_mode');
        register_setting('wcepi_settings', 'wcepi_accordion_default_open');
        register_setting('wcepi_settings', 'wcepi_shipping_returns_content');
        register_setting('wcepi_settings', 'wcepi_returns_content');
        register_setting('wcepi_settings', 'wcepi_free_shipping_text');
        register_setting('wcepi_settings', 'wcepi_in_stock_text');
        register_setting('wcepi_settings', 'wcepi_out_of_stock_text');
        register_setting('wcepi_settings', 'wcepi_ships_in_text');

        // Badge style settings - Free Shipping
        register_setting('wcepi_settings', 'wcepi_free_shipping_bg_color');
        register_setting('wcepi_settings', 'wcepi_free_shipping_text_color');

        // Badge style settings - Warranty
        register_setting('wcepi_settings', 'wcepi_warranty_badge_bg_color');
        register_setting('wcepi_settings', 'wcepi_warranty_badge_text_color');
        register_setting('wcepi_settings', 'wcepi_warranty_badge_border_color');
        register_setting('wcepi_settings', 'wcepi_warranty_badge_icon_color');
        register_setting('wcepi_settings', 'wcepi_warranty_badge_icon_type');
        register_setting('wcepi_settings', 'wcepi_warranty_badge_font_size');
        register_setting('wcepi_settings', 'wcepi_warranty_badge_font_weight');

        // Badge style settings - Stock Status Icon
        register_setting('wcepi_settings', 'wcepi_stock_badge_in_stock_icon_type');
        register_setting('wcepi_settings', 'wcepi_stock_badge_out_of_stock_icon_type');

        // Badge layout settings
        register_setting('wcepi_settings', 'wcepi_badges_inline_layout');
        register_setting('wcepi_settings', 'wcepi_badges_shape');

        // Badge style settings - Stock Status
        register_setting('wcepi_settings', 'wcepi_stock_badge_in_stock_bg_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_in_stock_text_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_in_stock_icon_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_in_stock_border_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_out_of_stock_bg_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_out_of_stock_text_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_out_of_stock_icon_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_out_of_stock_border_color');
        register_setting('wcepi_settings', 'wcepi_stock_badge_icon_type');
        register_setting('wcepi_settings', 'wcepi_stock_badge_font_size');
        register_setting('wcepi_settings', 'wcepi_stock_badge_font_weight');

        // Ships In badge styling
        register_setting('wcepi_settings', 'wcepi_ships_in_bg_color');
        register_setting('wcepi_settings', 'wcepi_ships_in_text_color');
        register_setting('wcepi_settings', 'wcepi_ships_in_icon_color');

        // Free shipping border
        register_setting('wcepi_settings', 'wcepi_free_shipping_border_color');

        // Content styling settings
        register_setting('wcepi_settings', 'wcepi_content_font_size');
        register_setting('wcepi_settings', 'wcepi_content_padding_top');
        register_setting('wcepi_settings', 'wcepi_content_padding_bottom');
        register_setting('wcepi_settings', 'wcepi_content_padding_left');
        
        // Payment icon size settings
        register_setting('wcepi_settings', 'wcepi_payment_icon_size_desktop');
        register_setting('wcepi_settings', 'wcepi_payment_icon_size_tablet');
        register_setting('wcepi_settings', 'wcepi_payment_icon_size_mobile');
        register_setting('wcepi_settings', 'wcepi_content_padding_right');
        
        // Table styling settings
        register_setting('wcepi_settings', 'wcepi_table_border_color');
        register_setting('wcepi_settings', 'wcepi_table_border_width');
        register_setting('wcepi_settings', 'wcepi_table_cell_padding');
        register_setting('wcepi_settings', 'wcepi_table_margin_bottom');

        // Tab/Accordion label settings
        register_setting('wcepi_settings', 'wcepi_label_description');
        register_setting('wcepi_settings', 'wcepi_label_dimensions');
        register_setting('wcepi_settings', 'wcepi_label_specifications');
        register_setting('wcepi_settings', 'wcepi_label_downloads');
        register_setting('wcepi_settings', 'wcepi_label_shipping_returns');
        register_setting('wcepi_settings', 'wcepi_label_returns');
        register_setting('wcepi_settings', 'wcepi_label_warranty');
        register_setting('wcepi_settings', 'wcepi_label_faq');

        // Tab sort order
        register_setting('wcepi_settings', 'wcepi_tab_order');

        // Archive/Listing page settings - visibility
        register_setting('wcepi_settings', 'wcepi_archive_show_free_shipping');
        register_setting('wcepi_settings', 'wcepi_archive_show_warranty');
        register_setting('wcepi_settings', 'wcepi_archive_show_ships_in');
        register_setting('wcepi_settings', 'wcepi_archive_show_stock');
        register_setting('wcepi_settings', 'wcepi_archive_show_custom_badges');

        // Archive/Listing page badge styling
        register_setting('wcepi_settings', 'wcepi_archive_badge_shape');
        register_setting('wcepi_settings', 'wcepi_archive_badge_font_size');
        register_setting('wcepi_settings', 'wcepi_archive_badge_padding');
        register_setting('wcepi_settings', 'wcepi_archive_badge_border_radius');

        // Archive - Free Shipping Badge styling
        register_setting('wcepi_settings', 'wcepi_archive_free_shipping_bg_color');
        register_setting('wcepi_settings', 'wcepi_archive_free_shipping_text_color');
        register_setting('wcepi_settings', 'wcepi_archive_free_shipping_border_color');

        // Archive - Warranty Badge styling
        register_setting('wcepi_settings', 'wcepi_archive_warranty_bg_color');
        register_setting('wcepi_settings', 'wcepi_archive_warranty_text_color');
        register_setting('wcepi_settings', 'wcepi_archive_warranty_border_color');
        register_setting('wcepi_settings', 'wcepi_archive_warranty_icon_color');

        // Archive - Stock Badge styling
        register_setting('wcepi_settings', 'wcepi_archive_stock_in_bg_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_in_text_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_in_border_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_in_icon_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_out_bg_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_out_text_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_out_border_color');
        register_setting('wcepi_settings', 'wcepi_archive_stock_out_icon_color');

        // Archive - Ships In Badge styling
        register_setting('wcepi_settings', 'wcepi_archive_ships_in_bg_color');
        register_setting('wcepi_settings', 'wcepi_archive_ships_in_text_color');
        register_setting('wcepi_settings', 'wcepi_archive_ships_in_border_color');
        register_setting('wcepi_settings', 'wcepi_archive_ships_in_icon_color');

        // Badge order, positions, and custom badges
        register_setting('wcepi_settings', 'wcepi_badges_order');
        register_setting('wcepi_settings', 'wcepi_badges_positions');
        register_setting('wcepi_settings', 'wcepi_custom_badges');

        // Schema/SEO settings
        register_setting('wcepi_settings', 'wcepi_enable_product_schema');
        register_setting('wcepi_settings', 'wcepi_schema_brand');
        register_setting('wcepi_settings', 'wcepi_schema_shipping_country');
        register_setting('wcepi_settings', 'wcepi_schema_shipping_cost');
        register_setting('wcepi_settings', 'wcepi_schema_transit_time_min');
        register_setting('wcepi_settings', 'wcepi_schema_transit_time_max');
        register_setting('wcepi_settings', 'wcepi_schema_return_days');
        register_setting('wcepi_settings', 'wcepi_schema_return_fees');
    }

    /**
     * Get default tab order
     */
    public static function get_default_tab_order() {
        return array(
            'description' => 10,
            'dimensions' => 15,
            'specifications' => 20,
            'downloads' => 25,
            'shipping_returns' => 30,
            'returns' => 32,
            'warranty' => 35,
            'faq' => 40,
            'custom_sections' => 45
        );
    }

    /**
     * Get tab labels for display
     */
    public static function get_tab_labels() {
        return array(
            'description' => __('Description', 'wc-enhanced-product-info'),
            'dimensions' => __('Dimensions', 'wc-enhanced-product-info'),
            'specifications' => __('Specifications', 'wc-enhanced-product-info'),
            'downloads' => __('Downloads/Manuals', 'wc-enhanced-product-info'),
            'shipping_returns' => __('Shipping Policy', 'wc-enhanced-product-info'),
            'returns' => __('Returns Policy', 'wc-enhanced-product-info'),
            'warranty' => __('Warranty', 'wc-enhanced-product-info'),
            'faq' => __('FAQ', 'wc-enhanced-product-info'),
            'custom_sections' => __('Custom Sections', 'wc-enhanced-product-info')
        );
    }

    /**
     * Get saved tab order (global)
     */
    public static function get_tab_order() {
        $saved_order = get_option('wcepi_tab_order', array());
        $default_order = self::get_default_tab_order();

        if (empty($saved_order) || !is_array($saved_order)) {
            return $default_order;
        }

        // Merge with defaults to ensure all tabs are included
        return array_merge($default_order, $saved_order);
    }

    /**
     * Get default badge order
     */
    public static function get_default_badge_order() {
        return array(
            'free_shipping' => 1,
            'warranty' => 2,
            'stock' => 3
        );
    }

    /**
     * Get badge labels for sorting UI
     */
    public static function get_badge_labels() {
        return array(
            'free_shipping' => __('Free Shipping Badge', 'wc-enhanced-product-info'),
            'warranty' => __('Warranty Badge', 'wc-enhanced-product-info'),
            'stock' => __('Stock Status Badge', 'wc-enhanced-product-info')
        );
    }

    /**
     * Get default badge positions (above or below cart)
     */
    public static function get_default_badge_positions() {
        return array(
            'free_shipping' => 'next_to_price',
            'warranty' => 'above',
            'stock' => 'above'
        );
    }

    /**
     * Get saved badge positions
     */
    public static function get_badge_positions() {
        $saved_positions = get_option('wcepi_badges_positions', array());
        $default_positions = self::get_default_badge_positions();

        if (empty($saved_positions) || !is_array($saved_positions)) {
            return $default_positions;
        }

        // Merge with defaults to ensure all badges have positions
        return array_merge($default_positions, $saved_positions);
    }

    /**
     * Get saved badge order
     */
    public static function get_badge_order() {
        $saved_order = get_option('wcepi_badges_order', array());
        $default_order = self::get_default_badge_order();

        if (empty($saved_order) || !is_array($saved_order)) {
            return $default_order;
        }

        // Merge with defaults to ensure all badges are included
        return array_merge($default_order, $saved_order);
    }

    /**
     * Get custom badges
     */
    public static function get_custom_badges() {
        $badges = get_option('wcepi_custom_badges', array());
        return is_array($badges) ? $badges : array();
    }

    /**
     * Get available badge icons
     */
    public static function get_badge_icons() {
        return array(
            // Checkmarks & Verification
            'checkbox-square' => __('Checkbox (Square)', 'wc-enhanced-product-info'),
            'checkbox-circle' => __('Checkmark (Circle)', 'wc-enhanced-product-info'),
            'verified' => __('Verified Badge', 'wc-enhanced-product-info'),
            'check' => __('Simple Check', 'wc-enhanced-product-info'),

            // Security & Trust
            'shield' => __('Shield', 'wc-enhanced-product-info'),
            'shield-check' => __('Shield with Checkmark', 'wc-enhanced-product-info'),
            'lock' => __('Lock', 'wc-enhanced-product-info'),
            'key' => __('Key', 'wc-enhanced-product-info'),

            // Awards & Quality
            'badge' => __('Badge/Ribbon', 'wc-enhanced-product-info'),
            'star' => __('Star', 'wc-enhanced-product-info'),
            'award' => __('Award/Medal', 'wc-enhanced-product-info'),
            'certificate' => __('Certificate', 'wc-enhanced-product-info'),
            'trophy' => __('Trophy', 'wc-enhanced-product-info'),
            'crown' => __('Crown', 'wc-enhanced-product-info'),

            // Satisfaction & Approval
            'thumbs-up' => __('Thumbs Up', 'wc-enhanced-product-info'),
            'heart' => __('Heart', 'wc-enhanced-product-info'),
            'smile' => __('Smile', 'wc-enhanced-product-info'),

            // Shipping & Delivery
            'truck' => __('Delivery Truck', 'wc-enhanced-product-info'),
            'box' => __('Package Box', 'wc-enhanced-product-info'),
            'plane' => __('Airplane', 'wc-enhanced-product-info'),
            'clock' => __('Clock', 'wc-enhanced-product-info'),
            'calendar' => __('Calendar', 'wc-enhanced-product-info'),

            // Money & Payment
            'money' => __('Money/Dollar', 'wc-enhanced-product-info'),
            'credit-card' => __('Credit Card', 'wc-enhanced-product-info'),
            'percent' => __('Percent', 'wc-enhanced-product-info'),
            'tag' => __('Price Tag', 'wc-enhanced-product-info'),

            // Communication & Support
            'headset' => __('Customer Support', 'wc-enhanced-product-info'),
            'phone' => __('Phone', 'wc-enhanced-product-info'),
            'chat' => __('Chat Bubble', 'wc-enhanced-product-info'),
            'email' => __('Email', 'wc-enhanced-product-info'),

            // Arrows & Returns
            'refresh' => __('Refresh/Return', 'wc-enhanced-product-info'),
            'undo' => __('Undo/Returns', 'wc-enhanced-product-info'),

            // Misc
            'gift' => __('Gift', 'wc-enhanced-product-info'),
            'leaf' => __('Leaf/Eco', 'wc-enhanced-product-info'),
            'bolt' => __('Lightning Bolt', 'wc-enhanced-product-info'),
            'fire' => __('Fire/Hot', 'wc-enhanced-product-info'),
            'globe' => __('Globe/Worldwide', 'wc-enhanced-product-info'),
            'info' => __('Info', 'wc-enhanced-product-info'),
            'none' => __('No Icon', 'wc-enhanced-product-info')
        );
    }

    /**
     * Build a hoverable "?" help tip
     *
     * @param string $tip Plain-text explanation shown on hover/focus
     * @return string HTML for the tip
     */
    private function help_tip($tip) {
        return '<span class="wcepi-help-tip" tabindex="0" role="img" aria-label="' . esc_attr($tip) . '" data-tip="' . esc_attr($tip) . '"></span>';
    }

    /**
     * Render a live badge preview row (populated and kept in sync by JS)
     *
     * @param string $stage_id   Element id the preview JS renders into
     * @param array  $data_attrs Extra data-* attributes (e.g. sample text)
     */
    private function badge_preview_row($stage_id, $data_attrs = array()) {
        $attrs = '';
        foreach ($data_attrs as $key => $value) {
            $attrs .= ' data-' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }
        ?>
        <tr class="wcepi-preview-row">
            <th scope="row"><?php _e('Live Preview', 'wc-enhanced-product-info'); ?></th>
            <td>
                <div class="wcepi-badge-preview-stage" id="<?php echo esc_attr($stage_id); ?>"<?php echo $attrs; ?>></div>
                <p class="description"><?php _e('Updates live as you change the settings below. Sizing is approximate — the final look depends on your theme\'s fonts. Save to apply it to your store.', 'wc-enhanced-product-info'); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (isset($_POST['wcepi_save_settings']) && check_admin_referer('wcepi_settings_nonce')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'wc-enhanced-product-info') . '</p></div>';
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

        ?>
        <div class="wrap wcepi-settings-wrap">
            <h1><?php _e('WooCommerce Enhanced Product Info Settings', 'wc-enhanced-product-info'); ?></h1>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper wcepi-nav-tabs">
                <a href="?page=wcepi-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'wc-enhanced-product-info'); ?>
                </a>
                <a href="?page=wcepi-settings&tab=layout" class="nav-tab <?php echo $active_tab === 'layout' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Layout & Position', 'wc-enhanced-product-info'); ?>
                </a>
                <a href="?page=wcepi-settings&tab=styling" class="nav-tab <?php echo $active_tab === 'styling' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Styling', 'wc-enhanced-product-info'); ?>
                </a>
                <a href="?page=wcepi-settings&tab=labels" class="nav-tab <?php echo $active_tab === 'labels' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Labels & Text', 'wc-enhanced-product-info'); ?>
                </a>
                <a href="?page=wcepi-settings&tab=schema" class="nav-tab <?php echo $active_tab === 'schema' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('SEO/Schema', 'wc-enhanced-product-info'); ?>
                </a>
            </nav>

            <form method="post" action="" id="wcepi-settings-form">
                <?php wp_nonce_field('wcepi_settings_nonce'); ?>
                <input type="hidden" name="wcepi_current_tab" value="<?php echo esc_attr($active_tab); ?>">

                <p class="submit" style="margin: 15px 0 0; padding: 0;">
                    <input type="submit" name="wcepi_save_settings" class="button button-primary"
                           value="<?php esc_attr_e('Save Settings', 'wc-enhanced-product-info'); ?>">
                </p>

                <?php if ($active_tab === 'general'): ?>
                <!-- GENERAL TAB -->
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Feature Toggles', 'wc-enhanced-product-info'); ?></h2>
                            </th>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_free_shipping_badge">
                                    <?php _e('Enable Free Shipping Badge', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_free_shipping_badge"
                                       name="wcepi_enable_free_shipping_badge" value="yes"
                                       <?php checked(get_option('wcepi_enable_free_shipping_badge', 'yes'), 'yes'); ?>>
                                <?php echo $this->help_tip(__('This is the master switch. The badge only appears on products where you also tick "Enable free shipping badge" in the Enhanced Product Information box on that product\'s edit page.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Show "Free Shipping" badge next to product price', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_free_shipping_text">
                                    <?php _e('Free Shipping Text', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_free_shipping_text" 
                                       name="wcepi_free_shipping_text" 
                                       value="<?php echo esc_attr(get_option('wcepi_free_shipping_text', 'Free Shipping')); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_stock_status">
                                    <?php _e('Enable Enhanced Stock Status', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_stock_status"
                                       name="wcepi_enable_stock_status" value="yes"
                                       <?php checked(get_option('wcepi_enable_stock_status', 'yes'), 'yes'); ?>>
                                <?php echo $this->help_tip(__('Replaces WooCommerce\'s plain stock text with a styled badge (icon + color). "Ships in X Days" and the expected-restock date are set per product in the Enhanced Product Information box.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Show colored stock status with quantity and optional return date', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_in_stock_text">
                                    <?php _e('In Stock Text', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_in_stock_text" 
                                       name="wcepi_in_stock_text" 
                                       value="<?php echo esc_attr(get_option('wcepi_in_stock_text', 'In Stock')); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_out_of_stock_text">
                                    <?php _e('Out of Stock Text', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_out_of_stock_text"
                                       name="wcepi_out_of_stock_text"
                                       value="<?php echo esc_attr(get_option('wcepi_out_of_stock_text', 'Out of Stock')); ?>"
                                       class="regular-text">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_ships_in_text">
                                    <?php _e('Ships In Text', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_ships_in_text"
                                       name="wcepi_ships_in_text"
                                       value="<?php echo esc_attr(get_option('wcepi_ships_in_text', 'Ships in %s Days')); ?>"
                                       class="regular-text">
                                <?php echo $this->help_tip(__('The number comes from each product\'s "Ships in (days)" field. Products without a value simply show "In Stock" with no shipping estimate.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Use %s as placeholder for the number of days (e.g., "Ships in %s Days" or "Ready in %s business days")', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_custom_dimensions">
                                    <?php _e('Enable Custom Dimensions', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_custom_dimensions"
                                       name="wcepi_enable_custom_dimensions" value="yes"
                                       <?php checked(get_option('wcepi_enable_custom_dimensions', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Allow adding custom dimension fields beyond Width, Depth, Height', 'wc-enhanced-product-info'); ?>
                                </p>
                                <div style="margin-top: 10px; margin-left: 24px;">
                                    <label>
                                        <input type="checkbox" id="wcepi_sync_dimensions_to_attributes"
                                               name="wcepi_sync_dimensions_to_attributes" value="yes"
                                               <?php checked(get_option('wcepi_sync_dimensions_to_attributes', 'no'), 'yes'); ?>>
                                        <?php _e('Sync to Attributes', 'wc-enhanced-product-info'); ?>
                                    </label>
                                    <?php echo $this->help_tip(__('Creates real WooCommerce attributes (visible under Products → Attributes) from each dimension, so filter widgets and sorting can use them. Runs automatically when a product is saved; use "Sync All Existing Products" below for products you already have.', 'wc-enhanced-product-info')); ?>
                                    <p class="description" style="margin-left: 0;">
                                        <?php _e('Create WooCommerce product attributes from dimension fields for filtering and sorting', 'wc-enhanced-product-info'); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_specifications">
                                    <?php _e('Enable Product Specifications', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_specifications"
                                       name="wcepi_enable_specifications" value="yes"
                                       <?php checked(get_option('wcepi_enable_specifications', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Show custom product specifications section', 'wc-enhanced-product-info'); ?>
                                </p>
                                <div style="margin-top: 10px; margin-left: 24px;">
                                    <label>
                                        <input type="checkbox" id="wcepi_sync_specs_to_attributes"
                                               name="wcepi_sync_specs_to_attributes" value="yes"
                                               <?php checked(get_option('wcepi_sync_specs_to_attributes', 'no'), 'yes'); ?>>
                                        <?php _e('Sync to Attributes', 'wc-enhanced-product-info'); ?>
                                    </label>
                                    <?php echo $this->help_tip(__('Creates real WooCommerce attributes (visible under Products → Attributes) from each specification, so filter widgets and sorting can use them. Runs automatically when a product is saved; use "Sync All Existing Products" below for products you already have.', 'wc-enhanced-product-info')); ?>
                                    <p class="description" style="margin-left: 0;">
                                        <?php _e('Create WooCommerce product attributes from specification fields for filtering and sorting', 'wc-enhanced-product-info'); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>

                        <!-- Bulk Sync Existing Products -->
                        <tr>
                            <th scope="row">
                                <?php _e('Bulk Sync Existing Products', 'wc-enhanced-product-info'); ?>
                            </th>
                            <td>
                                <p class="description" style="margin-bottom: 10px;">
                                    <?php _e('Sync specifications and dimensions from all existing products to WooCommerce attributes. This only needs to be run once after enabling the sync options above.', 'wc-enhanced-product-info'); ?>
                                </p>
                                <button type="button" id="wcepi-bulk-sync-btn" class="button button-secondary">
                                    <?php _e('Sync All Existing Products', 'wc-enhanced-product-info'); ?>
                                </button>
                                <span id="wcepi-bulk-sync-status" style="margin-left: 10px;"></span>
                                <div id="wcepi-bulk-sync-progress" style="display: none; margin-top: 10px;">
                                    <div style="background: #e0e0e0; border-radius: 3px; height: 20px; width: 300px;">
                                        <div id="wcepi-bulk-sync-bar" style="background: #0073aa; height: 100%; border-radius: 3px; width: 0%; transition: width 0.3s;"></div>
                                    </div>
                                    <p id="wcepi-bulk-sync-info" style="margin-top: 5px;"></p>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_downloads">
                                    <?php _e('Enable Downloads/Manuals', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_downloads" 
                                       name="wcepi_enable_downloads" value="yes" 
                                       <?php checked(get_option('wcepi_enable_downloads', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Allow uploading or linking to product PDFs and manuals', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_shipping_returns">
                                    <?php _e('Enable Shipping Policy', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_shipping_returns"
                                       name="wcepi_enable_shipping_returns" value="yes"
                                       <?php checked(get_option('wcepi_enable_shipping_returns', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Show shipping policy section', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_returns">
                                    <?php _e('Enable Returns Policy', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_returns"
                                       name="wcepi_enable_returns" value="yes"
                                       <?php checked(get_option('wcepi_enable_returns', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Show returns policy section', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_warranty">
                                    <?php _e('Enable Warranty Information', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_warranty" 
                                       name="wcepi_enable_warranty" value="yes" 
                                       <?php checked(get_option('wcepi_enable_warranty', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Show warranty information section', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_faq">
                                    <?php _e('Enable FAQ Section', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_faq"
                                       name="wcepi_enable_faq" value="yes"
                                       <?php checked(get_option('wcepi_enable_faq', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Show FAQ section for product questions and answers', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_custom_sections">
                                    <?php _e('Enable Custom Sections', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_custom_sections"
                                       name="wcepi_enable_custom_sections" value="yes"
                                       <?php checked(get_option('wcepi_enable_custom_sections', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Allow creating custom sections with specification fields or rich text content', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Payment Methods Badge', 'wc-enhanced-product-info'); ?></h2>
                                <p class="description"><?php _e('Display accepted payment method icons below the Add to Cart button', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_payment_methods">
                                    <?php _e('Enable Payment Methods Badge', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_payment_methods"
                                       name="wcepi_enable_payment_methods" value="yes"
                                       <?php checked(get_option('wcepi_enable_payment_methods', 'no'), 'yes'); ?>>
                                <?php echo $this->help_tip(__('A display-only trust signal — it shows icons for the methods you tick below but does not change which payment methods are available at checkout.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Show payment method icons below the Add to Cart button', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php _e('Accepted Payment Methods', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <?php
                                $payment_methods = array(
                                    'visa' => 'Visa',
                                    'mastercard' => 'Mastercard',
                                    'amex' => 'American Express',
                                    'discover' => 'Discover',
                                    'paypal' => 'PayPal',
                                    'apple_pay' => 'Apple Pay',
                                    'google_pay' => 'Google Pay',
                                    'venmo' => 'Venmo',
                                    'afterpay' => 'Afterpay',
                                    'klarna' => 'Klarna',
                                    'stripe' => 'Stripe',
                                    'cash' => 'Cash',
                                    'check' => 'Check',
                                    'bank_transfer' => 'Bank Transfer'
                                );
                                
                                $selected_methods = get_option('wcepi_payment_methods', array());
                                if (!is_array($selected_methods)) {
                                    $selected_methods = array();
                                }
                                
                                echo '<div class="wcepi-payment-methods-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; max-width: 600px;">';
                                foreach ($payment_methods as $key => $label) {
                                    $checked = in_array($key, $selected_methods) ? 'checked' : '';
                                    echo '<label style="display: flex; align-items: center; padding: 8px; background: #f9f9f9; border-radius: 4px;">';
                                    echo '<input type="checkbox" name="wcepi_payment_methods[]" value="' . esc_attr($key) . '" ' . $checked . ' style="margin-right: 8px;">';
                                    echo '<span>' . esc_html($label) . '</span>';
                                    echo '</label>';
                                }
                                echo '</div>';
                                ?>
                                <p class="description" style="margin-top: 10px;">
                                    <?php _e('Select all payment methods you accept', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Custom Payment Methods', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <p class="description" style="margin-bottom: 10px;">
                                    <?php _e('Add your own payment methods with custom icons. These will display alongside the built-in options above.', 'wc-enhanced-product-info'); ?>
                                </p>
                                <div id="wcepi-custom-payment-methods">
                                    <?php
                                    $custom_payment_methods = get_option('wcepi_custom_payment_methods', array());
                                    if (!is_array($custom_payment_methods)) {
                                        $custom_payment_methods = array();
                                    }
                                    if (!empty($custom_payment_methods)) {
                                        foreach ($custom_payment_methods as $index => $method) {
                                            ?>
                                            <div class="wcepi-custom-payment-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                                <span class="wcepi-drag-handle dashicons dashicons-menu" title="<?php _e('Drag to reorder', 'wc-enhanced-product-info'); ?>"></span>
                                                <input type="text" name="wcepi_custom_payment_methods[<?php echo $index; ?>][name]"
                                                       value="<?php echo esc_attr($method['name']); ?>"
                                                       placeholder="<?php _e('Payment Name (e.g., Zelle)', 'wc-enhanced-product-info'); ?>"
                                                       class="regular-text" style="flex: 1;">
                                                <input type="hidden" name="wcepi_custom_payment_methods[<?php echo $index; ?>][image]"
                                                       value="<?php echo esc_url($method['image']); ?>"
                                                       class="wcepi-custom-payment-image-url">
                                                <?php if (!empty($method['image'])) : ?>
                                                    <img src="<?php echo esc_url($method['image']); ?>" alt="" style="width: 40px; height: 40px; object-fit: contain; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
                                                <?php else : ?>
                                                    <span class="wcepi-payment-preview" style="width: 40px; height: 40px; background: #fff; border: 1px solid #ddd; border-radius: 4px; display: inline-block;"></span>
                                                <?php endif; ?>
                                                <button type="button" class="button wcepi-upload-payment-icon"><?php _e('Upload Icon', 'wc-enhanced-product-info'); ?></button>
                                                <button type="button" class="button wcepi-remove-custom-payment"><?php _e('Remove', 'wc-enhanced-product-info'); ?></button>
                                            </div>
                                            <?php
                                        }
                                    }
                                    ?>
                                </div>
                                <button type="button" class="button wcepi-add-custom-payment" style="margin-top: 10px;">
                                    <?php _e('Add Custom Payment Method', 'wc-enhanced-product-info'); ?>
                                </button>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Icon Size (Desktop)', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="wcepi_payment_icon_size_desktop" 
                                       value="<?php echo esc_attr(get_option('wcepi_payment_icon_size_desktop', '52')); ?>" 
                                       min="20" 
                                       max="100" 
                                       step="1"
                                       style="width: 80px;">
                                <span class="description">px (default: 52px)</span>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php _e('Icon Size (Tablet)', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="wcepi_payment_icon_size_tablet" 
                                       value="<?php echo esc_attr(get_option('wcepi_payment_icon_size_tablet', '48')); ?>" 
                                       min="20" 
                                       max="100" 
                                       step="1"
                                       style="width: 80px;">
                                <span class="description">px (default: 48px, screens ≤768px)</span>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label><?php _e('Icon Size (Mobile)', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="wcepi_payment_icon_size_mobile" 
                                       value="<?php echo esc_attr(get_option('wcepi_payment_icon_size_mobile', '45')); ?>" 
                                       min="20" 
                                       max="100" 
                                       step="1"
                                       style="width: 80px;">
                                <span class="description">px (default: 45px, screens ≤480px)</span>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Display Settings', 'wc-enhanced-product-info'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_display_mode">
                                    <?php _e('Display Mode', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_display_mode" name="wcepi_display_mode">
                                    <option value="tabs" <?php selected(get_option('wcepi_display_mode', 'tabs'), 'tabs'); ?>>
                                        <?php _e('Tabs', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="accordion" <?php selected(get_option('wcepi_display_mode', 'tabs'), 'accordion'); ?>>
                                        <?php _e('Accordion', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="stacked" <?php selected(get_option('wcepi_display_mode', 'tabs'), 'stacked'); ?>>
                                        <?php _e('Stacked (No Tabs)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                                <?php echo $this->help_tip(__('Tabs: classic horizontal tabs (like most themes). Accordion: sections collapse/expand vertically — great on mobile. Stacked: all sections shown open, one after another, with no tabs. Reviews always display below the sections.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Choose how to display product information sections', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_accordion_default_open">
                                    <?php _e('Accordion: Default Open Section', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_accordion_default_open" name="wcepi_accordion_default_open">
                                    <option value="first" <?php selected(get_option('wcepi_accordion_default_open', 'first'), 'first'); ?>>
                                        <?php _e('Description / first section open', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="none" <?php selected(get_option('wcepi_accordion_default_open', 'first'), 'none'); ?>>
                                        <?php _e('All sections collapsed', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                                <?php echo $this->help_tip(__('Only applies when Display Mode is Accordion. Opens the Description section automatically when the page loads (or the first section, if a product has no description), so shoppers see content without an extra click.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Choose whether the accordion starts with the Description expanded', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Section Sort Order', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <p class="description" style="margin-bottom: 10px;">
                                    <?php _e('Drag and drop to reorder sections. This sets the default order for all products.', 'wc-enhanced-product-info'); ?>
                                </p>
                                <?php
                                $tab_order = self::get_tab_order();
                                $tab_labels = self::get_tab_labels();

                                // Sort tabs by their priority value
                                asort($tab_order);
                                ?>
                                <ul id="wcepi-tab-sort-order" class="wcepi-sortable-list">
                                    <?php foreach ($tab_order as $tab_key => $priority) : ?>
                                        <li class="wcepi-sortable-item" data-tab="<?php echo esc_attr($tab_key); ?>">
                                            <span class="dashicons dashicons-menu wcepi-drag-handle"></span>
                                            <span class="wcepi-tab-label"><?php echo esc_html($tab_labels[$tab_key] ?? $tab_key); ?></span>
                                            <input type="hidden" name="wcepi_tab_order[<?php echo esc_attr($tab_key); ?>]" value="<?php echo esc_attr($priority); ?>" class="wcepi-tab-priority">
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <style>
                                    .wcepi-sortable-list {
                                        list-style: none;
                                        padding: 0;
                                        margin: 0;
                                        max-width: 400px;
                                    }
                                    .wcepi-sortable-item {
                                        display: flex;
                                        align-items: center;
                                        padding: 10px 12px;
                                        margin-bottom: 4px;
                                        background: #fff;
                                        border: 1px solid #ddd;
                                        border-radius: 4px;
                                        cursor: move;
                                        transition: background-color 0.2s, box-shadow 0.2s;
                                    }
                                    .wcepi-sortable-item:hover {
                                        background: #f9f9f9;
                                    }
                                    .wcepi-sortable-item.ui-sortable-helper {
                                        background: #fff;
                                        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                                    }
                                    .wcepi-sortable-item.ui-sortable-placeholder {
                                        visibility: visible !important;
                                        background: #e8f4fc;
                                        border: 2px dashed #2271b1;
                                    }
                                    .wcepi-drag-handle {
                                        color: #999;
                                        margin-right: 10px;
                                    }
                                    .wcepi-tab-label {
                                        flex: 1;
                                        font-weight: 500;
                                    }
                                </style>
                                <script>
                                jQuery(document).ready(function($) {
                                    if (typeof $.fn.sortable !== 'undefined') {
                                        $('#wcepi-tab-sort-order').sortable({
                                            handle: '.wcepi-drag-handle',
                                            placeholder: 'ui-sortable-placeholder',
                                            update: function(event, ui) {
                                                // Update priority values based on new order
                                                $('#wcepi-tab-sort-order .wcepi-sortable-item').each(function(index) {
                                                    $(this).find('.wcepi-tab-priority').val((index + 1) * 5);
                                                });
                                            }
                                        });
                                    } else {
                                        console.log('WCEPI: jQuery UI Sortable not loaded');
                                    }
                                });
                                </script>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Product Listing/Archive Pages', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('These badges appear under each product\'s price on the shop and category pages. They use the same per-product data as the product page — e.g. the free shipping badge only shows on products that have free shipping enabled, and warranty only shows on products with warranty info.', 'wc-enhanced-product-info')); ?></h2>
                                <p class="description"><?php _e('Show badges on shop, category, and other product listing pages', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Show Free Shipping Badge', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcepi_archive_show_free_shipping" value="yes"
                                           <?php checked(get_option('wcepi_archive_show_free_shipping', 'no'), 'yes'); ?>>
                                    <?php _e('Display free shipping badge on product listings', 'wc-enhanced-product-info'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Show Warranty Info', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcepi_archive_show_warranty" value="yes"
                                           <?php checked(get_option('wcepi_archive_show_warranty', 'no'), 'yes'); ?>>
                                    <?php _e('Display warranty information on product listings', 'wc-enhanced-product-info'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Show Stock Status', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcepi_archive_show_stock" value="yes"
                                           <?php checked(get_option('wcepi_archive_show_stock', 'no'), 'yes'); ?>>
                                    <?php _e('Display stock status badge on product listings (e.g., "In Stock")', 'wc-enhanced-product-info'); ?>
                                </label>
                                <br><br>
                                <label>
                                    <input type="checkbox" name="wcepi_archive_show_ships_in" value="yes"
                                           <?php checked(get_option('wcepi_archive_show_ships_in', 'no'), 'yes'); ?>>
                                    <?php _e('Include "Ships in X Days" in stock badge (e.g., "In Stock - Ships in 3 Days")', 'wc-enhanced-product-info'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Show Custom Badges', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcepi_archive_show_custom_badges" value="yes"
                                           <?php checked(get_option('wcepi_archive_show_custom_badges', 'no'), 'yes'); ?>>
                                    <?php _e('Display custom badges on product listings', 'wc-enhanced-product-info'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <td colspan="2">
                                <p class="description" style="margin-top: 15px;">
                                    <?php _e('To customize the styling of listing badges separately from product page badges, go to the', 'wc-enhanced-product-info'); ?>
                                    <a href="?page=wcepi-settings&tab=styling#archive-badges-styling"><?php _e('Styling tab → Product Listing Badge Styling', 'wc-enhanced-product-info'); ?></a>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h2 style="margin-top: 30px;"><?php _e('Global Content Templates', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('This content shows in the Shipping and Returns sections of every product. To override it for a single product, fill in the Custom Shipping/Returns Policy editors in that product\'s Enhanced Product Information box — or use the reusable templates there for per-brand policies.', 'wc-enhanced-product-info')); ?></h2>
                                <p class="description"><?php _e('Default content that will be displayed on all products unless overridden individually', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_shipping_returns_content">
                                    <?php _e('Shipping Policy', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                $shipping_content = get_option('wcepi_shipping_returns_content', '');
                                wp_editor($shipping_content, 'wcepi_shipping_returns_content', array(
                                    'textarea_name' => 'wcepi_shipping_returns_content',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny' => true
                                ));
                                ?>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_returns_content">
                                    <?php _e('Returns Policy', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                $returns_content = get_option('wcepi_returns_content', '');
                                wp_editor($returns_content, 'wcepi_returns_content', array(
                                    'textarea_name' => 'wcepi_returns_content',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny' => true
                                ));
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($active_tab === 'layout'): ?>
                <!-- LAYOUT & POSITION TAB -->
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Badge Layout & Position', 'wc-enhanced-product-info'); ?></h2>
                                <p class="description"><?php _e('Control the layout and position of warranty and stock badges on product pages', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_badges_inline_layout">
                                    <?php _e('Badge Layout', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_badges_inline_layout" name="wcepi_badges_inline_layout">
                                    <option value="stacked" <?php selected(get_option('wcepi_badges_inline_layout', 'stacked'), 'stacked'); ?>>
                                        <?php _e('Stacked (Each badge on own line)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="inline" <?php selected(get_option('wcepi_badges_inline_layout', 'stacked'), 'inline'); ?>>
                                        <?php _e('Inline (Badges on same line)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                                <?php echo $this->help_tip(__('Stacked puts each badge on its own line (easier to scan). Inline places them side by side on one row (more compact, closer to how Shopify stores look).', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Choose how warranty and stock badges are displayed. Inline mode shows them side by side.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Badge Order & Position Section -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;"><?php _e('Badge Order & Position', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('This controls where each badge sits on the product page. "Next to Price" is only available for the Free Shipping badge — it attaches the badge directly to the price line. Order within the same position follows this list top to bottom.', 'wc-enhanced-product-info')); ?></h3>
                                <p class="description"><?php _e('Drag to reorder badges. Set each badge to appear Above or Below the Add to Cart button.', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <?php
                                $badge_order = self::get_badge_order();
                                $badge_labels = self::get_badge_labels();
                                $badge_positions = self::get_badge_positions();
                                $custom_badges = self::get_custom_badges();

                                // Add custom badges to the order list
                                foreach ($custom_badges as $badge_id => $badge_data) {
                                    $custom_key = 'custom_' . $badge_id;
                                    if (!isset($badge_order[$custom_key])) {
                                        $badge_order[$custom_key] = max($badge_order) + 1;
                                    }
                                    $badge_labels[$custom_key] = $badge_data['label'];
                                    if (!isset($badge_positions[$custom_key])) {
                                        $badge_positions[$custom_key] = 'above';
                                    }
                                }

                                // Sort badges by their priority value
                                asort($badge_order);
                                ?>
                                <ul id="wcepi-badge-sort-order" class="wcepi-sortable-list">
                                    <?php foreach ($badge_order as $badge_key => $priority) : ?>
                                        <?php if (isset($badge_labels[$badge_key])) :
                                            $current_position = isset($badge_positions[$badge_key]) ? $badge_positions[$badge_key] : 'above';
                                        ?>
                                        <li class="wcepi-sortable-item" data-badge="<?php echo esc_attr($badge_key); ?>">
                                            <span class="dashicons dashicons-menu wcepi-drag-handle"></span>
                                            <span class="wcepi-badge-label"><?php echo esc_html($badge_labels[$badge_key]); ?></span>
                                            <?php if (strpos($badge_key, 'custom_') === 0) : ?>
                                                <span class="wcepi-custom-badge-indicator" style="font-size: 11px; color: #666; margin-left: 8px;">(<?php _e('custom', 'wc-enhanced-product-info'); ?>)</span>
                                            <?php endif; ?>
                                            <select name="wcepi_badges_positions[<?php echo esc_attr($badge_key); ?>]" class="wcepi-badge-position-select" style="margin-left: auto;">
                                                <?php if ($badge_key === 'free_shipping') : ?>
                                                    <option value="next_to_price" <?php selected($current_position, 'next_to_price'); ?>><?php _e('Next to Price', 'wc-enhanced-product-info'); ?></option>
                                                <?php endif; ?>
                                                <option value="above" <?php selected($current_position, 'above'); ?>><?php _e('Above Add to Cart', 'wc-enhanced-product-info'); ?></option>
                                                <option value="below" <?php selected($current_position, 'below'); ?>><?php _e('Below Add to Cart', 'wc-enhanced-product-info'); ?></option>
                                            </select>
                                            <input type="hidden" name="wcepi_badges_order[<?php echo esc_attr($badge_key); ?>]" value="<?php echo esc_attr($priority); ?>" class="wcepi-badge-priority">
                                        </li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>

                                <style>
                                    .wcepi-sortable-list .wcepi-sortable-item {
                                        display: flex !important;
                                        align-items: center;
                                        gap: 10px;
                                    }
                                    .wcepi-badge-position-select {
                                        padding: 4px 8px;
                                        border-radius: 4px;
                                        border: 1px solid #8c8f94;
                                        font-size: 13px;
                                    }
                                </style>

                                <script>
                                jQuery(document).ready(function($) {
                                    if (typeof $.fn.sortable !== 'undefined') {
                                        $('#wcepi-badge-sort-order').sortable({
                                            handle: '.wcepi-drag-handle',
                                            placeholder: 'ui-sortable-placeholder',
                                            update: function(event, ui) {
                                                // Update priority values based on new order
                                                $('#wcepi-badge-sort-order .wcepi-sortable-item').each(function(index) {
                                                    $(this).find('.wcepi-badge-priority').val(index + 1);
                                                });
                                            }
                                        });
                                    }
                                });
                                </script>
                            </td>
                        </tr>

                        <!-- Custom Badges Section -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;"><?php _e('Custom Badges', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('Storewide trust signals like "30-Day Money-Back Guarantee" or "24/7 Support". Unlike the free shipping badge, these show on every product automatically once enabled. Position and order them in the Badge Order & Position list above.', 'wc-enhanced-product-info')); ?></h3>
                                <p class="description"><?php _e('Create custom badges for things like "Satisfaction Guarantee", "Secure Shipping", "24/7 Support", etc. These badges will be displayed on all products.', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <?php
                                $badge_icons = self::get_badge_icons();
                                ?>
                                <div id="wcepi-custom-badges-container">
                                    <?php
                                    if (!empty($custom_badges)) :
                                        foreach ($custom_badges as $badge_id => $badge_data) :
                                    ?>
                                    <div class="wcepi-custom-badge-item" data-badge-id="<?php echo esc_attr($badge_id); ?>">
                                        <div class="wcepi-custom-badge-header">
                                            <span class="dashicons dashicons-menu wcepi-drag-handle" style="cursor: move;"></span>
                                            <strong><?php echo esc_html($badge_data['label']); ?></strong>
                                            <button type="button" class="wcepi-toggle-badge-edit" style="margin-left: auto;"><?php _e('Edit', 'wc-enhanced-product-info'); ?></button>
                                            <button type="button" class="wcepi-remove-custom-badge" style="color: #a00;"><?php _e('Remove', 'wc-enhanced-product-info'); ?></button>
                                        </div>
                                        <div class="wcepi-custom-badge-fields" style="display: none; padding: 15px; background: #f9f9f9; margin-top: 10px; border-radius: 4px;">
                                            <p>
                                                <label><strong><?php _e('Badge Text', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][label]" value="<?php echo esc_attr($badge_data['label']); ?>" class="regular-text">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Icon', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <select name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][icon]" class="wcepi-icon-select wcepi-custom-badge-icon-select" data-badge-id="<?php echo esc_attr($badge_id); ?>">
                                                    <?php foreach ($badge_icons as $icon_key => $icon_label) : ?>
                                                        <option value="<?php echo esc_attr($icon_key); ?>" <?php selected($badge_data['icon'] ?? 'shield-check', $icon_key); ?>><?php echo esc_html($icon_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="wcepi-custom-badge-icon-preview wcepi-icon-preview" data-badge-id="<?php echo esc_attr($badge_id); ?>" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #f5f5f5; border-radius: 4px; margin-left: 10px; vertical-align: middle;"></span>
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Background Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][bg_color]" value="<?php echo esc_attr($badge_data['bg_color'] ?? 'transparent'); ?>" class="wcepi-color-picker">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Text Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][text_color]" value="<?php echo esc_attr($badge_data['text_color'] ?? '#1a1a1a'); ?>" class="wcepi-color-picker">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Icon Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][icon_color]" value="<?php echo esc_attr($badge_data['icon_color'] ?? '#2563eb'); ?>" class="wcepi-color-picker">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Border Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][border_color]" value="<?php echo esc_attr($badge_data['border_color'] ?? ''); ?>" class="wcepi-color-picker" data-default-color="">
                                                <span class="description" style="display: block; margin-top: 5px;"><?php _e('Leave empty for no border', 'wc-enhanced-product-info'); ?></span>
                                            </p>
                                            <p>
                                                <label>
                                                    <input type="checkbox" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][enabled]" value="yes" <?php checked($badge_data['enabled'] ?? 'yes', 'yes'); ?>>
                                                    <?php _e('Enable this badge', 'wc-enhanced-product-info'); ?>
                                                </label>
                                            </p>
                                            <input type="hidden" name="wcepi_custom_badges[<?php echo esc_attr($badge_id); ?>][id]" value="<?php echo esc_attr($badge_id); ?>">
                                        </div>
                                    </div>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </div>

                                <button type="button" id="wcepi-add-custom-badge" class="button" style="margin-top: 15px;">
                                    <span class="dashicons dashicons-plus-alt" style="vertical-align: text-bottom;"></span>
                                    <?php _e('Add Custom Badge', 'wc-enhanced-product-info'); ?>
                                </button>

                                <!-- Template for new custom badge -->
                                <script type="text/template" id="wcepi-custom-badge-template">
                                    <div class="wcepi-custom-badge-item" data-badge-id="{{BADGE_ID}}">
                                        <div class="wcepi-custom-badge-header">
                                            <span class="dashicons dashicons-menu wcepi-drag-handle" style="cursor: move;"></span>
                                            <strong class="wcepi-badge-title"><?php _e('New Badge', 'wc-enhanced-product-info'); ?></strong>
                                            <button type="button" class="wcepi-toggle-badge-edit" style="margin-left: auto;"><?php _e('Edit', 'wc-enhanced-product-info'); ?></button>
                                            <button type="button" class="wcepi-remove-custom-badge" style="color: #a00;"><?php _e('Remove', 'wc-enhanced-product-info'); ?></button>
                                        </div>
                                        <div class="wcepi-custom-badge-fields" style="padding: 15px; background: #f9f9f9; margin-top: 10px; border-radius: 4px;">
                                            <p>
                                                <label><strong><?php _e('Badge Text', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[{{BADGE_ID}}][label]" value="" class="regular-text wcepi-badge-label-input" placeholder="<?php esc_attr_e('e.g., Satisfaction Guarantee', 'wc-enhanced-product-info'); ?>">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Icon', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <select name="wcepi_custom_badges[{{BADGE_ID}}][icon]" class="wcepi-icon-select wcepi-custom-badge-icon-select" data-badge-id="{{BADGE_ID}}">
                                                    <?php foreach ($badge_icons as $icon_key => $icon_label) : ?>
                                                        <option value="<?php echo esc_attr($icon_key); ?>" <?php echo $icon_key === 'shield-check' ? 'selected' : ''; ?>><?php echo esc_html($icon_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="wcepi-custom-badge-icon-preview wcepi-icon-preview" data-badge-id="{{BADGE_ID}}" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #f5f5f5; border-radius: 4px; margin-left: 10px; vertical-align: middle;"></span>
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Background Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[{{BADGE_ID}}][bg_color]" value="transparent" class="wcepi-color-picker-new">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Text Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[{{BADGE_ID}}][text_color]" value="#1a1a1a" class="wcepi-color-picker-new">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Icon Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[{{BADGE_ID}}][icon_color]" value="#2563eb" class="wcepi-color-picker-new">
                                            </p>
                                            <p>
                                                <label><strong><?php _e('Border Color', 'wc-enhanced-product-info'); ?></strong></label><br>
                                                <input type="text" name="wcepi_custom_badges[{{BADGE_ID}}][border_color]" value="" class="wcepi-color-picker-new" data-default-color="">
                                                <span class="description" style="display: block; margin-top: 5px;"><?php _e('Leave empty for no border', 'wc-enhanced-product-info'); ?></span>
                                            </p>
                                            <p>
                                                <label>
                                                    <input type="checkbox" name="wcepi_custom_badges[{{BADGE_ID}}][enabled]" value="yes" checked>
                                                    <?php _e('Enable this badge', 'wc-enhanced-product-info'); ?>
                                                </label>
                                            </p>
                                            <input type="hidden" name="wcepi_custom_badges[{{BADGE_ID}}][id]" value="{{BADGE_ID}}">
                                        </div>
                                    </div>
                                </script>

                                <style>
                                    .wcepi-custom-badge-item {
                                        background: #fff;
                                        border: 1px solid #ddd;
                                        border-radius: 4px;
                                        padding: 12px;
                                        margin-bottom: 10px;
                                        max-width: 600px;
                                    }
                                    .wcepi-custom-badge-header {
                                        display: flex;
                                        align-items: center;
                                        gap: 10px;
                                    }
                                    .wcepi-custom-badge-header button {
                                        background: none;
                                        border: none;
                                        cursor: pointer;
                                        padding: 4px 8px;
                                    }
                                    .wcepi-custom-badge-header button:hover {
                                        text-decoration: underline;
                                    }
                                    #wcepi-custom-badges-container .wcepi-custom-badge-item.ui-sortable-helper {
                                        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                                    }
                                    #wcepi-custom-badges-container .ui-sortable-placeholder {
                                        visibility: visible !important;
                                        background: #e8f4fc;
                                        border: 2px dashed #2271b1;
                                        height: 60px;
                                        margin-bottom: 10px;
                                    }
                                </style>

                                <script>
                                jQuery(document).ready(function($) {
                                    var badgeCounter = <?php echo count($custom_badges) + 1; ?>;

                                    // Custom badge icon SVG definitions
                                    var customBadgeIcons = {
                                        'none': '',
                                        'checkbox-square': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="1" y="1" width="22" height="22" rx="4" fill="#2563eb"/><path d="M7 12.5L10.5 16L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'checkbox-circle': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#2563eb"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'verified': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.4 2.4h3.4v3.4L20 10l-2 2.2v3.4h-3.4L12 18l-2.4-2.4H6.2v-3.4L4 10l2-2.2V4.4h3.4L12 2z" fill="#2563eb"/><path d="M9 10l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'check': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 12l5.5 5.5L20 7" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'shield': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="#2563eb"/></svg>',
                                        'shield-check': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="#2563eb"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'lock': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" fill="#2563eb"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="#2563eb" stroke-width="2"/></svg>',
                                        'key': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="7.5" cy="15.5" r="5.5" fill="#2563eb"/><path d="M11 12l10-10M21 2l-3 3M18 5l-3 3" stroke="#2563eb" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'badge': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="#2563eb"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'star': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="#2563eb"/></svg>',
                                        'award': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="6" fill="#2563eb"/><path d="M8.21 13.89L7 23l5-3 5 3-1.21-9.12" fill="#2563eb"/></svg>',
                                        'certificate': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="14" rx="2" fill="#2563eb"/><path d="M7 9h10M7 12h6" stroke="white" stroke-width="1.5" stroke-linecap="round"/><circle cx="16" cy="18" r="3" fill="#2563eb" stroke="white" stroke-width="1"/></svg>',
                                        'trophy': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M6 2h12v6a6 6 0 11-12 0V2z" fill="#2563eb"/><path d="M6 4H2v4a4 4 0 004 4M18 4h4v4a4 4 0 01-4 4M12 14v4M8 22h8M12 18h0" stroke="#2563eb" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'crown': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M2 17l3-11 5 6 2-8 2 8 5-6 3 11H2z" fill="#2563eb"/><rect x="2" y="17" width="20" height="4" fill="#2563eb"/></svg>',
                                        'thumbs-up': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" fill="#2563eb"/></svg>',
                                        'heart': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z" fill="#2563eb"/></svg>',
                                        'smile': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#2563eb"/><path d="M8 14s1.5 2 4 2 4-2 4-2" stroke="white" stroke-width="2" stroke-linecap="round"/><circle cx="9" cy="9" r="1.5" fill="white"/><circle cx="15" cy="9" r="1.5" fill="white"/></svg>',
                                        'truck': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M16 3H1v13h15V3z" fill="#2563eb"/><path d="M16 8h4l3 3v5h-7V8z" fill="#2563eb"/><circle cx="5.5" cy="18.5" r="2.5" fill="#2563eb" stroke="white" stroke-width="1"/><circle cx="18.5" cy="18.5" r="2.5" fill="#2563eb" stroke="white" stroke-width="1"/></svg>',
                                        'box': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" fill="#2563eb"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="white" stroke-width="1.5"/></svg>',
                                        'plane': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" fill="#2563eb"/></svg>',
                                        'clock': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#2563eb"/><path d="M12 6v6l4 2" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'calendar': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" fill="#2563eb"/><path d="M16 2v4M8 2v4M3 10h18" stroke="white" stroke-width="1.5"/></svg>',
                                        'money': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#2563eb"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 010 3H9M9 12h4.5a1.5 1.5 0 010 3H9" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'credit-card': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="1" y="4" width="22" height="16" rx="2" fill="#2563eb"/><path d="M1 10h22" stroke="white" stroke-width="2"/><path d="M5 15h4M13 15h4" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>',
                                        'percent': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#2563eb"/><circle cx="9" cy="9" r="2" stroke="white" stroke-width="1.5"/><circle cx="15" cy="15" r="2" stroke="white" stroke-width="1.5"/><path d="M17 7L7 17" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'tag': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z" fill="#2563eb"/><circle cx="7" cy="7" r="2" fill="white"/></svg>',
                                        'headset': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M3 18v-6a9 9 0 0118 0v6" stroke="#2563eb" stroke-width="2"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3v5zM3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3v5z" fill="#2563eb"/></svg>',
                                        'phone': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z" fill="#2563eb"/></svg>',
                                        'chat': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2v10z" fill="#2563eb"/></svg>',
                                        'email': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="2" y="4" width="20" height="16" rx="2" fill="#2563eb"/><path d="M22 6l-10 7L2 6" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'refresh': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M23 4v6h-6M1 20v-6h6" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'undo': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M3 10h10a5 5 0 015 5v2" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 14l-4-4 4-4" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                                        'gift': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="8" width="18" height="14" rx="1" fill="#2563eb"/><rect x="1" y="4" width="22" height="4" fill="#2563eb"/><path d="M12 4v18M12 4c-1.5-2-4-3-6-2s-2 3 0 4c1 .5 4 0 6-2zM12 4c1.5-2 4-3 6-2s2 3 0 4c-1 .5-4 0-6-2z" stroke="white" stroke-width="1.5"/></svg>',
                                        'leaf': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M11 20A7 7 0 019.2 6.2C11 4 13 2 18 2c0 5-2 7-4.2 8.8A7 7 0 0111 20z" fill="#2563eb"/><path d="M4 21c1-5 5-9 7-9" stroke="#2563eb" stroke-width="2" stroke-linecap="round"/></svg>',
                                        'bolt': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="#2563eb"/></svg>',
                                        'fire': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 22c4-2 7-6 7-10 0-3-1.5-5.5-4-8-1 3-2 4-4 4-1-3-2-5-2-8-3 2-6 7-6 12 0 4 3 8 9 10z" fill="#2563eb"/></svg>',
                                        'globe': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#2563eb"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z" stroke="white" stroke-width="1.5"/></svg>',
                                        'info': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#2563eb"/><path d="M12 16v-4M12 8h.01" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>'
                                    };

                                    // Function to update custom badge icon preview
                                    function updateCustomBadgeIconPreview($select) {
                                        var value = $select.val();
                                        var badgeId = $select.data('badge-id');
                                        var $preview = $('.wcepi-custom-badge-icon-preview[data-badge-id="' + badgeId + '"]');

                                        if (customBadgeIcons[value]) {
                                            $preview.html(customBadgeIcons[value]);
                                        } else {
                                            $preview.html('');
                                        }
                                    }

                                    // Initialize icon previews on page load
                                    $('.wcepi-custom-badge-icon-select').each(function() {
                                        updateCustomBadgeIconPreview($(this));
                                    });

                                    // Update icon preview on change
                                    $(document).on('change', '.wcepi-custom-badge-icon-select', function() {
                                        updateCustomBadgeIconPreview($(this));
                                    });

                                    // Toggle edit fields
                                    $(document).on('click', '.wcepi-toggle-badge-edit', function() {
                                        $(this).closest('.wcepi-custom-badge-item').find('.wcepi-custom-badge-fields').slideToggle();
                                    });

                                    // Remove badge
                                    $(document).on('click', '.wcepi-remove-custom-badge', function() {
                                        if (confirm('<?php _e('Are you sure you want to remove this badge?', 'wc-enhanced-product-info'); ?>')) {
                                            var $item = $(this).closest('.wcepi-custom-badge-item');
                                            var badgeId = $item.data('badge-id');

                                            // Also remove from badge order list if exists
                                            $('#wcepi-badge-sort-order .wcepi-sortable-item[data-badge="custom_' + badgeId + '"]').remove();

                                            $item.slideUp(function() {
                                                $(this).remove();
                                            });
                                        }
                                    });

                                    // Update title when label changes
                                    $(document).on('input', '.wcepi-badge-label-input', function() {
                                        var newLabel = $(this).val() || '<?php _e('New Badge', 'wc-enhanced-product-info'); ?>';
                                        $(this).closest('.wcepi-custom-badge-item').find('.wcepi-badge-title').text(newLabel);
                                    });

                                    // Add new badge
                                    $('#wcepi-add-custom-badge').on('click', function() {
                                        var template = $('#wcepi-custom-badge-template').html();
                                        var newId = 'badge_' + Date.now();
                                        var newHtml = template.replace(/\{\{BADGE_ID\}\}/g, newId);

                                        var $newBadge = $(newHtml);
                                        $('#wcepi-custom-badges-container').append($newBadge);

                                        // Initialize color pickers for new badge
                                        $newBadge.find('.wcepi-color-picker-new').each(function() {
                                            $(this).removeClass('wcepi-color-picker-new').addClass('wcepi-color-picker');
                                            if ($.fn.wpColorPicker) {
                                                $(this).wpColorPicker();
                                            }
                                        });

                                        // Initialize icon preview for new badge
                                        var $newSelect = $newBadge.find('.wcepi-custom-badge-icon-select');
                                        updateCustomBadgeIconPreview($newSelect);

                                        // Add to badge order list
                                        var orderItem = '<li class="wcepi-sortable-item" data-badge="custom_' + newId + '">' +
                                            '<span class="dashicons dashicons-menu wcepi-drag-handle"></span>' +
                                            '<span class="wcepi-badge-label"><?php _e('New Badge', 'wc-enhanced-product-info'); ?></span>' +
                                            '<span class="wcepi-custom-badge-indicator" style="font-size: 11px; color: #666; margin-left: 8px;">(<?php _e('custom', 'wc-enhanced-product-info'); ?>)</span>' +
                                            '<select name="wcepi_badges_positions[custom_' + newId + ']" class="wcepi-badge-position-select" style="margin-left: auto;">' +
                                            '<option value="above"><?php _e('Above Cart', 'wc-enhanced-product-info'); ?></option>' +
                                            '<option value="below"><?php _e('Below Cart', 'wc-enhanced-product-info'); ?></option>' +
                                            '</select>' +
                                            '<input type="hidden" name="wcepi_badges_order[custom_' + newId + ']" value="' + ($('#wcepi-badge-sort-order .wcepi-sortable-item').length + 1) + '" class="wcepi-badge-priority">' +
                                            '</li>';
                                        $('#wcepi-badge-sort-order').append(orderItem);

                                        badgeCounter++;
                                    });

                                    // Make custom badges sortable
                                    if (typeof $.fn.sortable !== 'undefined') {
                                        $('#wcepi-custom-badges-container').sortable({
                                            handle: '.wcepi-drag-handle',
                                            placeholder: 'ui-sortable-placeholder',
                                            items: '.wcepi-custom-badge-item'
                                        });
                                    }
                                });
                                </script>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($active_tab === 'styling'): ?>
                <!-- STYLING TAB -->
                <table class="form-table">
                    <tbody>
                        <!-- General Badge Styling -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 0; padding-top: 0;"><?php _e('Badge Shape', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_badges_shape">
                                    <?php _e('Badge Style', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_badges_shape" name="wcepi_badges_shape">
                                    <option value="rounded" <?php selected(get_option('wcepi_badges_shape', 'rounded'), 'rounded'); ?>>
                                        <?php _e('Rounded (Pill)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="slightly-rounded" <?php selected(get_option('wcepi_badges_shape', 'rounded'), 'slightly-rounded'); ?>>
                                        <?php _e('Slightly Rounded', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="squared" <?php selected(get_option('wcepi_badges_shape', 'rounded'), 'squared'); ?>>
                                        <?php _e('Squared', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                                <?php echo $this->help_tip(__('Applies to warranty, stock, and custom badges on product pages. Listing/archive badges follow this too unless you pick a different shape under Product Listing Badge Styling below.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Choose the shape for warranty and stock badges', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Free Shipping Badge Styling -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Free Shipping Badge', 'wc-enhanced-product-info'); ?></h3>
                                <p class="description"><?php _e('Style the free shipping badge that appears next to the price', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>

                        <?php $this->badge_preview_row('wcepi-preview-free-shipping', array('text' => get_option('wcepi_free_shipping_text', 'Free Shipping'))); ?>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_free_shipping_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_free_shipping_bg_color"
                                       name="wcepi_free_shipping_bg_color"
                                       value="<?php echo esc_attr(get_option('wcepi_free_shipping_bg_color', '#4CAF50')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #4CAF50 (green)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_free_shipping_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_free_shipping_text_color"
                                       name="wcepi_free_shipping_text_color"
                                       value="<?php echo esc_attr(get_option('wcepi_free_shipping_text_color', '#ffffff')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #ffffff (white)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_free_shipping_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_free_shipping_border_color"
                                       name="wcepi_free_shipping_border_color"
                                       value="<?php echo esc_attr(get_option('wcepi_free_shipping_border_color', '')); ?>"
                                       class="wcepi-color-picker" data-default-color="">
                                <p class="description">
                                    <?php _e('Leave empty for no border, or set a color for a visible border.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Warranty Badge Styling -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Warranty Badge', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('This badge appears on products where "Display warranty info below product price" is checked in the product\'s Enhanced Product Information box (or via a warranty template).', 'wc-enhanced-product-info')); ?></h3>
                            </th>
                        </tr>

                        <?php $this->badge_preview_row('wcepi-preview-warranty', array('text' => __('2-Year Warranty', 'wc-enhanced-product-info'))); ?>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_warranty_badge_bg_color"
                                       name="wcepi_warranty_badge_bg_color"
                                       value="<?php echo esc_attr(get_option('wcepi_warranty_badge_bg_color', 'transparent')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: transparent', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_warranty_badge_text_color"
                                       name="wcepi_warranty_badge_text_color"
                                       value="<?php echo esc_attr(get_option('wcepi_warranty_badge_text_color', '#1a1a1a')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #1a1a1a (dark gray)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_warranty_badge_border_color"
                                       name="wcepi_warranty_badge_border_color"
                                       value="<?php echo esc_attr(get_option('wcepi_warranty_badge_border_color', '')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Optional left border. Leave empty for no border.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_warranty_badge_icon_color"
                                       name="wcepi_warranty_badge_icon_color"
                                       value="<?php echo esc_attr(get_option('wcepi_warranty_badge_icon_color', '#2563eb')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #2563eb (blue)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_icon_type">
                                    <?php _e('Icon Style', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <select id="wcepi_warranty_badge_icon_type" name="wcepi_warranty_badge_icon_type" class="wcepi-icon-select" data-preview="warranty-icon-preview">
                                        <option value="checkbox-square" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'checkbox-square'); ?>>
                                            <?php _e('Checkbox (Square)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="checkbox-circle" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'checkbox-circle'); ?>>
                                            <?php _e('Checkmark (Circle)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="shield" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'shield'); ?>>
                                            <?php _e('Shield', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="shield-check" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'shield-check'); ?>>
                                            <?php _e('Shield with Checkmark', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="badge" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'badge'); ?>>
                                            <?php _e('Badge/Ribbon', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="star" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'star'); ?>>
                                            <?php _e('Star', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="award" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'award'); ?>>
                                            <?php _e('Award/Medal', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="certificate" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'certificate'); ?>>
                                            <?php _e('Certificate', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="thumbs-up" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'thumbs-up'); ?>>
                                            <?php _e('Thumbs Up', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="verified" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'verified'); ?>>
                                            <?php _e('Verified Badge', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="none" <?php selected(get_option('wcepi_warranty_badge_icon_type', 'checkbox-square'), 'none'); ?>>
                                            <?php _e('No Icon', 'wc-enhanced-product-info'); ?>
                                        </option>
                                    </select>
                                    <span id="warranty-icon-preview" class="wcepi-icon-preview" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #f5f5f5; border-radius: 4px;"></span>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_font_size">
                                    <?php _e('Font Size (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_warranty_badge_font_size"
                                       name="wcepi_warranty_badge_font_size"
                                       value="<?php echo esc_attr(get_option('wcepi_warranty_badge_font_size', '14')); ?>"
                                       min="10" max="24" step="1" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 14px', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_warranty_badge_font_weight">
                                    <?php _e('Font Weight', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_warranty_badge_font_weight" name="wcepi_warranty_badge_font_weight">
                                    <option value="400" <?php selected(get_option('wcepi_warranty_badge_font_weight', '500'), '400'); ?>>
                                        <?php _e('Normal (400)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="500" <?php selected(get_option('wcepi_warranty_badge_font_weight', '500'), '500'); ?>>
                                        <?php _e('Medium (500)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="600" <?php selected(get_option('wcepi_warranty_badge_font_weight', '500'), '600'); ?>>
                                        <?php _e('Semi-Bold (600)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="700" <?php selected(get_option('wcepi_warranty_badge_font_weight', '500'), '700'); ?>>
                                        <?php _e('Bold (700)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <!-- Stock Status Badge Styling -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('In Stock Badge', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <?php $this->badge_preview_row('wcepi-preview-stock-in', array('text' => get_option('wcepi_in_stock_text', 'In Stock'))); ?>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_in_stock_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_in_stock_bg_color"
                                       name="wcepi_stock_badge_in_stock_bg_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_in_stock_bg_color', 'transparent')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: transparent', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_in_stock_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_in_stock_text_color"
                                       name="wcepi_stock_badge_in_stock_text_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_in_stock_text_color', '#1a1a1a')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #1a1a1a (dark gray)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_in_stock_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_in_stock_icon_color"
                                       name="wcepi_stock_badge_in_stock_icon_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_in_stock_icon_color', '#16a34a')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #16a34a (green)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_in_stock_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_in_stock_border_color"
                                       name="wcepi_stock_badge_in_stock_border_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_in_stock_border_color', '')); ?>"
                                       class="wcepi-color-picker" data-default-color="">
                                <p class="description">
                                    <?php _e('Leave empty for no border (transparent when custom background is set)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Out of Stock Badge Styling -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Out of Stock Badge', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <?php $this->badge_preview_row('wcepi-preview-stock-out', array('text' => get_option('wcepi_out_of_stock_text', 'Out of Stock'))); ?>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_out_of_stock_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_out_of_stock_bg_color"
                                       name="wcepi_stock_badge_out_of_stock_bg_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_out_of_stock_bg_color', 'transparent')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: transparent', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_out_of_stock_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_out_of_stock_text_color"
                                       name="wcepi_stock_badge_out_of_stock_text_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_out_of_stock_text_color', '#dc2626')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #dc2626 (red)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_out_of_stock_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_out_of_stock_icon_color"
                                       name="wcepi_stock_badge_out_of_stock_icon_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_out_of_stock_icon_color', '#dc2626')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #dc2626 (red)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_out_of_stock_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_stock_badge_out_of_stock_border_color"
                                       name="wcepi_stock_badge_out_of_stock_border_color"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_out_of_stock_border_color', '')); ?>"
                                       class="wcepi-color-picker" data-default-color="">
                                <p class="description">
                                    <?php _e('Leave empty for no border (transparent when custom background is set)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Stock Badge Common Settings -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Stock Badge Common Settings', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_in_stock_icon_type">
                                    <?php _e('In Stock Icon Style', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <select id="wcepi_stock_badge_in_stock_icon_type" name="wcepi_stock_badge_in_stock_icon_type" class="wcepi-icon-select" data-preview="in-stock-icon-preview" data-icon-type="in-stock">
                                        <option value="circle-check" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'circle-check'); ?>>
                                            <?php _e('Circle with Checkmark', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="square-check" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'square-check'); ?>>
                                            <?php _e('Square with Checkmark', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="checkmark-only" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'checkmark-only'); ?>>
                                            <?php _e('Checkmark Only (No Background)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="dot" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'dot'); ?>>
                                            <?php _e('Colored Dot', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="truck" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'truck'); ?>>
                                            <?php _e('Truck (Shipping)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="box" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'box'); ?>>
                                            <?php _e('Package/Box', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="warehouse" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'warehouse'); ?>>
                                            <?php _e('Warehouse/Storage', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="lightning" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'lightning'); ?>>
                                            <?php _e('Lightning Bolt (Fast)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="clock" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'clock'); ?>>
                                            <?php _e('Clock (Ready)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="thumbs-up" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'thumbs-up'); ?>>
                                            <?php _e('Thumbs Up', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="none" <?php selected(get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), 'none'); ?>>
                                            <?php _e('No Icon', 'wc-enhanced-product-info'); ?>
                                        </option>
                                    </select>
                                    <span id="in-stock-icon-preview" class="wcepi-icon-preview" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #f5f5f5; border-radius: 4px;"></span>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_out_of_stock_icon_type">
                                    <?php _e('Out of Stock Icon Style', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <select id="wcepi_stock_badge_out_of_stock_icon_type" name="wcepi_stock_badge_out_of_stock_icon_type" class="wcepi-icon-select" data-preview="out-of-stock-icon-preview" data-icon-type="out-of-stock">
                                        <option value="circle-x" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'circle-x'); ?>>
                                            <?php _e('Circle with X', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="square-x" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'square-x'); ?>>
                                            <?php _e('Square with X', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="x-only" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'x-only'); ?>>
                                            <?php _e('X Only (No Background)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="dot" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'dot'); ?>>
                                            <?php _e('Colored Dot', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="clock" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'clock'); ?>>
                                            <?php _e('Clock (Coming Soon)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="calendar" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'calendar'); ?>>
                                            <?php _e('Calendar (Back Order)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="alert" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'alert'); ?>>
                                            <?php _e('Alert Triangle', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="ban" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'ban'); ?>>
                                            <?php _e('Ban/Prohibited', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="none" <?php selected(get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), 'none'); ?>>
                                            <?php _e('No Icon', 'wc-enhanced-product-info'); ?>
                                        </option>
                                    </select>
                                    <span id="out-of-stock-icon-preview" class="wcepi-icon-preview" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #f5f5f5; border-radius: 4px;"></span>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_font_size">
                                    <?php _e('Font Size (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_stock_badge_font_size"
                                       name="wcepi_stock_badge_font_size"
                                       value="<?php echo esc_attr(get_option('wcepi_stock_badge_font_size', '14')); ?>"
                                       min="10" max="24" step="1" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 14px', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_stock_badge_font_weight">
                                    <?php _e('Font Weight', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_stock_badge_font_weight" name="wcepi_stock_badge_font_weight">
                                    <option value="400" <?php selected(get_option('wcepi_stock_badge_font_weight', '500'), '400'); ?>>
                                        <?php _e('Normal (400)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="500" <?php selected(get_option('wcepi_stock_badge_font_weight', '500'), '500'); ?>>
                                        <?php _e('Medium (500)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="600" <?php selected(get_option('wcepi_stock_badge_font_weight', '500'), '600'); ?>>
                                        <?php _e('Semi-Bold (600)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="700" <?php selected(get_option('wcepi_stock_badge_font_weight', '500'), '700'); ?>>
                                        <?php _e('Bold (700)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <!-- Ships In Badge Styling -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Ships In Badge', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_ships_in_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_ships_in_bg_color"
                                       name="wcepi_ships_in_bg_color"
                                       value="<?php echo esc_attr(get_option('wcepi_ships_in_bg_color', 'transparent')); ?>"
                                       class="wcepi-color-picker" data-default-color="transparent">
                                <p class="description">
                                    <?php _e('Default: transparent', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_ships_in_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_ships_in_text_color"
                                       name="wcepi_ships_in_text_color"
                                       value="<?php echo esc_attr(get_option('wcepi_ships_in_text_color', '#666666')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #666666 (gray)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_ships_in_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_ships_in_icon_color"
                                       name="wcepi_ships_in_icon_color"
                                       value="<?php echo esc_attr(get_option('wcepi_ships_in_icon_color', '#666666')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #666666 (gray)', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- End Badge Styling Section -->

                        <!-- Product Listing Badge Styling Section -->
                        <tr id="archive-badges-styling">
                            <th colspan="2">
                                <h2 style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ccc;"><?php _e('Product Listing Badge Styling', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('Styles badges on shop/category pages separately from the product page. Leave any color empty to inherit the product page badge styling — you only need to fill in what should look different on listings.', 'wc-enhanced-product-info')); ?></h2>
                                <p class="description"><?php _e('Customize badge appearance on shop, category, and archive pages. Leave colors empty to use the same styling as product pages.', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>

                        <!-- Archive Badge General Settings -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 10px;"><?php _e('General Listing Badge Settings', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_badge_font_size">
                                    <?php _e('Badge Font Size (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_archive_badge_font_size"
                                       name="wcepi_archive_badge_font_size"
                                       value="<?php echo esc_attr(get_option('wcepi_archive_badge_font_size', '12')); ?>"
                                       min="8" max="20" style="width: 80px;">
                                <p class="description">
                                    <?php _e('Default: 12px. Smaller size recommended for listing pages.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_badge_padding">
                                    <?php _e('Badge Padding (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_archive_badge_padding"
                                       name="wcepi_archive_badge_padding"
                                       value="<?php echo esc_attr(get_option('wcepi_archive_badge_padding', '4px 10px')); ?>"
                                       class="regular-text" style="width: 120px;">
                                <p class="description">
                                    <?php _e('Default: 4px 10px. Use CSS padding format (e.g., "5px 12px" for top/bottom and left/right).', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_badge_shape">
                                    <?php _e('Badge Shape', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_archive_badge_shape" name="wcepi_archive_badge_shape">
                                    <option value="" <?php selected(get_option('wcepi_archive_badge_shape', ''), ''); ?>>
                                        <?php _e('Use Product Page Setting', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="rounded" <?php selected(get_option('wcepi_archive_badge_shape', ''), 'rounded'); ?>>
                                        <?php _e('Rounded (Pill)', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="slightly-rounded" <?php selected(get_option('wcepi_archive_badge_shape', ''), 'slightly-rounded'); ?>>
                                        <?php _e('Slightly Rounded', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="squared" <?php selected(get_option('wcepi_archive_badge_shape', ''), 'squared'); ?>>
                                        <?php _e('Squared', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Choose the shape for listing page badges. Leave as "Use Product Page Setting" to match product pages.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <?php $this->badge_preview_row('wcepi-preview-archive', array(
                            'fs-text' => get_option('wcepi_free_shipping_text', 'Free Shipping'),
                            'warranty-text' => __('2-Year Warranty', 'wc-enhanced-product-info'),
                            'in-text' => get_option('wcepi_in_stock_text', 'In Stock'),
                            'out-text' => get_option('wcepi_out_of_stock_text', 'Out of Stock'),
                        )); ?>

                        <!-- Archive Free Shipping Badge -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Free Shipping Badge (Listings)', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_free_shipping_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $fs_bg_val = get_option('wcepi_archive_free_shipping_bg_color', ''); ?>
                                <select id="wcepi_archive_free_shipping_bg_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_free_shipping_bg_color">
                                    <option value="" <?php selected($fs_bg_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="transparent" <?php selected($fs_bg_val, 'transparent'); ?>><?php _e('Transparent (No Background)', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($fs_bg_val) && $fs_bg_val !== 'transparent', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_free_shipping_bg_color"
                                       name="wcepi_archive_free_shipping_bg_color"
                                       value="<?php echo esc_attr($fs_bg_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($fs_bg_val) || $fs_bg_val === 'transparent') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_free_shipping_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $fs_text_val = get_option('wcepi_archive_free_shipping_text_color', ''); ?>
                                <select id="wcepi_archive_free_shipping_text_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_free_shipping_text_color">
                                    <option value="" <?php selected($fs_text_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($fs_text_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_free_shipping_text_color"
                                       name="wcepi_archive_free_shipping_text_color"
                                       value="<?php echo esc_attr($fs_text_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($fs_text_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_free_shipping_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $fs_border_val = get_option('wcepi_archive_free_shipping_border_color', ''); ?>
                                <select id="wcepi_archive_free_shipping_border_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_free_shipping_border_color">
                                    <option value="" <?php selected($fs_border_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="none" <?php selected($fs_border_val, 'none'); ?>><?php _e('No Border', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($fs_border_val) && $fs_border_val !== 'none', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_free_shipping_border_color"
                                       name="wcepi_archive_free_shipping_border_color"
                                       value="<?php echo esc_attr($fs_border_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($fs_border_val) || $fs_border_val === 'none') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <!-- Archive Warranty Badge -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Warranty Badge (Listings)', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_warranty_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $war_bg_val = get_option('wcepi_archive_warranty_bg_color', ''); ?>
                                <select id="wcepi_archive_warranty_bg_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_warranty_bg_color">
                                    <option value="" <?php selected($war_bg_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="transparent" <?php selected($war_bg_val, 'transparent'); ?>><?php _e('Transparent (No Background)', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($war_bg_val) && $war_bg_val !== 'transparent', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_warranty_bg_color"
                                       name="wcepi_archive_warranty_bg_color"
                                       value="<?php echo esc_attr($war_bg_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($war_bg_val) || $war_bg_val === 'transparent') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_warranty_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $war_text_val = get_option('wcepi_archive_warranty_text_color', ''); ?>
                                <select id="wcepi_archive_warranty_text_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_warranty_text_color">
                                    <option value="" <?php selected($war_text_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($war_text_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_warranty_text_color"
                                       name="wcepi_archive_warranty_text_color"
                                       value="<?php echo esc_attr($war_text_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($war_text_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_warranty_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $war_border_val = get_option('wcepi_archive_warranty_border_color', ''); ?>
                                <select id="wcepi_archive_warranty_border_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_warranty_border_color">
                                    <option value="" <?php selected($war_border_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="none" <?php selected($war_border_val, 'none'); ?>><?php _e('No Border', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($war_border_val) && $war_border_val !== 'none', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_warranty_border_color"
                                       name="wcepi_archive_warranty_border_color"
                                       value="<?php echo esc_attr($war_border_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($war_border_val) || $war_border_val === 'none') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_warranty_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $war_icon_val = get_option('wcepi_archive_warranty_icon_color', ''); ?>
                                <select id="wcepi_archive_warranty_icon_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_warranty_icon_color">
                                    <option value="" <?php selected($war_icon_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($war_icon_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_warranty_icon_color"
                                       name="wcepi_archive_warranty_icon_color"
                                       value="<?php echo esc_attr($war_icon_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($war_icon_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <!-- Archive In Stock Badge -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('In Stock Badge (Listings)', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_in_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_in_bg_val = get_option('wcepi_archive_stock_in_bg_color', ''); ?>
                                <select id="wcepi_archive_stock_in_bg_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_in_bg_color">
                                    <option value="" <?php selected($stock_in_bg_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="transparent" <?php selected($stock_in_bg_val, 'transparent'); ?>><?php _e('Transparent (No Background)', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_in_bg_val) && $stock_in_bg_val !== 'transparent', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_in_bg_color"
                                       name="wcepi_archive_stock_in_bg_color"
                                       value="<?php echo esc_attr($stock_in_bg_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($stock_in_bg_val) || $stock_in_bg_val === 'transparent') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_in_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_in_text_val = get_option('wcepi_archive_stock_in_text_color', ''); ?>
                                <select id="wcepi_archive_stock_in_text_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_in_text_color">
                                    <option value="" <?php selected($stock_in_text_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_in_text_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_in_text_color"
                                       name="wcepi_archive_stock_in_text_color"
                                       value="<?php echo esc_attr($stock_in_text_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($stock_in_text_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_in_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_in_border_val = get_option('wcepi_archive_stock_in_border_color', ''); ?>
                                <select id="wcepi_archive_stock_in_border_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_in_border_color">
                                    <option value="" <?php selected($stock_in_border_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="none" <?php selected($stock_in_border_val, 'none'); ?>><?php _e('No Border', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_in_border_val) && $stock_in_border_val !== 'none', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_in_border_color"
                                       name="wcepi_archive_stock_in_border_color"
                                       value="<?php echo esc_attr($stock_in_border_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($stock_in_border_val) || $stock_in_border_val === 'none') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_in_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_in_icon_val = get_option('wcepi_archive_stock_in_icon_color', ''); ?>
                                <select id="wcepi_archive_stock_in_icon_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_in_icon_color">
                                    <option value="" <?php selected($stock_in_icon_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_in_icon_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_in_icon_color"
                                       name="wcepi_archive_stock_in_icon_color"
                                       value="<?php echo esc_attr($stock_in_icon_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($stock_in_icon_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <!-- Archive Out of Stock Badge -->
                        <tr>
                            <th colspan="2">
                                <h3 style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><?php _e('Out of Stock Badge (Listings)', 'wc-enhanced-product-info'); ?></h3>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_out_bg_color">
                                    <?php _e('Background Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_out_bg_val = get_option('wcepi_archive_stock_out_bg_color', ''); ?>
                                <select id="wcepi_archive_stock_out_bg_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_out_bg_color">
                                    <option value="" <?php selected($stock_out_bg_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="transparent" <?php selected($stock_out_bg_val, 'transparent'); ?>><?php _e('Transparent (No Background)', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_out_bg_val) && $stock_out_bg_val !== 'transparent', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_out_bg_color"
                                       name="wcepi_archive_stock_out_bg_color"
                                       value="<?php echo esc_attr($stock_out_bg_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($stock_out_bg_val) || $stock_out_bg_val === 'transparent') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_out_text_color">
                                    <?php _e('Text Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_out_text_val = get_option('wcepi_archive_stock_out_text_color', ''); ?>
                                <select id="wcepi_archive_stock_out_text_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_out_text_color">
                                    <option value="" <?php selected($stock_out_text_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_out_text_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_out_text_color"
                                       name="wcepi_archive_stock_out_text_color"
                                       value="<?php echo esc_attr($stock_out_text_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($stock_out_text_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_out_border_color">
                                    <?php _e('Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_out_border_val = get_option('wcepi_archive_stock_out_border_color', ''); ?>
                                <select id="wcepi_archive_stock_out_border_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_out_border_color">
                                    <option value="" <?php selected($stock_out_border_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="none" <?php selected($stock_out_border_val, 'none'); ?>><?php _e('No Border', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_out_border_val) && $stock_out_border_val !== 'none', true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_out_border_color"
                                       name="wcepi_archive_stock_out_border_color"
                                       value="<?php echo esc_attr($stock_out_border_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo (empty($stock_out_border_val) || $stock_out_border_val === 'none') ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_archive_stock_out_icon_color">
                                    <?php _e('Icon Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <?php $stock_out_icon_val = get_option('wcepi_archive_stock_out_icon_color', ''); ?>
                                <select id="wcepi_archive_stock_out_icon_color_select" class="wcepi-archive-color-select" data-target="wcepi_archive_stock_out_icon_color">
                                    <option value="" <?php selected($stock_out_icon_val, ''); ?>><?php _e('Use Product Page Styling', 'wc-enhanced-product-info'); ?></option>
                                    <option value="custom" <?php selected(!empty($stock_out_icon_val), true); ?>><?php _e('Custom Color', 'wc-enhanced-product-info'); ?></option>
                                </select>
                                <input type="text" id="wcepi_archive_stock_out_icon_color"
                                       name="wcepi_archive_stock_out_icon_color"
                                       value="<?php echo esc_attr($stock_out_icon_val); ?>"
                                       class="wcepi-color-picker wcepi-archive-color-input" data-default-color=""
                                       style="<?php echo empty($stock_out_icon_val) ? 'display:none;' : ''; ?>">
                            </td>
                        </tr>

                        <!-- End Product Listing Badge Styling Section -->
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($active_tab === 'labels'): ?>
                <!-- LABELS TAB -->
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Tab/Section Labels', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('Renames the tab/section headings shown to shoppers (e.g. "Specifications" → "Tech Specs"). Renaming never hides a section — sections only appear when they have content, and features are turned on/off in the General tab.', 'wc-enhanced-product-info')); ?></h2>
                                <p class="description"><?php _e('Customize the labels used for tabs and sections on product pages', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_description"><?php _e('Description Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_description" name="wcepi_label_description" value="<?php echo esc_attr(get_option('wcepi_label_description', 'Description')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_dimensions"><?php _e('Dimensions Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_dimensions" name="wcepi_label_dimensions" value="<?php echo esc_attr(get_option('wcepi_label_dimensions', 'Dimensions')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_specifications"><?php _e('Specifications Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_specifications" name="wcepi_label_specifications" value="<?php echo esc_attr(get_option('wcepi_label_specifications', 'Specifications')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_downloads"><?php _e('Downloads Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_downloads" name="wcepi_label_downloads" value="<?php echo esc_attr(get_option('wcepi_label_downloads', 'Downloads / Manuals')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_shipping_returns"><?php _e('Shipping Policy Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_shipping_returns" name="wcepi_label_shipping_returns" value="<?php echo esc_attr(get_option('wcepi_label_shipping_returns', 'Shipping Policy')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_returns"><?php _e('Returns Policy Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_returns" name="wcepi_label_returns" value="<?php echo esc_attr(get_option('wcepi_label_returns', 'Returns Policy')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_warranty"><?php _e('Warranty Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_warranty" name="wcepi_label_warranty" value="<?php echo esc_attr(get_option('wcepi_label_warranty', 'Warranty')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_label_faq"><?php _e('FAQ Label', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_label_faq" name="wcepi_label_faq" value="<?php echo esc_attr(get_option('wcepi_label_faq', 'FAQ')); ?>" class="regular-text"></td>
                        </tr>

                        <tr>
                            <th colspan="2">
                                <h2 style="margin-top: 20px;"><?php _e('Stock Status Text', 'wc-enhanced-product-info'); ?></h2>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_in_stock_text"><?php _e('In Stock Text', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_in_stock_text" name="wcepi_in_stock_text" value="<?php echo esc_attr(get_option('wcepi_in_stock_text', 'In Stock')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_out_of_stock_text"><?php _e('Out of Stock Text', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_out_of_stock_text" name="wcepi_out_of_stock_text" value="<?php echo esc_attr(get_option('wcepi_out_of_stock_text', 'Out of Stock')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcepi_free_shipping_text"><?php _e('Free Shipping Text', 'wc-enhanced-product-info'); ?></label></th>
                            <td><input type="text" id="wcepi_free_shipping_text" name="wcepi_free_shipping_text" value="<?php echo esc_attr(get_option('wcepi_free_shipping_text', 'Free Shipping')); ?>" class="regular-text"></td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($active_tab === 'schema'): ?>
                <!-- SCHEMA/SEO TAB -->
                <table class="form-table">
                    <tbody>
                        <!-- Schema/SEO Settings Section -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Schema / SEO Settings', 'wc-enhanced-product-info'); ?></h2>
                                <p class="description"><?php _e('Configure structured data (JSON-LD) for better search engine visibility. These settings enhance your product rich snippets in Google.', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_enable_product_schema">
                                    <?php _e('Enable Product Schema Output', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="wcepi_enable_product_schema"
                                       name="wcepi_enable_product_schema" value="yes"
                                       <?php checked(get_option('wcepi_enable_product_schema', 'yes'), 'yes'); ?>>
                                <p class="description">
                                    <?php _e('Output this plugin\'s Product JSON-LD (with warranty, shipping and returns details) on product pages. Turn this OFF if your SEO plugin (Yoast, Rank Math, etc.) already outputs Product schema, to avoid duplicate structured data.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_schema_brand">
                                    <?php _e('Default Brand Name', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_schema_brand"
                                       name="wcepi_schema_brand"
                                       value="<?php echo esc_attr(get_option('wcepi_schema_brand', '')); ?>"
                                       class="regular-text">
                                <?php echo $this->help_tip(__('Brand is looked up per product first: brand plugins (WooCommerce Brands, Perfect Brands, YITH), then a "brand" or "manufacturer" product attribute. This value is only the fallback when none of those exist.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Default brand for products without a brand attribute. Leave empty to omit brand from the schema.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_schema_shipping_country">
                                    <?php _e('Shipping Country', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_schema_shipping_country"
                                       name="wcepi_schema_shipping_country"
                                       value="<?php echo esc_attr(get_option('wcepi_schema_shipping_country', 'US')); ?>"
                                       class="small-text" maxlength="2">
                                <?php echo $this->help_tip(__('The country your shipping and returns details apply to in Google\'s eyes. Google Shopping uses this to show delivery and returns info to shoppers in that country.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('2-letter country code (e.g., US, CA, GB). Used for shipping and return policy schema.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_schema_shipping_cost">
                                    <?php _e('Default Shipping Cost', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_schema_shipping_cost"
                                       name="wcepi_schema_shipping_cost"
                                       value="<?php echo esc_attr(get_option('wcepi_schema_shipping_cost', '')); ?>"
                                       class="small-text" min="0" step="0.01">
                                <p class="description">
                                    <?php _e('Default shipping cost for products not marked as free shipping. Leave empty to omit from schema.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Transit Time (Days)', 'wc-enhanced-product-info'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <?php _e('Min:', 'wc-enhanced-product-info'); ?>
                                    <input type="number" name="wcepi_schema_transit_time_min"
                                           value="<?php echo esc_attr(get_option('wcepi_schema_transit_time_min', '3')); ?>"
                                           class="small-text" min="1" max="60">
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    <?php _e('Max:', 'wc-enhanced-product-info'); ?>
                                    <input type="number" name="wcepi_schema_transit_time_max"
                                           value="<?php echo esc_attr(get_option('wcepi_schema_transit_time_max', '7')); ?>"
                                           class="small-text" min="1" max="60">
                                </label>
                                <?php echo $this->help_tip(__('Transit = carrier time in the mail, after your handling time. Handling time comes from each product\'s "Ships in (days)" field. Google combines both into the delivery estimate shown in Shopping results.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Estimated shipping transit time range (after handling). Used in shippingDetails schema.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_schema_return_days">
                                    <?php _e('Return Window (Days)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_schema_return_days"
                                       name="wcepi_schema_return_days"
                                       value="<?php echo esc_attr(get_option('wcepi_schema_return_days', '30')); ?>"
                                       class="small-text" min="0" max="365">
                                <?php echo $this->help_tip(__('This only feeds the structured data Google reads — make sure it matches what your actual Returns Policy page says, or Google may flag the mismatch.', 'wc-enhanced-product-info')); ?>
                                <p class="description">
                                    <?php _e('Number of days customers can return products. Set to 0 to disable return policy schema.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_schema_return_fees">
                                    <?php _e('Return Shipping Fees', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="wcepi_schema_return_fees" name="wcepi_schema_return_fees">
                                    <option value="FreeReturn" <?php selected(get_option('wcepi_schema_return_fees', 'FreeReturn'), 'FreeReturn'); ?>>
                                        <?php _e('Free Returns', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="ReturnShippingFees" <?php selected(get_option('wcepi_schema_return_fees', 'FreeReturn'), 'ReturnShippingFees'); ?>>
                                        <?php _e('Customer Pays Return Shipping', 'wc-enhanced-product-info'); ?>
                                    </option>
                                    <option value="RestockingFees" <?php selected(get_option('wcepi_schema_return_fees', 'FreeReturn'), 'RestockingFees'); ?>>
                                        <?php _e('Restocking Fees Apply', 'wc-enhanced-product-info'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Who pays for return shipping? This appears in Google Shopping results.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        <!-- End Schema/SEO Settings Section -->
                    </tbody>
                </table>
                <?php endif; ?>

                <?php
                // Content styling and other settings moved to Styling tab - kept for backwards compatibility in save
                if ($active_tab === 'styling'):
                ?>
                <!-- Additional Styling Options (appended to Styling tab) -->
                <table class="form-table" style="margin-top: 0;">
                    <tbody>
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Content Styling', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('Applies to the text inside the product information sections (Description, Dimensions, Warranty, FAQ, etc.) in all three display modes — Tabs, Accordion, and Stacked.', 'wc-enhanced-product-info')); ?></h2>
                                <p class="description"><?php _e('Control the text size and spacing inside content sections', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_content_font_size">
                                    <?php _e('Content Font Size (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_content_font_size"
                                       name="wcepi_content_font_size"
                                       value="<?php echo esc_attr(get_option('wcepi_content_font_size', '15')); ?>"
                                       min="12" max="24" step="1" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 15px. Controls text size in content sections.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_content_padding_top">
                                    <?php _e('Content Top Padding (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_content_padding_top"
                                       name="wcepi_content_padding_top"
                                       value="<?php echo esc_attr(get_option('wcepi_content_padding_top', '10')); ?>"
                                       min="0" max="100" step="5" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 10px. Space above content.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_content_padding_bottom">
                                    <?php _e('Content Bottom Padding (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_content_padding_bottom"
                                       name="wcepi_content_padding_bottom"
                                       value="<?php echo esc_attr(get_option('wcepi_content_padding_bottom', '10')); ?>"
                                       min="0" max="100" step="5" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 10px. Space below content.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_content_padding_left">
                                    <?php _e('Content Left Padding (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_content_padding_left"
                                       name="wcepi_content_padding_left"
                                       value="<?php echo esc_attr(get_option('wcepi_content_padding_left', '0')); ?>"
                                       min="0" max="100" step="5" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 0px. Space on left side.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_content_padding_right">
                                    <?php _e('Content Right Padding (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_content_padding_right"
                                       name="wcepi_content_padding_right"
                                       value="<?php echo esc_attr(get_option('wcepi_content_padding_right', '0')); ?>"
                                       min="0" max="100" step="5" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 0px. Space on right side.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Table Styling', 'wc-enhanced-product-info'); ?><?php echo $this->help_tip(__('Applies to the Dimensions, Specifications, and custom section tables on product pages. Borders here mean the line between rows — side borders are removed for a clean, modern look.', 'wc-enhanced-product-info')); ?></h2>
                                <p class="description"><?php _e('Control the appearance of tables in Dimensions, Specifications, and Custom Sections', 'wc-enhanced-product-info'); ?></p>
                            </th>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_table_border_color">
                                    <?php _e('Table Border Color', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="wcepi_table_border_color"
                                       name="wcepi_table_border_color"
                                       value="<?php echo esc_attr(get_option('wcepi_table_border_color', '#eeeeee')); ?>"
                                       class="wcepi-color-picker">
                                <p class="description">
                                    <?php _e('Default: #eeeeee (light gray). Controls border color for table rows and columns.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wcepi_table_border_width">
                                    <?php _e('Table Border Width (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_table_border_width"
                                       name="wcepi_table_border_width"
                                       value="<?php echo esc_attr(get_option('wcepi_table_border_width', '1')); ?>"
                                       min="0" max="10" step="1" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 1px. Controls the thickness of table borders.', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_table_cell_padding">
                                    <?php _e('Table Cell Padding (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_table_cell_padding"
                                       name="wcepi_table_cell_padding"
                                       value="<?php echo esc_attr(get_option('wcepi_table_cell_padding', '12')); ?>"
                                       min="0" max="50" step="1" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 12px. Controls the padding inside table cells (Dimensions, Specifications, etc.).', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="wcepi_table_margin_bottom">
                                    <?php _e('Table Bottom Margin (px)', 'wc-enhanced-product-info'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" id="wcepi_table_margin_bottom"
                                       name="wcepi_table_margin_bottom"
                                       value="<?php echo esc_attr(get_option('wcepi_table_margin_bottom', '20')); ?>"
                                       min="0" max="100" step="1" class="small-text">
                                <p class="description">
                                    <?php _e('Default: 20px. Controls the spacing below tables (space between table and next section).', 'wc-enhanced-product-info'); ?>
                                </p>
                            </td>
                        </tr>

                    </tbody>
                </table>
                <?php endif; ?>

                <p class="submit">
                    <input type="submit" name="wcepi_save_settings" class="button button-primary"
                           value="<?php _e('Save Settings', 'wc-enhanced-product-info'); ?>">
                </p>
            </form>

            <!-- Tab Navigation JavaScript (prevent leave page prompt) -->
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var $form = $('#wcepi-settings-form');
                var initialFormState = '';
                var isSubmitting = false;

                // Store initial form state after a short delay to let color pickers etc initialize
                setTimeout(function() {
                    initialFormState = $form.serialize();
                }, 150);

                // Check if form has actually changed
                function hasFormChanged() {
                    return initialFormState !== '' && $form.serialize() !== initialFormState;
                }

                // When form is submitted, disable the beforeunload check
                $form.on('submit', function() {
                    isSubmitting = true;
                    $(window).off('beforeunload');
                });

                // Handle tab clicks
                $('.wcepi-nav-tabs .nav-tab').on('click', function(e) {
                    if (hasFormChanged()) {
                        if (!confirm('<?php echo esc_js(__('You have unsaved changes. Click OK to discard them and switch tabs, or Cancel to stay on this page.', 'wc-enhanced-product-info')); ?>')) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    // Allow navigation - remove beforeunload handler
                    $(window).off('beforeunload');
                });

                // Prevent browser's default "leave page" prompt
                $(window).on('beforeunload', function() {
                    if (!isSubmitting && hasFormChanged()) {
                        return '<?php echo esc_js(__('You have unsaved changes.', 'wc-enhanced-product-info')); ?>';
                    }
                });
            });
            </script>

            <!-- Icon Preview JavaScript (only on Styling tab) -->
            <?php if ($active_tab === 'styling'): ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Icon SVG definitions
                var warrantyIcons = {
                    'checkbox-square': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="1" y="1" width="22" height="22" rx="4" fill="#2563eb"/><path d="M7 12.5L10.5 16L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'checkbox-circle': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#2563eb"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'shield': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="#2563eb"/></svg>',
                    'shield-check': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="#2563eb"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'badge': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="#2563eb"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'star': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="#2563eb"/></svg>',
                    'award': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="6" fill="#2563eb"/><path d="M8.21 13.89L7 23l5-3 5 3-1.21-9.12" fill="#2563eb"/><path d="M9 8l1.5 1.5L14 6" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'certificate': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="14" rx="2" fill="#2563eb"/><path d="M7 9h10M7 12h6" stroke="white" stroke-width="1.5" stroke-linecap="round"/><circle cx="16" cy="18" r="3" fill="#2563eb" stroke="white" stroke-width="1"/></svg>',
                    'thumbs-up': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" fill="#2563eb"/></svg>',
                    'verified': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.4 2.4h3.4v3.4L20 10l-2 2.2v3.4h-3.4L12 18l-2.4-2.4H6.2v-3.4L4 10l2-2.2V4.4h3.4L12 2z" fill="#2563eb"/><path d="M9 10l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'none': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#ccc" stroke-width="2" stroke-dasharray="4 4"/><text x="12" y="16" text-anchor="middle" font-size="10" fill="#999">—</text></svg>'
                };

                var inStockIcons = {
                    'circle-check': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#16a34a"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'square-check': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="1" y="1" width="22" height="22" rx="4" fill="#16a34a"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'checkmark-only': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 12l5.5 5.5L20 7" stroke="#16a34a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'dot': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="6" fill="#16a34a"/></svg>',
                    'truck': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M16 3H1v13h15V3z" fill="#16a34a"/><path d="M16 8h4l3 3v5h-7V8z" fill="#16a34a"/><circle cx="5.5" cy="18.5" r="2.5" fill="#16a34a" stroke="white" stroke-width="1"/><circle cx="18.5" cy="18.5" r="2.5" fill="#16a34a" stroke="white" stroke-width="1"/></svg>',
                    'box': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" fill="#16a34a"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="white" stroke-width="1.5"/></svg>',
                    'warehouse': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M22 20V8l-10-6L2 8v12h20z" fill="#16a34a"/><rect x="6" y="12" width="4" height="8" fill="white"/><rect x="14" y="12" width="4" height="8" fill="white"/></svg>',
                    'lightning': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="#16a34a"/></svg>',
                    'clock': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#16a34a"/><path d="M12 6v6l4 2" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                    'thumbs-up': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" fill="#16a34a"/></svg>',
                    'none': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#ccc" stroke-width="2" stroke-dasharray="4 4"/><text x="12" y="16" text-anchor="middle" font-size="10" fill="#999">—</text></svg>'
                };

                var outOfStockIcons = {
                    'circle-x': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#dc2626"/><path d="M8 8l8 8M16 8l-8 8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'square-x': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="1" y="1" width="22" height="22" rx="4" fill="#dc2626"/><path d="M8 8l8 8M16 8l-8 8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'x-only': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6l-12 12" stroke="#dc2626" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'dot': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="6" fill="#dc2626"/></svg>',
                    'clock': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#dc2626"/><path d="M12 6v6l4 2" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                    'calendar': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" fill="#dc2626"/><path d="M16 2v4M8 2v4M3 10h18" stroke="white" stroke-width="1.5"/><path d="M8 14h2v2H8zM14 14h2v2h-2z" fill="white"/></svg>',
                    'alert': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" fill="#dc2626"/><path d="M12 9v4M12 17h.01" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>',
                    'ban': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#dc2626"/><path d="M4.93 4.93l14.14 14.14" stroke="white" stroke-width="2"/></svg>',
                    'none': '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#ccc" stroke-width="2" stroke-dasharray="4 4"/><text x="12" y="16" text-anchor="middle" font-size="10" fill="#999">—</text></svg>'
                };

                // Function to update icon preview
                function updateIconPreview(selectId, previewId, iconType) {
                    var value = $('#' + selectId).val();
                    var icons;

                    if (iconType === 'in-stock') {
                        icons = inStockIcons;
                    } else if (iconType === 'out-of-stock') {
                        icons = outOfStockIcons;
                    } else {
                        icons = warrantyIcons;
                    }

                    if (icons[value]) {
                        $('#' + previewId).html(icons[value]);
                    } else {
                        $('#' + previewId).html('');
                    }
                }

                // Initialize previews on page load
                updateIconPreview('wcepi_warranty_badge_icon_type', 'warranty-icon-preview', 'warranty');
                updateIconPreview('wcepi_stock_badge_in_stock_icon_type', 'in-stock-icon-preview', 'in-stock');
                updateIconPreview('wcepi_stock_badge_out_of_stock_icon_type', 'out-of-stock-icon-preview', 'out-of-stock');

                // Update previews on change
                $('#wcepi_warranty_badge_icon_type').on('change', function() {
                    updateIconPreview('wcepi_warranty_badge_icon_type', 'warranty-icon-preview', 'warranty');
                });

                $('#wcepi_stock_badge_in_stock_icon_type').on('change', function() {
                    updateIconPreview('wcepi_stock_badge_in_stock_icon_type', 'in-stock-icon-preview', 'in-stock');
                });

                $('#wcepi_stock_badge_out_of_stock_icon_type').on('change', function() {
                    updateIconPreview('wcepi_stock_badge_out_of_stock_icon_type', 'out-of-stock-icon-preview', 'out-of-stock');
                });

                // =============================================
                // LIVE BADGE PREVIEWS
                // Mirror the frontend badge markup/styles and update as
                // settings change (reuses the icon SVG maps above).
                // =============================================

                function wcepiVal(id, fallback) {
                    var $el = $('#' + id);
                    if (!$el.length) {
                        return fallback;
                    }
                    var v = $el.val();
                    return (v === '' || v === null || typeof v === 'undefined') ? fallback : v;
                }

                function wcepiRadius(shape) {
                    if (shape === 'slightly-rounded') { return '6px'; }
                    if (shape === 'squared') { return '0'; }
                    return '20px';
                }

                // The icon maps bake in a base color; swap it for the chosen one
                function wcepiIcon(icons, type, baseColor, newColor, size) {
                    if (!type || type === 'none' || !icons[type]) {
                        return null;
                    }
                    var $icon = $('<span/>').css({
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'flex-shrink': '0'
                    }).html(icons[type].split(baseColor).join(newColor));
                    $icon.find('svg').attr({ width: size, height: size });
                    return $icon;
                }

                function wcepiBorderCss(border, bg, defaultBorder) {
                    if (border) {
                        return '1px solid ' + border;
                    }
                    if (bg && bg !== 'transparent' && bg !== '#f8f9fa') {
                        return '1px solid transparent';
                    }
                    return '1px solid ' + defaultBorder;
                }

                function renderFreeShippingPreview() {
                    var $stage = $('#wcepi-preview-free-shipping');
                    if (!$stage.length) { return; }

                    var border = wcepiVal('wcepi_free_shipping_border_color', '');
                    var $badge = $('<span/>').text($stage.data('text') || 'Free Shipping').css({
                        'display': 'inline-block',
                        'background': wcepiVal('wcepi_free_shipping_bg_color', '#4CAF50'),
                        'color': wcepiVal('wcepi_free_shipping_text_color', '#ffffff'),
                        'padding': '5px 15px',
                        'border-radius': '3px',
                        'font-size': '14px',
                        'font-weight': '600',
                        'text-transform': 'uppercase',
                        'letter-spacing': '0.5px',
                        'border': border ? '1px solid ' + border : 'none'
                    });
                    $stage.empty().append($badge);
                }

                function renderWarrantyPreview() {
                    var $stage = $('#wcepi-preview-warranty');
                    if (!$stage.length) { return; }

                    var bg = wcepiVal('wcepi_warranty_badge_bg_color', '#f8f9fa');
                    var $badge = $('<span/>').css({
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'gap': '8px',
                        'padding': '8px 14px',
                        'background': bg,
                        'color': wcepiVal('wcepi_warranty_badge_text_color', '#1a1a1a'),
                        'border': wcepiBorderCss(wcepiVal('wcepi_warranty_badge_border_color', ''), bg, '#e5e7eb'),
                        'border-radius': wcepiRadius(wcepiVal('wcepi_badges_shape', 'rounded')),
                        'font-size': (parseInt(wcepiVal('wcepi_warranty_badge_font_size', '14'), 10) || 14) + 'px',
                        'font-weight': parseInt(wcepiVal('wcepi_warranty_badge_font_weight', '500'), 10) || 500,
                        'line-height': '1.4'
                    });

                    var $icon = wcepiIcon(warrantyIcons, wcepiVal('wcepi_warranty_badge_icon_type', 'checkbox-square'), '#2563eb', wcepiVal('wcepi_warranty_badge_icon_color', '#2563eb'), 18);
                    if ($icon) { $badge.append($icon); }
                    $badge.append($('<span/>').text($stage.data('text')));
                    $stage.empty().append($badge);
                }

                function renderStockPreview(inStock) {
                    var $stage = $(inStock ? '#wcepi-preview-stock-in' : '#wcepi-preview-stock-out');
                    if (!$stage.length) { return; }

                    var prefix = inStock ? 'wcepi_stock_badge_in_stock_' : 'wcepi_stock_badge_out_of_stock_';
                    var bg = wcepiVal(prefix + 'bg_color', '#f8f9fa');
                    var icons = inStock ? inStockIcons : outOfStockIcons;
                    var baseIconColor = inStock ? '#16a34a' : '#dc2626';

                    var $badge = $('<span/>').css({
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'gap': '8px',
                        'padding': '8px 14px',
                        'background': bg,
                        'color': wcepiVal(prefix + 'text_color', inStock ? '#1a1a1a' : '#dc2626'),
                        'border': wcepiBorderCss(wcepiVal(prefix + 'border_color', ''), bg, '#e5e7eb'),
                        'border-radius': wcepiRadius(wcepiVal('wcepi_badges_shape', 'rounded')),
                        'font-size': (parseInt(wcepiVal('wcepi_stock_badge_font_size', '14'), 10) || 14) + 'px',
                        'font-weight': parseInt(wcepiVal('wcepi_stock_badge_font_weight', '500'), 10) || 500,
                        'line-height': '1.4'
                    });

                    var $icon = wcepiIcon(icons, wcepiVal(prefix + 'icon_type', inStock ? 'circle-check' : 'circle-x'), baseIconColor, wcepiVal(prefix + 'icon_color', baseIconColor), 18);
                    if ($icon) { $badge.append($icon); }
                    $badge.append($('<span/>').text($stage.data('text')));
                    $stage.empty().append($badge);
                }

                function renderArchivePreview() {
                    var $stage = $('#wcepi-preview-archive');
                    if (!$stage.length) { return; }

                    var fontSize = (parseInt(wcepiVal('wcepi_archive_badge_font_size', '12'), 10) || 12) + 'px';
                    var padding = wcepiVal('wcepi_archive_badge_padding', '4px 10px');
                    var radius = wcepiRadius(wcepiVal('wcepi_archive_badge_shape', '') || wcepiVal('wcepi_badges_shape', 'rounded'));

                    function miniBadge(bg, color, border, $icon, text) {
                        var $b = $('<span/>').css({
                            'display': 'inline-flex',
                            'align-items': 'center',
                            'gap': '4px',
                            'font-size': fontSize,
                            'padding': padding,
                            'border-radius': radius,
                            'line-height': '1.3',
                            'background': bg,
                            'color': color,
                            'border': border ? '1px solid ' + border : 'none'
                        });
                        if ($icon) { $b.append($icon); }
                        $b.append($('<span/>').text(text));
                        return $b;
                    }

                    // Empty archive value = inherit the product page setting
                    function archOr(archiveId, productValue) {
                        var v = $('#' + archiveId).val();
                        return v ? v : productValue;
                    }

                    $stage.empty();

                    // Free shipping
                    $stage.append(miniBadge(
                        archOr('wcepi_archive_free_shipping_bg_color', wcepiVal('wcepi_free_shipping_bg_color', '#4CAF50')),
                        archOr('wcepi_archive_free_shipping_text_color', wcepiVal('wcepi_free_shipping_text_color', '#ffffff')),
                        archOr('wcepi_archive_free_shipping_border_color', wcepiVal('wcepi_free_shipping_border_color', '')),
                        null,
                        $stage.data('fs-text')
                    ));

                    // Warranty
                    $stage.append(miniBadge(
                        archOr('wcepi_archive_warranty_bg_color', wcepiVal('wcepi_warranty_badge_bg_color', '#f8f9fa')),
                        archOr('wcepi_archive_warranty_text_color', wcepiVal('wcepi_warranty_badge_text_color', '#1a1a1a')),
                        archOr('wcepi_archive_warranty_border_color', wcepiVal('wcepi_warranty_badge_border_color', '#e5e7eb')),
                        wcepiIcon(warrantyIcons, wcepiVal('wcepi_warranty_badge_icon_type', 'checkbox-square'), '#2563eb', archOr('wcepi_archive_warranty_icon_color', wcepiVal('wcepi_warranty_badge_icon_color', '#2563eb')), 14),
                        $stage.data('warranty-text')
                    ));

                    // In stock
                    $stage.append(miniBadge(
                        archOr('wcepi_archive_stock_in_bg_color', wcepiVal('wcepi_stock_badge_in_stock_bg_color', '#f8f9fa')),
                        archOr('wcepi_archive_stock_in_text_color', wcepiVal('wcepi_stock_badge_in_stock_text_color', '#1a1a1a')),
                        archOr('wcepi_archive_stock_in_border_color', wcepiVal('wcepi_stock_badge_in_stock_border_color', '#e5e7eb')),
                        wcepiIcon(inStockIcons, wcepiVal('wcepi_stock_badge_in_stock_icon_type', 'circle-check'), '#16a34a', archOr('wcepi_archive_stock_in_icon_color', wcepiVal('wcepi_stock_badge_in_stock_icon_color', '#16a34a')), 12),
                        $stage.data('in-text')
                    ));

                    // Out of stock
                    $stage.append(miniBadge(
                        archOr('wcepi_archive_stock_out_bg_color', wcepiVal('wcepi_stock_badge_out_of_stock_bg_color', '#f8f9fa')),
                        archOr('wcepi_archive_stock_out_text_color', wcepiVal('wcepi_stock_badge_out_of_stock_text_color', '#dc2626')),
                        archOr('wcepi_archive_stock_out_border_color', wcepiVal('wcepi_stock_badge_out_of_stock_border_color', '#e5e7eb')),
                        wcepiIcon(outOfStockIcons, wcepiVal('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x'), '#dc2626', archOr('wcepi_archive_stock_out_icon_color', wcepiVal('wcepi_stock_badge_out_of_stock_icon_color', '#dc2626')), 12),
                        $stage.data('out-text')
                    ));
                }

                function updateAllBadgePreviews() {
                    renderFreeShippingPreview();
                    renderWarrantyPreview();
                    renderStockPreview(true);
                    renderStockPreview(false);
                    renderArchivePreview();
                }

                // Initial render (delayed slightly so color pickers finish initializing)
                updateAllBadgePreviews();
                setTimeout(updateAllBadgePreviews, 200);

                // Re-render on any setting change (color pickers trigger
                // 'change' on their inputs while dragging)
                $('#wcepi-settings-form').on('change input', 'input, select', updateAllBadgePreviews);

                // Archive Color Select Handler - show/hide color picker based on dropdown selection
                $('.wcepi-archive-color-select').on('change', function() {
                    var selectedValue = $(this).val();
                    var targetId = $(this).data('target');
                    var $colorInput = $('#' + targetId);
                    var $colorPickerWrapper = $colorInput.closest('.wp-picker-container');

                    if (selectedValue === 'custom') {
                        // Show color picker
                        if ($colorPickerWrapper.length) {
                            $colorPickerWrapper.show();
                        } else {
                            $colorInput.show();
                        }
                    } else if (selectedValue === 'transparent' || selectedValue === 'none') {
                        // Hide color picker and set value to the special keyword
                        if ($colorPickerWrapper.length) {
                            $colorPickerWrapper.hide();
                        } else {
                            $colorInput.hide();
                        }
                        $colorInput.val(selectedValue);
                    } else {
                        // Empty - use product page styling
                        if ($colorPickerWrapper.length) {
                            $colorPickerWrapper.hide();
                        } else {
                            $colorInput.hide();
                        }
                        $colorInput.val('');
                    }
                });

                // Initialize archive color selects on page load (handle wp-color-picker wrapper)
                setTimeout(function() {
                    $('.wcepi-archive-color-select').each(function() {
                        var selectedValue = $(this).val();
                        var targetId = $(this).data('target');
                        var $colorInput = $('#' + targetId);
                        var $colorPickerWrapper = $colorInput.closest('.wp-picker-container');

                        if (selectedValue !== 'custom') {
                            if ($colorPickerWrapper.length) {
                                $colorPickerWrapper.hide();
                            }
                        }
                    });
                }, 200);
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        // Get current tab to only save settings for that tab
        $current_tab = isset($_POST['wcepi_current_tab']) ? sanitize_text_field($_POST['wcepi_current_tab']) : 'general';

        // Define fields by tab
        $tab_fields = array(
            'general' => array(
                'checkboxes' => array(
                    'wcepi_enable_free_shipping_badge',
                    'wcepi_enable_stock_status',
                    'wcepi_enable_custom_dimensions',
                    'wcepi_sync_dimensions_to_attributes',
                    'wcepi_enable_specifications',
                    'wcepi_sync_specs_to_attributes',
                    'wcepi_enable_downloads',
                    'wcepi_enable_shipping_returns',
                    'wcepi_enable_returns',
                    'wcepi_enable_warranty',
                    'wcepi_enable_faq',
                    'wcepi_enable_custom_sections',
                    'wcepi_enable_payment_methods',
                    'wcepi_archive_show_free_shipping',
                    'wcepi_archive_show_warranty',
                    'wcepi_archive_show_ships_in',
                    'wcepi_archive_show_stock',
                    'wcepi_archive_show_custom_badges'
                ),
                'text' => array(
                    'wcepi_display_mode',
                    'wcepi_accordion_default_open',
                    'wcepi_free_shipping_text',
                    'wcepi_in_stock_text',
                    'wcepi_out_of_stock_text',
                    'wcepi_ships_in_text'
                ),
                'colors' => array(),
                'special' => array('payment_methods', 'shipping_returns_content', 'returns_content')
            ),
            'layout' => array(
                'checkboxes' => array(),
                'text' => array(
                    'wcepi_badges_inline_layout'
                ),
                'colors' => array(),
                'special' => array('tab_order', 'badges_order', 'badges_positions', 'custom_badges')
            ),
            'styling' => array(
                'checkboxes' => array(),
                'text' => array(
                    'wcepi_badges_shape',
                    'wcepi_warranty_badge_icon_type',
                    'wcepi_warranty_badge_font_size',
                    'wcepi_warranty_badge_font_weight',
                    'wcepi_stock_badge_icon_type',
                    'wcepi_stock_badge_in_stock_icon_type',
                    'wcepi_stock_badge_out_of_stock_icon_type',
                    'wcepi_stock_badge_font_size',
                    'wcepi_stock_badge_font_weight',
                    'wcepi_content_font_size',
                    'wcepi_content_padding_top',
                    'wcepi_content_padding_bottom',
                    'wcepi_content_padding_left',
                    'wcepi_content_padding_right',
                    'wcepi_payment_icon_size_desktop',
                    'wcepi_payment_icon_size_tablet',
                    'wcepi_payment_icon_size_mobile',
                    'wcepi_table_border_width',
                    'wcepi_table_cell_padding',
                    'wcepi_table_margin_bottom',
                    'wcepi_archive_badge_shape',
                    'wcepi_archive_badge_font_size',
                    'wcepi_archive_badge_padding',
                    'wcepi_archive_badge_border_radius'
                ),
                'colors' => array(
                    'wcepi_free_shipping_bg_color',
                    'wcepi_free_shipping_text_color',
                    'wcepi_free_shipping_border_color',
                    'wcepi_warranty_badge_bg_color',
                    'wcepi_warranty_badge_text_color',
                    'wcepi_warranty_badge_border_color',
                    'wcepi_warranty_badge_icon_color',
                    'wcepi_stock_badge_in_stock_bg_color',
                    'wcepi_stock_badge_in_stock_text_color',
                    'wcepi_stock_badge_in_stock_icon_color',
                    'wcepi_stock_badge_in_stock_border_color',
                    'wcepi_stock_badge_out_of_stock_bg_color',
                    'wcepi_stock_badge_out_of_stock_text_color',
                    'wcepi_stock_badge_out_of_stock_icon_color',
                    'wcepi_stock_badge_out_of_stock_border_color',
                    'wcepi_ships_in_bg_color',
                    'wcepi_ships_in_text_color',
                    'wcepi_ships_in_icon_color',
                    'wcepi_table_border_color',
                    'wcepi_archive_free_shipping_bg_color',
                    'wcepi_archive_free_shipping_text_color',
                    'wcepi_archive_free_shipping_border_color',
                    'wcepi_archive_warranty_bg_color',
                    'wcepi_archive_warranty_text_color',
                    'wcepi_archive_warranty_border_color',
                    'wcepi_archive_warranty_icon_color',
                    'wcepi_archive_stock_in_bg_color',
                    'wcepi_archive_stock_in_text_color',
                    'wcepi_archive_stock_in_border_color',
                    'wcepi_archive_stock_in_icon_color',
                    'wcepi_archive_stock_out_bg_color',
                    'wcepi_archive_stock_out_text_color',
                    'wcepi_archive_stock_out_border_color',
                    'wcepi_archive_stock_out_icon_color',
                    'wcepi_archive_ships_in_bg_color',
                    'wcepi_archive_ships_in_text_color',
                    'wcepi_archive_ships_in_border_color',
                    'wcepi_archive_ships_in_icon_color'
                ),
                'special' => array()
            ),
            'labels' => array(
                'checkboxes' => array(),
                'text' => array(
                    'wcepi_label_description',
                    'wcepi_label_dimensions',
                    'wcepi_label_specifications',
                    'wcepi_label_downloads',
                    'wcepi_label_shipping_returns',
                    'wcepi_label_returns',
                    'wcepi_label_warranty',
                    'wcepi_label_faq'
                ),
                'colors' => array(),
                'special' => array()
            ),
            'schema' => array(
                'checkboxes' => array(
                    'wcepi_enable_product_schema'
                ),
                'text' => array(
                    'wcepi_schema_brand',
                    'wcepi_schema_shipping_country',
                    'wcepi_schema_shipping_cost',
                    'wcepi_schema_transit_time_min',
                    'wcepi_schema_transit_time_max',
                    'wcepi_schema_return_days',
                    'wcepi_schema_return_fees'
                ),
                'colors' => array(),
                'special' => array()
            )
        );

        // Get fields for current tab
        $fields = isset($tab_fields[$current_tab]) ? $tab_fields[$current_tab] : array();

        // Process checkbox fields for current tab only
        if (!empty($fields['checkboxes'])) {
            foreach ($fields['checkboxes'] as $field) {
                update_option($field, isset($_POST[$field]) ? 'yes' : 'no');
            }
        }

        // Process text fields for current tab only
        if (!empty($fields['text'])) {
            foreach ($fields['text'] as $field) {
                if (isset($_POST[$field])) {
                    $value = sanitize_text_field($_POST[$field]);

                    // Fields injected into CSS must be a strict CSS length list (e.g. "4px 10px")
                    if ($field === 'wcepi_archive_badge_padding' && $value !== '' && !preg_match('/^(\d+(\.\d+)?(px|em|rem|%)?\s*){1,4}$/', $value)) {
                        continue;
                    }

                    update_option($field, $value);
                }
            }
        }

        // Process color fields for current tab only
        if (!empty($fields['colors'])) {
            foreach ($fields['colors'] as $field) {
                if (isset($_POST[$field])) {
                    $color = sanitize_text_field($_POST[$field]);
                    if (empty($color)) {
                        update_option($field, '');
                    } elseif ($color === 'none' || $color === 'transparent') {
                        // Allow special values for archive badge styling
                        update_option($field, $color);
                    } else {
                        if (!empty($color) && $color[0] !== '#') {
                            $color = '#' . $color;
                        }
                        if (preg_match('/^#[a-f0-9]{6}$/i', $color)) {
                            update_option($field, $color);
                        }
                    }
                }
            }
        }

        // Process special fields based on current tab
        if (!empty($fields['special'])) {
            foreach ($fields['special'] as $special_type) {
                switch ($special_type) {
                    case 'payment_methods':
                        if (isset($_POST['wcepi_payment_methods']) && is_array($_POST['wcepi_payment_methods'])) {
                            $payment_methods = array_map('sanitize_text_field', $_POST['wcepi_payment_methods']);
                            update_option('wcepi_payment_methods', $payment_methods);
                        } else {
                            update_option('wcepi_payment_methods', array());
                        }
                        // Handle custom payment methods
                        if (isset($_POST['wcepi_custom_payment_methods']) && is_array($_POST['wcepi_custom_payment_methods'])) {
                            $custom_payment_methods = array();
                            foreach ($_POST['wcepi_custom_payment_methods'] as $method) {
                                if (!empty($method['name']) && !empty($method['image'])) {
                                    $custom_payment_methods[] = array(
                                        'name' => sanitize_text_field($method['name']),
                                        'image' => esc_url_raw($method['image'])
                                    );
                                }
                            }
                            update_option('wcepi_custom_payment_methods', $custom_payment_methods);
                        } else {
                            update_option('wcepi_custom_payment_methods', array());
                        }
                        break;
                    case 'shipping_returns_content':
                        if (isset($_POST['wcepi_shipping_returns_content'])) {
                            update_option('wcepi_shipping_returns_content', wp_kses_post($_POST['wcepi_shipping_returns_content']));
                        }
                        break;
                    case 'returns_content':
                        if (isset($_POST['wcepi_returns_content'])) {
                            update_option('wcepi_returns_content', wp_kses_post($_POST['wcepi_returns_content']));
                        }
                        break;
                    case 'tab_order':
                        if (isset($_POST['wcepi_tab_order']) && is_array($_POST['wcepi_tab_order'])) {
                            $tab_order = array();
                            foreach ($_POST['wcepi_tab_order'] as $tab_key => $priority) {
                                $tab_order[sanitize_key($tab_key)] = absint($priority);
                            }
                            update_option('wcepi_tab_order', $tab_order);
                        }
                        break;
                    case 'badges_order':
                        if (isset($_POST['wcepi_badges_order']) && is_array($_POST['wcepi_badges_order'])) {
                            $badges_order = array();
                            foreach ($_POST['wcepi_badges_order'] as $badge_key => $priority) {
                                $badges_order[sanitize_key($badge_key)] = absint($priority);
                            }
                            update_option('wcepi_badges_order', $badges_order);
                        }
                        break;
                    case 'badges_positions':
                        if (isset($_POST['wcepi_badges_positions']) && is_array($_POST['wcepi_badges_positions'])) {
                            $badges_positions = array();
                            foreach ($_POST['wcepi_badges_positions'] as $badge_key => $position) {
                                $sanitized_position = in_array($position, array('above', 'below', 'next_to_price')) ? $position : 'above';
                                $badges_positions[sanitize_key($badge_key)] = $sanitized_position;
                            }
                            update_option('wcepi_badges_positions', $badges_positions);
                        }
                        break;
                    case 'custom_badges':
                        if (isset($_POST['wcepi_custom_badges']) && is_array($_POST['wcepi_custom_badges'])) {
                            $custom_badges = array();
                            foreach ($_POST['wcepi_custom_badges'] as $badge_id => $badge_data) {
                                $sanitized_id = sanitize_key($badge_id);
                                // Only save if there's a label
                                if (!empty($badge_data['label'])) {
                                    $custom_badges[$sanitized_id] = array(
                                        'id' => $sanitized_id,
                                        'label' => sanitize_text_field($badge_data['label']),
                                        'icon' => sanitize_text_field($badge_data['icon'] ?? 'shield-check'),
                                        'bg_color' => sanitize_text_field($badge_data['bg_color'] ?? 'transparent'),
                                        'text_color' => sanitize_hex_color($badge_data['text_color'] ?? '#1a1a1a') ?: '#1a1a1a',
                                        'icon_color' => sanitize_hex_color($badge_data['icon_color'] ?? '#2563eb') ?: '#2563eb',
                                        'border_color' => !empty($badge_data['border_color']) ? sanitize_hex_color($badge_data['border_color']) : '',
                                        'enabled' => isset($badge_data['enabled']) ? 'yes' : 'no'
                                    );
                                }
                            }
                            update_option('wcepi_custom_badges', $custom_badges);
                        } else {
                            // No custom badges submitted, clear the option
                            update_option('wcepi_custom_badges', array());
                        }
                        break;
                }
            }
        }
    }

    /**
     * AJAX handler for bulk syncing existing products to attributes
     */
    public function ajax_bulk_sync_attributes() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcepi_bulk_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'wc-enhanced-product-info')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-enhanced-product-info')));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10;

        // Get sync options from the AJAX request (current checkbox state on the page)
        $sync_specs = isset($_POST['sync_specs']) && $_POST['sync_specs'] === 'yes';
        $sync_dims = isset($_POST['sync_dims']) && $_POST['sync_dims'] === 'yes';

        if (!$sync_specs && !$sync_dims) {
            wp_send_json_error(array('message' => __('Please enable at least one sync option first.', 'wc-enhanced-product-info')));
        }

        // Also save the options to the database so future product saves will sync
        if ($sync_specs) {
            update_option('wcepi_sync_specs_to_attributes', 'yes');
        }
        if ($sync_dims) {
            update_option('wcepi_sync_dimensions_to_attributes', 'yes');
        }

        // Get total count on first request
        if ($offset === 0) {
            $total_args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key' => '_wcepi_specifications',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_wcepi_custom_dimensions',
                        'compare' => 'EXISTS'
                    )
                )
            );
            $total_query = new WP_Query($total_args);
            $total = $total_query->found_posts;
        } else {
            $total = isset($_POST['total']) ? intval($_POST['total']) : 0;
        }

        // Get batch of products
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_wcepi_specifications',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_wcepi_custom_dimensions',
                    'compare' => 'EXISTS'
                )
            )
        );

        $query = new WP_Query($args);
        $product_ids = $query->posts;
        $processed = 0;

        $meta_boxes = WCEPI_Meta_Boxes::get_instance();

        foreach ($product_ids as $product_id) {
            // Sync specifications
            if ($sync_specs) {
                $specifications = get_post_meta($product_id, '_wcepi_specifications', true);
                if (!empty($specifications) && is_array($specifications)) {
                    $meta_boxes->sync_to_wc_attributes_public($product_id, 'spec', $specifications);
                }
            }

            // Sync dimensions
            if ($sync_dims) {
                $dimensions = get_post_meta($product_id, '_wcepi_custom_dimensions', true);
                if (!empty($dimensions) && is_array($dimensions)) {
                    $meta_boxes->sync_to_wc_attributes_public($product_id, 'dim', $dimensions);
                }
            }

            $processed++;
        }

        $new_offset = $offset + $processed;
        $done = $new_offset >= $total || empty($product_ids);

        wp_send_json_success(array(
            'processed' => $processed,
            'offset' => $new_offset,
            'total' => $total,
            'done' => $done,
            'message' => $done
                ? sprintf(__('Completed! Synced %d products.', 'wc-enhanced-product-info'), $new_offset)
                : sprintf(__('Processing... %d of %d products', 'wc-enhanced-product-info'), $new_offset, $total)
        ));
    }
}