<?php
/**
 * Frontend Class
 *
 * @package WC_Enhanced_Product_Info
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCEPI_Frontend {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Free shipping badge - display right after price
        add_filter('woocommerce_get_price_html', array($this, 'add_free_shipping_to_price'), 100, 2);
        
        // Warranty info after free shipping (if enabled)
        add_filter('woocommerce_get_price_html', array($this, 'add_warranty_to_price'), 110, 2);

        // Stock badge for archive pages (includes "Ships in X Days" when enabled)
        add_filter('woocommerce_get_price_html', array($this, 'add_stock_to_price_archive'), 130, 2);

        // Custom badges for archive pages
        add_filter('woocommerce_get_price_html', array($this, 'add_custom_badges_to_price_archive'), 140, 2);
        
        // Stock status - use woocommerce_get_stock_html for full HTML control
        // (woocommerce_stock_html was removed in WooCommerce 3.0)
        add_filter('woocommerce_get_stock_html', array($this, 'custom_stock_html'), 10, 2);
        
        // Remove default WooCommerce description and tabs output
        add_action('wp', array($this, 'remove_woocommerce_description_output'));
        
        // Replace default tabs or add content
        add_filter('woocommerce_product_tabs', array($this, 'customize_product_tabs'), 98);
        
        // Add stacked content if not using tabs
        add_action('woocommerce_after_single_product_summary', array($this, 'display_stacked_content'), 15);
        
        // Add body class for accordion mode
        add_filter('body_class', array($this, 'add_body_class'));

        // Add warranty schema markup
        add_action('wp_head', array($this, 'output_warranty_schema'), 10);

        // Add custom badge styles
        add_action('wp_head', array($this, 'output_custom_badge_styles'), 20);

        // Add table styles in footer to ensure they override everything
        add_action('wp_footer', array($this, 'output_table_styles_footer'), 999);

        // Payment methods badge - display after product summary (completely outside form)
        add_action('woocommerce_single_product_summary', array($this, 'display_payment_methods_badge'), 35);

        // Register badges section hook later when options are fully loaded
        add_action('wp', array($this, 'register_badges_section_hook'));
    }

    /**
     * Register the badges section hooks
     * Now supports per-badge positioning (above/below cart)
     */
    public function register_badges_section_hook() {
        if (!is_product()) {
            return;
        }

        // Register both above and below cart hooks
        // Badges will be filtered by position in the display methods
        add_action('woocommerce_before_add_to_cart_form', array($this, 'display_badges_above_cart'), 10);
        add_action('woocommerce_after_add_to_cart_form', array($this, 'display_badges_below_cart'), 10);
    }

    /**
     * Display badges positioned above the cart
     */
    public function display_badges_above_cart() {
        $this->display_inline_badges_section('above');
    }

    /**
     * Display badges positioned below the cart
     */
    public function display_badges_below_cart() {
        $this->display_inline_badges_section('below');
    }

    /**
     * Display combined badges section
     * This outputs warranty and stock badges together in one container
     * Works for both stacked and inline layouts
     *
     * @param string $position Filter badges by position ('above' or 'below' cart). Empty string shows all.
     */
    public function display_inline_badges_section($position = '') {
        global $product;
        if (!$product) {
            return;
        }

        $inline_layout = get_option('wcepi_badges_inline_layout', 'stacked');

        // Get badge order and positions from settings
        $badge_order = WCEPI_Settings::get_badge_order();
        asort($badge_order); // Sort by priority value

        $badge_positions = WCEPI_Settings::get_badge_positions();

        // Build all badge HTML in order
        $badges_html = array();

        foreach ($badge_order as $badge_key => $priority) {
            // Get badge position
            $badge_position = isset($badge_positions[$badge_key]) ? $badge_positions[$badge_key] : 'above';

            // Skip free_shipping if it's set to 'next_to_price' (it's displayed separately)
            if ($badge_key === 'free_shipping' && $badge_position === 'next_to_price') {
                continue;
            }

            // Filter by position if specified (above or below cart)
            if (!empty($position)) {
                if ($badge_position !== $position) {
                    continue;
                }
            }

            $badge_html = '';

            // Check if this is a custom badge
            if (strpos($badge_key, 'custom_') === 0) {
                $custom_badge_id = str_replace('custom_', '', $badge_key);
                $badge_html = $this->get_custom_badge_html($custom_badge_id);
            } else {
                // Built-in badges
                switch ($badge_key) {
                    case 'free_shipping':
                        $badge_html = $this->get_free_shipping_badge_html($product);
                        break;
                    case 'warranty':
                        $badge_html = $this->get_warranty_badge_html($product);
                        break;
                    case 'stock':
                        $badge_html = $this->get_stock_badge_html($product);
                        break;
                }
            }

            if (!empty($badge_html)) {
                $badges_html[] = $badge_html;
            }
        }

        // Only output if we have at least one badge
        if (empty($badges_html)) {
            return;
        }

        // Different wrapper class based on layout and position
        $wrapper_class = ($inline_layout === 'inline') ? 'wcepi-badges-wrapper wcepi-inline-wrapper' : 'wcepi-badges-wrapper wcepi-stacked-wrapper';
        if (!empty($position)) {
            $wrapper_class .= ' wcepi-badges-' . esc_attr($position);
        }

        echo '<div class="' . esc_attr($wrapper_class) . '">';
        echo implode('', $badges_html);
        echo '</div>';
    }

    /**
     * Get free shipping badge HTML (for inline mode)
     */
    private function get_free_shipping_badge_html($product) {
        if (get_option('wcepi_enable_free_shipping_badge', 'yes') !== 'yes') {
            return '';
        }

        // Check if product has free shipping
        $free_shipping = get_post_meta($product->get_id(), '_wcepi_free_shipping', true);
        if ($free_shipping !== 'yes') {
            return '';
        }

        $free_shipping_text = get_option('wcepi_free_shipping_text', 'Free Shipping');
        $bg_color = get_option('wcepi_free_shipping_bg_color', '#4CAF50');
        $text_color = get_option('wcepi_free_shipping_text_color', '#ffffff');

        $html = '<div class="wcepi-free-shipping-badge wcepi-inline-badge" style="background-color: ' . esc_attr($bg_color) . '; color: ' . esc_attr($text_color) . ';">';
        $html .= '<span class="wcepi-badge-text">' . esc_html($free_shipping_text) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get custom badge HTML
     */
    private function get_custom_badge_html($badge_id) {
        $custom_badges = WCEPI_Settings::get_custom_badges();

        if (!isset($custom_badges[$badge_id])) {
            return '';
        }

        $badge_data = $custom_badges[$badge_id];

        // Check if badge is enabled
        if (($badge_data['enabled'] ?? 'yes') !== 'yes') {
            return '';
        }

        $label = $badge_data['label'] ?? '';
        $icon = $badge_data['icon'] ?? 'shield-check';
        $bg_color = $badge_data['bg_color'] ?? 'transparent';
        $text_color = $badge_data['text_color'] ?? '#1a1a1a';
        $icon_color = $badge_data['icon_color'] ?? '#2563eb';
        $border_color = $badge_data['border_color'] ?? '';

        if (empty($label)) {
            return '';
        }

        // Build inline style
        $style = 'background-color: ' . esc_attr($bg_color) . '; color: ' . esc_attr($text_color) . ';';
        if (!empty($border_color)) {
            $style .= ' border: 1px solid ' . esc_attr($border_color) . ';';
        } elseif ($bg_color !== 'transparent' && $bg_color !== '#f8f9fa') {
            // When custom background is set and no border specified, make border transparent
            $style .= ' border-color: transparent;';
        }

        $html = '<div class="wcepi-custom-badge wcepi-inline-badge" style="' . $style . '">';

        $icon_svg = $this->get_custom_badge_icon_svg($icon, $icon_color, 18);
        if (!empty($icon_svg)) {
            $html .= '<span class="wcepi-badge-icon">' . $icon_svg . '</span>';
        }

        $html .= '<span class="wcepi-badge-text">' . esc_html($label) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get custom badge icon SVG
     */
    private function get_custom_badge_icon_svg($icon_type, $icon_color, $size = 18) {
        if ($icon_type === 'none') {
            return '';
        }

        switch ($icon_type) {
            // Checkmarks & Verification
            case 'checkbox-square':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="1" y="1" width="22" height="22" rx="4" fill="' . esc_attr($icon_color) . '"/><path d="M7 12.5L10.5 16L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'checkbox-circle':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="' . esc_attr($icon_color) . '"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'verified':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.4 2.4h3.4v3.4L20 10l-2 2.2v3.4h-3.4L12 18l-2.4-2.4H6.2v-3.4L4 10l2-2.2V4.4h3.4L12 2z" fill="' . esc_attr($icon_color) . '"/><path d="M9 10l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'check':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M4 12l5.5 5.5L20 7" stroke="' . esc_attr($icon_color) . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            // Security & Trust
            case 'shield':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'shield-check':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="' . esc_attr($icon_color) . '"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'lock':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="' . esc_attr($icon_color) . '" stroke-width="2"/></svg>';
            case 'key':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="7.5" cy="15.5" r="5.5" fill="' . esc_attr($icon_color) . '"/><path d="M11 12l10-10M21 2l-3 3M18 5l-3 3" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round"/></svg>';

            // Awards & Quality
            case 'badge':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="' . esc_attr($icon_color) . '"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'star':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'award':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="6" fill="' . esc_attr($icon_color) . '"/><path d="M8.21 13.89L7 23l5-3 5 3-1.21-9.12" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'certificate':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="14" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M7 9h10M7 12h6" stroke="white" stroke-width="1.5" stroke-linecap="round"/><circle cx="16" cy="18" r="3" fill="' . esc_attr($icon_color) . '" stroke="white" stroke-width="1"/></svg>';
            case 'trophy':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M6 2h12v6a6 6 0 11-12 0V2z" fill="' . esc_attr($icon_color) . '"/><path d="M6 4H2v4a4 4 0 004 4M18 4h4v4a4 4 0 01-4 4M12 14v4M8 22h8M12 18h0" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round"/></svg>';
            case 'crown':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M2 17l3-11 5 6 2-8 2 8 5-6 3 11H2z" fill="' . esc_attr($icon_color) . '"/><rect x="2" y="17" width="20" height="4" fill="' . esc_attr($icon_color) . '"/></svg>';

            // Satisfaction & Approval
            case 'thumbs-up':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'heart':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'smile':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M8 14s1.5 2 4 2 4-2 4-2" stroke="white" stroke-width="2" stroke-linecap="round"/><circle cx="9" cy="9" r="1.5" fill="white"/><circle cx="15" cy="9" r="1.5" fill="white"/></svg>';

            // Shipping & Delivery
            case 'truck':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M16 3H1v13h15V3z" fill="' . esc_attr($icon_color) . '"/><path d="M16 8h4l3 3v5h-7V8z" fill="' . esc_attr($icon_color) . '"/><circle cx="5.5" cy="18.5" r="2.5" fill="' . esc_attr($icon_color) . '" stroke="white" stroke-width="1"/><circle cx="18.5" cy="18.5" r="2.5" fill="' . esc_attr($icon_color) . '" stroke="white" stroke-width="1"/></svg>';
            case 'box':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" fill="' . esc_attr($icon_color) . '"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="white" stroke-width="1.5"/></svg>';
            case 'plane':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'clock':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M12 6v6l4 2" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';
            case 'calendar':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M16 2v4M8 2v4M3 10h18" stroke="white" stroke-width="1.5"/></svg>';

            // Money & Payment
            case 'money':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 010 3H9M9 12h4.5a1.5 1.5 0 010 3H9" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';
            case 'credit-card':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="1" y="4" width="22" height="16" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M1 10h22" stroke="white" stroke-width="2"/><path d="M5 15h4M13 15h4" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>';
            case 'percent':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><circle cx="9" cy="9" r="2" stroke="white" stroke-width="1.5"/><circle cx="15" cy="15" r="2" stroke="white" stroke-width="1.5"/><path d="M17 7L7 17" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';
            case 'tag':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z" fill="' . esc_attr($icon_color) . '"/><circle cx="7" cy="7" r="2" fill="white"/></svg>';

            // Communication & Support
            case 'headset':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M3 18v-6a9 9 0 0118 0v6" stroke="' . esc_attr($icon_color) . '" stroke-width="2"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3v5zM3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3v5z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'phone':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'chat':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2v10z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'email':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="2" y="4" width="20" height="16" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M22 6l-10 7L2 6" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';

            // Arrows & Returns
            case 'refresh':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M23 4v6h-6M1 20v-6h6" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'undo':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M3 10h10a5 5 0 015 5v2" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 14l-4-4 4-4" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            // Misc
            case 'gift':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="3" y="8" width="18" height="14" rx="1" fill="' . esc_attr($icon_color) . '"/><rect x="1" y="4" width="22" height="4" fill="' . esc_attr($icon_color) . '"/><path d="M12 4v18M12 4c-1.5-2-4-3-6-2s-2 3 0 4c1 .5 4 0 6-2zM12 4c1.5-2 4-3 6-2s2 3 0 4c-1 .5-4 0-6-2z" stroke="white" stroke-width="1.5"/></svg>';
            case 'leaf':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M11 20A7 7 0 019.2 6.2C11 4 13 2 18 2c0 5-2 7-4.2 8.8A7 7 0 0111 20z" fill="' . esc_attr($icon_color) . '"/><path d="M4 21c1-5 5-9 7-9" stroke="' . esc_attr($icon_color) . '" stroke-width="2" stroke-linecap="round"/></svg>';
            case 'bolt':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'fire':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 22c4-2 7-6 7-10 0-3-1.5-5.5-4-8-1 3-2 4-4 4-1-3-2-5-2-8-3 2-6 7-6 12 0 4 3 8 9 10z" fill="' . esc_attr($icon_color) . '"/></svg>';
            case 'globe':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z" stroke="white" stroke-width="1.5"/></svg>';
            case 'info':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M12 16v-4M12 8h.01" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';

            default:
                return '';
        }
    }

    /**
     * Get warranty badge HTML (for inline mode - not appended to price)
     */
    private function get_warranty_badge_html($product) {
        $warranty_display_price = get_post_meta($product->get_id(), '_wcepi_warranty_display_price', true);
        if ($warranty_display_price !== 'yes') {
            return '';
        }

        $warranty_lifetime = get_post_meta($product->get_id(), '_wcepi_warranty_lifetime', true);
        $warranty_period = get_post_meta($product->get_id(), '_wcepi_warranty_period', true);
        $warranty_unit = get_post_meta($product->get_id(), '_wcepi_warranty_unit', true);
        $warranty_type = get_post_meta($product->get_id(), '_wcepi_warranty_type', true);

        if ($warranty_lifetime !== 'yes' && empty($warranty_period)) {
            return '';
        }

        // Build warranty text
        $warranty_text = '';
        if ($warranty_type === 'LifetimeWarranty' || $warranty_lifetime === 'yes') {
            $warranty_text = __('Lifetime Warranty', 'wc-enhanced-product-info');
        } elseif (!empty($warranty_period)) {
            if ($warranty_unit === 'days') {
                $warranty_text = sprintf(_n('%s-Day Warranty', '%s-Day Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
            } elseif ($warranty_unit === 'months') {
                $warranty_text = sprintf(_n('%s-Month Warranty', '%s-Month Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
            } else {
                $warranty_text = sprintf(_n('%s-Year Warranty', '%s-Year Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
            }
        }

        if (empty($warranty_text)) {
            return '';
        }

        $html = '<div class="wcepi-warranty-badge-price wcepi-inline-badge">';
        $icon_svg = $this->get_warranty_icon_svg(18);
        if (!empty($icon_svg)) {
            $html .= '<span class="wcepi-warranty-icon">' . $icon_svg . '</span>';
        }
        $html .= '<span class="wcepi-warranty-text">' . esc_html($warranty_text) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get stock badge HTML (for inline mode)
     */
    private function get_stock_badge_html($product) {
        if (get_option('wcepi_enable_stock_status', 'yes') !== 'yes') {
            return '';
        }

        $ships_in_days = get_post_meta($product->get_id(), '_wcepi_ships_in_days', true);
        $return_date = get_post_meta($product->get_id(), '_wcepi_return_date', true);

        if ($product->is_in_stock()) {
            $in_stock_text = get_option('wcepi_in_stock_text', 'In Stock');
            $ships_in_days_int = intval($ships_in_days);
            if ($ships_in_days_int > 0) {
                $ships_in_text = get_option('wcepi_ships_in_text', 'Ships in %s Days');
                $stock_text = $in_stock_text . ' - ' . sprintf($ships_in_text, $ships_in_days_int);
            } else {
                $stock_text = $in_stock_text;
            }

            $html = '<p class="stock wcepi-in-stock wcepi-inline-badge">';
            $icon_svg = $this->get_stock_icon_svg(true, 18);
            if (!empty($icon_svg)) {
                $html .= '<span class="wcepi-stock-icon">' . $icon_svg . '</span>';
            }
            $html .= '<span class="wcepi-stock-text">' . esc_html($stock_text) . '</span>';
            $html .= '</p>';
        } else {
            $out_of_stock_text = get_option('wcepi_out_of_stock_text', 'Out of Stock');
            $stock_text = $out_of_stock_text;

            if (!empty($return_date)) {
                $formatted_date = date_i18n(get_option('date_format'), strtotime($return_date));
                $stock_text .= ' - ' . sprintf(__('Expected: %s', 'wc-enhanced-product-info'), $formatted_date);
            }

            $html = '<p class="stock wcepi-out-of-stock wcepi-inline-badge">';
            $icon_svg = $this->get_stock_icon_svg(false, 18);
            if (!empty($icon_svg)) {
                $html .= '<span class="wcepi-stock-icon">' . $icon_svg . '</span>';
            }
            $html .= '<span class="wcepi-stock-text">' . esc_html($stock_text) . '</span>';
            $html .= '</p>';
        }

        return $html;
    }
    
    /**
     * Output custom badge styles based on settings
     */
    public function output_custom_badge_styles() {
        // Output on product pages and archive/shop pages
        if (!is_product() && !is_shop() && !is_product_category() && !is_product_tag() && !is_product_taxonomy()) {
            return;
        }

        // Get custom colors from settings
        $free_shipping_bg = get_option('wcepi_free_shipping_bg_color', '#4CAF50');
        $free_shipping_text = get_option('wcepi_free_shipping_text_color', '#ffffff');
        $free_shipping_border = get_option('wcepi_free_shipping_border_color', '');

        // Ships In badge settings
        $ships_in_bg = get_option('wcepi_ships_in_bg_color', 'transparent');
        $ships_in_text = get_option('wcepi_ships_in_text_color', '#666666');
        $ships_in_icon = get_option('wcepi_ships_in_icon_color', '#666666');

        // Warranty badge settings
        $warranty_bg = get_option('wcepi_warranty_badge_bg_color', 'transparent');
        $warranty_text = get_option('wcepi_warranty_badge_text_color', '#1a1a1a');
        $warranty_border = get_option('wcepi_warranty_badge_border_color', '');
        $warranty_font_size = get_option('wcepi_warranty_badge_font_size', '14');
        $warranty_font_weight = get_option('wcepi_warranty_badge_font_weight', '500');

        // Stock badge settings
        $stock_in_bg = get_option('wcepi_stock_badge_in_stock_bg_color', 'transparent');
        $stock_in_text = get_option('wcepi_stock_badge_in_stock_text_color', '#1a1a1a');
        $stock_in_border = get_option('wcepi_stock_badge_in_stock_border_color', '');
        $stock_out_bg = get_option('wcepi_stock_badge_out_of_stock_bg_color', 'transparent');
        $stock_out_text = get_option('wcepi_stock_badge_out_of_stock_text_color', '#dc2626');
        $stock_out_border = get_option('wcepi_stock_badge_out_of_stock_border_color', '');
        $stock_font_size = get_option('wcepi_stock_badge_font_size', '14');
        $stock_font_weight = get_option('wcepi_stock_badge_font_weight', '500');

        // Get content styling settings
        $content_font_size = get_option('wcepi_content_font_size', '15');
        $content_padding_top = get_option('wcepi_content_padding_top', '10');
        $content_padding_bottom = get_option('wcepi_content_padding_bottom', '10');
        $content_padding_left = get_option('wcepi_content_padding_left', '0');
        $content_padding_right = get_option('wcepi_content_padding_right', '0');

        // Get table styling settings
        $table_border_color = get_option('wcepi_table_border_color', '#eeeeee');
        $table_border_width = get_option('wcepi_table_border_width', '1');
        $table_cell_padding = get_option('wcepi_table_cell_padding', '12');

        // Get badge shape setting
        $badge_shape = get_option('wcepi_badges_shape', 'rounded');

        // Output inline styles
        echo '<style type="text/css">';

        // Badge shape - border radius based on setting
        $border_radius = '20px'; // default rounded/pill
        if ($badge_shape === 'slightly-rounded') {
            $border_radius = '6px';
        } elseif ($badge_shape === 'squared') {
            $border_radius = '0';
        }

        // Badge shape styles - use high specificity to override CSS file
        echo 'body .wcepi-warranty-badge-price, body .wcepi-in-stock, body .wcepi-out-of-stock, body .wcepi-inline-badge,';
        echo 'body .wcepi-stacked-wrapper .wcepi-warranty-badge-price, body .wcepi-stacked-wrapper .wcepi-in-stock, body .wcepi-stacked-wrapper .wcepi-out-of-stock, body .wcepi-stacked-wrapper .wcepi-inline-badge,';
        echo 'body .wcepi-inline-wrapper .wcepi-warranty-badge-price, body .wcepi-inline-wrapper .wcepi-in-stock, body .wcepi-inline-wrapper .wcepi-out-of-stock, body .wcepi-inline-wrapper .wcepi-inline-badge,';
        echo 'body.wcepi-inline-badges-mode .wcepi-warranty-badge-price, body.wcepi-inline-badges-mode .wcepi-in-stock, body.wcepi-inline-badges-mode .wcepi-out-of-stock,';
        echo '.woocommerce .wcepi-warranty-badge-price, .woocommerce .wcepi-in-stock, .woocommerce .wcepi-out-of-stock, .woocommerce .wcepi-inline-badge {';
        echo 'border-radius: ' . $border_radius . ' !important;';
        echo '}';

        // Free shipping badge styles - use high specificity
        echo 'body .wcepi-free-shipping-badge,';
        echo 'body .wcepi-free-shipping-badge.wcepi-inline-badge {';
        if ($free_shipping_bg) {
            echo 'background: ' . esc_attr($free_shipping_bg) . ' !important;';
        }
        if ($free_shipping_text) {
            echo 'color: ' . esc_attr($free_shipping_text) . ' !important;';
        }
        if ($free_shipping_border) {
            echo 'border: 1px solid ' . esc_attr($free_shipping_border) . ' !important;';
        } else {
            echo 'border-color: transparent !important;';
        }
        echo '}';

        // Ships In badge styles - use high specificity
        echo 'body .wcepi-ships-in {';
        if ($ships_in_bg) {
            echo 'background: ' . esc_attr($ships_in_bg) . ' !important;';
        }
        if ($ships_in_text) {
            echo 'color: ' . esc_attr($ships_in_text) . ' !important;';
        }
        echo '}';

        if ($ships_in_icon) {
            echo 'body .wcepi-ships-in svg,';
            echo 'body .wcepi-ships-in svg path {';
            echo 'fill: ' . esc_attr($ships_in_icon) . ' !important;';
            echo '}';
        }

        // Warranty badge styles - use high specificity to override CSS file
        echo 'body .wcepi-warranty-badge-price,';
        echo 'body .wcepi-stacked-wrapper .wcepi-warranty-badge-price,';
        echo 'body .wcepi-inline-wrapper .wcepi-warranty-badge-price {';
        if ($warranty_bg) {
            echo 'background: ' . esc_attr($warranty_bg) . ' !important;';
            // When custom background is set, make border transparent unless border color is specified
            if (!$warranty_border) {
                echo 'border-color: transparent !important;';
            }
        }
        if ($warranty_border) {
            echo 'border: 1px solid ' . esc_attr($warranty_border) . ' !important;';
        }
        echo '}';

        echo 'body .wcepi-warranty-badge-price .wcepi-warranty-text,';
        echo 'body .wcepi-stacked-wrapper .wcepi-warranty-badge-price .wcepi-warranty-text,';
        echo 'body .wcepi-inline-wrapper .wcepi-warranty-badge-price .wcepi-warranty-text {';
        if ($warranty_text) {
            echo 'color: ' . esc_attr($warranty_text) . ' !important;';
        }
        echo 'font-size: ' . intval($warranty_font_size) . 'px !important;';
        echo 'font-weight: ' . intval($warranty_font_weight) . ' !important;';
        echo '}';

        // In Stock badge styles - use high specificity to override CSS file
        echo 'body .wcepi-in-stock,';
        echo 'body .wcepi-stacked-wrapper .wcepi-in-stock,';
        echo 'body .wcepi-inline-wrapper .wcepi-in-stock {';
        if ($stock_in_bg) {
            echo 'background: ' . esc_attr($stock_in_bg) . ' !important;';
        }
        // Handle border color
        if ($stock_in_border) {
            echo 'border: 1px solid ' . esc_attr($stock_in_border) . ' !important;';
        } elseif ($stock_in_bg && $stock_in_bg !== 'transparent' && $stock_in_bg !== '#f8f9fa') {
            // When custom background is set and no border specified, make border transparent
            echo 'border-color: transparent !important;';
        }
        echo '}';

        echo 'body .wcepi-in-stock .wcepi-stock-text,';
        echo 'body .wcepi-stacked-wrapper .wcepi-in-stock .wcepi-stock-text,';
        echo 'body .wcepi-inline-wrapper .wcepi-in-stock .wcepi-stock-text {';
        if ($stock_in_text) {
            echo 'color: ' . esc_attr($stock_in_text) . ' !important;';
        }
        echo 'font-size: ' . intval($stock_font_size) . 'px !important;';
        echo 'font-weight: ' . intval($stock_font_weight) . ' !important;';
        echo '}';

        // Out of Stock badge styles - use high specificity to override CSS file
        echo 'body .wcepi-out-of-stock,';
        echo 'body .wcepi-stacked-wrapper .wcepi-out-of-stock,';
        echo 'body .wcepi-inline-wrapper .wcepi-out-of-stock {';
        if ($stock_out_bg) {
            echo 'background: ' . esc_attr($stock_out_bg) . ' !important;';
        }
        // Handle border color
        if ($stock_out_border) {
            echo 'border: 1px solid ' . esc_attr($stock_out_border) . ' !important;';
        } elseif ($stock_out_bg && $stock_out_bg !== 'transparent' && $stock_out_bg !== '#f8f9fa') {
            // When custom background is set and no border specified, make border transparent
            echo 'border-color: transparent !important;';
        }
        echo '}';

        echo 'body .wcepi-out-of-stock .wcepi-stock-text,';
        echo 'body .wcepi-stacked-wrapper .wcepi-out-of-stock .wcepi-stock-text,';
        echo 'body .wcepi-inline-wrapper .wcepi-out-of-stock .wcepi-stock-text {';
        if ($stock_out_text) {
            echo 'color: ' . esc_attr($stock_out_text) . ' !important;';
        }
        echo 'font-size: ' . intval($stock_font_size) . 'px !important;';
        echo 'font-weight: ' . intval($stock_font_weight) . ' !important;';
        echo '}';

        // Content styling
        echo '.wcepi-dimensions-content, .wcepi-specifications-content, .wcepi-downloads-content, .wcepi-shipping-returns-content, .wcepi-returns-content, .wcepi-warranty-content, .wcepi-faq-content, .wcepi-reviews-content {';
        echo 'font-size: ' . intval($content_font_size) . 'px !important;';
        echo 'padding: ' . intval($content_padding_top) . 'px ' . intval($content_padding_right) . 'px ' . intval($content_padding_bottom) . 'px ' . intval($content_padding_left) . 'px !important;';
        echo '}';

        // Also apply to tabs mode specifically
        echo 'body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-dimensions-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-specifications-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-downloads-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-shipping-returns-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-returns-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-warranty-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-faq-content, body:not(.wcepi-accordion-mode) .woocommerce-tabs .wcepi-reviews-content {';
        echo 'font-size: ' . intval($content_font_size) . 'px !important;';
        echo 'padding: ' . intval($content_padding_top) . 'px ' . intval($content_padding_right) . 'px ' . intval($content_padding_bottom) . 'px ' . intval($content_padding_left) . 'px !important;';
        echo '}';

        // Apply padding to accordion panels
        echo '.wcepi-accordion-mode .woocommerce-Tabs-panel, .wcepi-accordion-mode .woocommerce-Tabs-panel.active {';
        echo 'padding: ' . intval($content_padding_top) . 'px ' . intval($content_padding_right) . 'px ' . intval($content_padding_bottom) . 'px ' . intval($content_padding_left) . 'px !important;';
        echo '}';

        // Table styling - Always output with defaults if not set
        $table_border_width_val = $table_border_width !== '' ? intval($table_border_width) : 1;
        $table_border_color_val = $table_border_color ? $table_border_color : '#eeeeee';
        $table_cell_padding_val = $table_cell_padding !== '' ? intval($table_cell_padding) : 12;

        // High specificity selectors for table styling
        echo 'body .wcepi-dimensions-table, body .wcepi-specifications-table, body .wcepi-custom-section-table,';
        echo 'body table.wcepi-dimensions-table, body table.wcepi-specifications-table, body table.wcepi-custom-section-table {';
        echo 'border-collapse: collapse !important;';
        echo 'border-spacing: 0 !important;';
        echo 'width: 100% !important;';
        echo '}';

        echo 'body .wcepi-dimensions-table th, body .wcepi-specifications-table th, body .wcepi-custom-section-table th,';
        echo 'body table.wcepi-dimensions-table th, body table.wcepi-specifications-table th, body table.wcepi-custom-section-table th,';
        echo 'body .wcepi-dimensions-table tr th, body .wcepi-specifications-table tr th, body .wcepi-custom-section-table tr th {';
        echo 'padding: ' . $table_cell_padding_val . 'px 15px !important;';
        echo 'border-bottom: ' . $table_border_width_val . 'px solid ' . esc_attr($table_border_color_val) . ' !important;';
        echo 'border-top: none !important;';
        echo 'border-left: none !important;';
        echo 'border-right: none !important;';
        echo '}';

        echo 'body .wcepi-dimensions-table td, body .wcepi-specifications-table td, body .wcepi-custom-section-table td,';
        echo 'body table.wcepi-dimensions-table td, body table.wcepi-specifications-table td, body table.wcepi-custom-section-table td,';
        echo 'body .wcepi-dimensions-table tr td, body .wcepi-specifications-table tr td, body .wcepi-custom-section-table tr td {';
        echo 'padding: ' . $table_cell_padding_val . 'px 15px !important;';
        echo 'border-bottom: ' . $table_border_width_val . 'px solid ' . esc_attr($table_border_color_val) . ' !important;';
        echo 'border-top: none !important;';
        echo 'border-left: none !important;';
        echo 'border-right: none !important;';
        echo '}';

        // Remove bottom border from last row
        echo 'body .wcepi-dimensions-table tr:last-child td, body .wcepi-specifications-table tr:last-child td, body .wcepi-custom-section-table tr:last-child td,';
        echo 'body table.wcepi-dimensions-table tr:last-child td, body table.wcepi-specifications-table tr:last-child td, body table.wcepi-custom-section-table tr:last-child td {';
        echo 'border-bottom: none !important;';
        echo '}';

        // =============================================
        // ARCHIVE/LISTING PAGE BADGE STYLES
        // =============================================

        // Get archive badge general settings
        $archive_badge_shape = get_option('wcepi_archive_badge_shape', '');
        $archive_font_size = get_option('wcepi_archive_badge_font_size', '12');
        $archive_padding = get_option('wcepi_archive_badge_padding', '4px 10px');
        // Defensive: only allow CSS length lists in a value that gets printed inside <style>
        if (!preg_match('/^(\d+(\.\d+)?(px|em|rem|%)?\s*){1,4}$/', $archive_padding)) {
            $archive_padding = '4px 10px';
        }

        // Determine archive badge border radius based on shape setting
        // If empty, fall back to product page badge shape
        if (empty($archive_badge_shape)) {
            $archive_badge_shape = $badge_shape; // Use product page setting
        }
        // Convert shape to border-radius
        $archive_border_radius = '20px'; // default rounded/pill
        if ($archive_badge_shape === 'slightly-rounded') {
            $archive_border_radius = '6px';
        } elseif ($archive_badge_shape === 'squared') {
            $archive_border_radius = '0';
        }

        // Archive - Free Shipping Badge settings (fallback to product page settings)
        $archive_fs_bg = get_option('wcepi_archive_free_shipping_bg_color', '');
        $archive_fs_text = get_option('wcepi_archive_free_shipping_text_color', '');
        $archive_fs_border = get_option('wcepi_archive_free_shipping_border_color', '');

        // Archive - Warranty Badge settings (fallback to product page settings)
        $archive_warranty_bg = get_option('wcepi_archive_warranty_bg_color', '');
        $archive_warranty_text = get_option('wcepi_archive_warranty_text_color', '');
        $archive_warranty_border = get_option('wcepi_archive_warranty_border_color', '');
        $archive_warranty_icon = get_option('wcepi_archive_warranty_icon_color', '');

        // Archive - In Stock Badge settings (fallback to product page settings)
        $archive_stock_in_bg = get_option('wcepi_archive_stock_in_bg_color', '');
        $archive_stock_in_text = get_option('wcepi_archive_stock_in_text_color', '');
        $archive_stock_in_border = get_option('wcepi_archive_stock_in_border_color', '');
        $archive_stock_in_icon = get_option('wcepi_archive_stock_in_icon_color', '');

        // Archive - Out of Stock Badge settings (fallback to product page settings)
        $archive_stock_out_bg = get_option('wcepi_archive_stock_out_bg_color', '');
        $archive_stock_out_text = get_option('wcepi_archive_stock_out_text_color', '');
        $archive_stock_out_border = get_option('wcepi_archive_stock_out_border_color', '');
        $archive_stock_out_icon = get_option('wcepi_archive_stock_out_icon_color', '');

        // Base styles for all archive badges
        echo '/* Archive/Listing Badge Base Styles */';
        echo '.wcepi-archive-badge, .wcepi-archive-warranty, .wcepi-archive-stock, .wcepi-archive-custom-badge {';
        echo 'display: inline-flex !important;';
        echo 'align-items: center !important;';
        echo 'gap: 4px !important;';
        echo 'font-size: ' . intval($archive_font_size) . 'px !important;';
        echo 'padding: ' . esc_attr($archive_padding) . ' !important;';
        echo 'border-radius: ' . esc_attr($archive_border_radius) . ' !important;';
        echo 'line-height: 1.3 !important;';
        echo 'margin-top: 5px !important;';
        echo '}';

        // Archive Free Shipping Badge - use product page settings as fallback when archive setting is empty
        echo '.wcepi-free-shipping-badge.wcepi-archive-badge {';
        if ($archive_fs_bg === 'transparent') {
            echo 'background-color: transparent !important;';
        } elseif ($archive_fs_bg && $archive_fs_bg !== 'none') {
            echo 'background-color: ' . esc_attr($archive_fs_bg) . ' !important;';
        } elseif ($free_shipping_bg) {
            // Fallback to product page styling
            echo 'background-color: ' . esc_attr($free_shipping_bg) . ' !important;';
        }
        if ($archive_fs_text) {
            echo 'color: ' . esc_attr($archive_fs_text) . ' !important;';
        } elseif ($free_shipping_text) {
            // Fallback to product page styling
            echo 'color: ' . esc_attr($free_shipping_text) . ' !important;';
        }
        if ($archive_fs_border === 'none') {
            echo 'border: none !important;';
        } elseif ($archive_fs_border && $archive_fs_border !== 'transparent') {
            echo 'border: 1px solid ' . esc_attr($archive_fs_border) . ' !important;';
        } elseif ($free_shipping_border) {
            // Fallback to product page styling
            echo 'border: 1px solid ' . esc_attr($free_shipping_border) . ' !important;';
        }
        echo '}';

        // Archive Warranty Badge - use product page settings as fallback when archive setting is empty
        echo '.wcepi-warranty-badge-price.wcepi-archive-warranty {';
        if ($archive_warranty_bg === 'transparent') {
            echo 'background-color: transparent !important;';
        } elseif ($archive_warranty_bg && $archive_warranty_bg !== 'none') {
            echo 'background-color: ' . esc_attr($archive_warranty_bg) . ' !important;';
        } elseif ($warranty_bg) {
            // Fallback to product page styling
            echo 'background-color: ' . esc_attr($warranty_bg) . ' !important;';
        }
        if ($archive_warranty_border === 'none') {
            echo 'border: none !important;';
        } elseif ($archive_warranty_border && $archive_warranty_border !== 'transparent') {
            echo 'border: 1px solid ' . esc_attr($archive_warranty_border) . ' !important;';
        } elseif ($warranty_border) {
            // Fallback to product page styling
            echo 'border: 1px solid ' . esc_attr($warranty_border) . ' !important;';
        }
        echo '}';

        echo '.wcepi-warranty-badge-price.wcepi-archive-warranty .wcepi-warranty-text {';
        if ($archive_warranty_text) {
            echo 'color: ' . esc_attr($archive_warranty_text) . ' !important;';
        } elseif ($warranty_text) {
            // Fallback to product page styling
            echo 'color: ' . esc_attr($warranty_text) . ' !important;';
        }
        echo 'font-size: ' . intval($archive_font_size) . 'px !important;';
        echo '}';

        // Warranty icon - archive or product page fallback
        $warranty_icon_color = $archive_warranty_icon ? $archive_warranty_icon : $warranty_icon;
        if ($warranty_icon_color) {
            echo '.wcepi-warranty-badge-price.wcepi-archive-warranty .wcepi-warranty-icon svg,';
            echo '.wcepi-warranty-badge-price.wcepi-archive-warranty .wcepi-warranty-icon svg circle,';
            echo '.wcepi-warranty-badge-price.wcepi-archive-warranty .wcepi-warranty-icon svg rect,';
            echo '.wcepi-warranty-badge-price.wcepi-archive-warranty .wcepi-warranty-icon svg path {';
            echo 'fill: ' . esc_attr($warranty_icon_color) . ' !important;';
            echo '}';
        }

        // Archive In Stock Badge - use product page settings as fallback when archive setting is empty
        echo '.wcepi-archive-stock.wcepi-archive-in-stock {';
        if ($archive_stock_in_bg === 'transparent') {
            echo 'background-color: transparent !important;';
        } elseif ($archive_stock_in_bg && $archive_stock_in_bg !== 'none') {
            echo 'background-color: ' . esc_attr($archive_stock_in_bg) . ' !important;';
        } elseif ($stock_in_bg) {
            // Fallback to product page styling
            echo 'background-color: ' . esc_attr($stock_in_bg) . ' !important;';
        }
        if ($archive_stock_in_border === 'none') {
            echo 'border: none !important;';
        } elseif ($archive_stock_in_border && $archive_stock_in_border !== 'transparent') {
            echo 'border: 1px solid ' . esc_attr($archive_stock_in_border) . ' !important;';
        } elseif ($stock_in_border) {
            // Fallback to product page styling
            echo 'border: 1px solid ' . esc_attr($stock_in_border) . ' !important;';
        }
        echo '}';

        echo '.wcepi-archive-stock.wcepi-archive-in-stock .wcepi-stock-text {';
        if ($archive_stock_in_text) {
            echo 'color: ' . esc_attr($archive_stock_in_text) . ' !important;';
        } elseif ($stock_in_text) {
            // Fallback to product page styling
            echo 'color: ' . esc_attr($stock_in_text) . ' !important;';
        }
        echo 'font-size: ' . intval($archive_font_size) . 'px !important;';
        echo '}';

        // In Stock icon - archive or product page fallback
        $stock_in_icon_color = $archive_stock_in_icon ? $archive_stock_in_icon : $stock_in_icon;
        if ($stock_in_icon_color) {
            echo '.wcepi-archive-stock.wcepi-archive-in-stock .wcepi-stock-icon svg,';
            echo '.wcepi-archive-stock.wcepi-archive-in-stock .wcepi-stock-icon svg circle,';
            echo '.wcepi-archive-stock.wcepi-archive-in-stock .wcepi-stock-icon svg path {';
            echo 'fill: ' . esc_attr($stock_in_icon_color) . ' !important;';
            echo '}';
        }

        // Archive Out of Stock Badge - use product page settings as fallback when archive setting is empty
        echo '.wcepi-archive-stock.wcepi-archive-out-of-stock {';
        if ($archive_stock_out_bg === 'transparent') {
            echo 'background-color: transparent !important;';
        } elseif ($archive_stock_out_bg && $archive_stock_out_bg !== 'none') {
            echo 'background-color: ' . esc_attr($archive_stock_out_bg) . ' !important;';
        } elseif ($stock_out_bg) {
            // Fallback to product page styling
            echo 'background-color: ' . esc_attr($stock_out_bg) . ' !important;';
        }
        if ($archive_stock_out_border === 'none') {
            echo 'border: none !important;';
        } elseif ($archive_stock_out_border && $archive_stock_out_border !== 'transparent') {
            echo 'border: 1px solid ' . esc_attr($archive_stock_out_border) . ' !important;';
        } elseif ($stock_out_border) {
            // Fallback to product page styling
            echo 'border: 1px solid ' . esc_attr($stock_out_border) . ' !important;';
        }
        echo '}';

        echo '.wcepi-archive-stock.wcepi-archive-out-of-stock .wcepi-stock-text {';
        if ($archive_stock_out_text) {
            echo 'color: ' . esc_attr($archive_stock_out_text) . ' !important;';
        } elseif ($stock_out_text) {
            // Fallback to product page styling
            echo 'color: ' . esc_attr($stock_out_text) . ' !important;';
        }
        echo 'font-size: ' . intval($archive_font_size) . 'px !important;';
        echo '}';

        // Out of Stock icon - archive or product page fallback
        $stock_out_icon_color = $archive_stock_out_icon ? $archive_stock_out_icon : $stock_out_icon;
        if ($stock_out_icon_color) {
            echo '.wcepi-archive-stock.wcepi-archive-out-of-stock .wcepi-stock-icon svg,';
            echo '.wcepi-archive-stock.wcepi-archive-out-of-stock .wcepi-stock-icon svg circle,';
            echo '.wcepi-archive-stock.wcepi-archive-out-of-stock .wcepi-stock-icon svg path {';
            echo 'fill: ' . esc_attr($stock_out_icon_color) . ' !important;';
            echo '}';
        }

        // Archive Custom Badge base styles
        echo '.wcepi-archive-custom-badge {';
        echo 'border: 1px solid #e5e7eb !important;';
        echo '}';

        echo '.wcepi-archive-custom-badge .wcepi-badge-text {';
        echo 'font-size: ' . intval($archive_font_size) . 'px !important;';
        echo '}';

        echo '</style>';
    }

    /**
     * Output table styles in footer to ensure they override theme styles
     */
    public function output_table_styles_footer() {
        if (!is_product()) {
            return;
        }

        $table_border_color = get_option('wcepi_table_border_color', '#eeeeee');
        $table_border_width = get_option('wcepi_table_border_width', '1');
        $table_cell_padding = get_option('wcepi_table_cell_padding', '12');

        $border_width_val = $table_border_width !== '' ? intval($table_border_width) : 1;
        $border_color_val = $table_border_color ? $table_border_color : '#eeeeee';
        $padding_val = $table_cell_padding !== '' ? intval($table_cell_padding) : 12;

        echo '<style type="text/css" id="wcepi-table-styles">';
        // Ultra-high specificity selectors for table cells
        echo 'html body .woocommerce-Tabs-panel .wcepi-dimensions-table th,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-dimensions-table td,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-specifications-table th,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-specifications-table td,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-custom-section-table th,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-custom-section-table td,';
        echo 'html body .wcepi-dimensions-table th,';
        echo 'html body .wcepi-dimensions-table td,';
        echo 'html body .wcepi-specifications-table th,';
        echo 'html body .wcepi-specifications-table td,';
        echo 'html body .wcepi-custom-section-table th,';
        echo 'html body .wcepi-custom-section-table td,';
        echo 'body .wcepi-dimensions-table th, body .wcepi-specifications-table th, body .wcepi-custom-section-table th,';
        echo 'body .wcepi-dimensions-table td, body .wcepi-specifications-table td, body .wcepi-custom-section-table td,';
        echo '.wcepi-dimensions-table th, .wcepi-specifications-table th, .wcepi-custom-section-table th,';
        echo '.wcepi-dimensions-table td, .wcepi-specifications-table td, .wcepi-custom-section-table td,';
        echo 'table.wcepi-dimensions-table th, table.wcepi-specifications-table th, table.wcepi-custom-section-table th,';
        echo 'table.wcepi-dimensions-table td, table.wcepi-specifications-table td, table.wcepi-custom-section-table td {';
        echo 'padding-top: ' . $padding_val . 'px !important;';
        echo 'padding-bottom: ' . $padding_val . 'px !important;';
        echo 'padding-left: 15px !important;';
        echo 'padding-right: 15px !important;';
        echo 'border-bottom: ' . $border_width_val . 'px solid ' . esc_attr($border_color_val) . ' !important;';
        echo 'border-top: none !important;';
        echo 'border-left: none !important;';
        echo 'border-right: none !important;';
        echo '}';
        // Remove bottom border from last row
        echo 'html body .woocommerce-Tabs-panel .wcepi-dimensions-table tr:last-child td,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-specifications-table tr:last-child td,';
        echo 'html body .woocommerce-Tabs-panel .wcepi-custom-section-table tr:last-child td,';
        echo 'html body .wcepi-dimensions-table tr:last-child td,';
        echo 'html body .wcepi-specifications-table tr:last-child td,';
        echo 'html body .wcepi-custom-section-table tr:last-child td,';
        echo 'body .wcepi-dimensions-table tr:last-child td, body .wcepi-specifications-table tr:last-child td, body .wcepi-custom-section-table tr:last-child td,';
        echo '.wcepi-dimensions-table tr:last-child td, .wcepi-specifications-table tr:last-child td, .wcepi-custom-section-table tr:last-child td {';
        echo 'border-bottom: none !important;';
        echo '}';
        echo '</style>';
    }

    /**
     * Get warranty badge icon SVG based on settings
     */
    private function get_warranty_icon_svg($size = 18) {
        $icon_type = get_option('wcepi_warranty_badge_icon_type', 'checkbox-square');
        $icon_color = get_option('wcepi_warranty_badge_icon_color', '#2563eb');

        if ($icon_type === 'none') {
            return '';
        }

        switch ($icon_type) {
            case 'checkbox-circle':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="' . esc_attr($icon_color) . '"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            case 'shield':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="' . esc_attr($icon_color) . '"/></svg>';

            case 'shield-check':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z" fill="' . esc_attr($icon_color) . '"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            case 'badge':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="' . esc_attr($icon_color) . '"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            case 'star':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="' . esc_attr($icon_color) . '"/></svg>';

            case 'award':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="8" r="6" fill="' . esc_attr($icon_color) . '"/><path d="M8.21 13.89L7 23l5-3 5 3-1.21-9.12" fill="' . esc_attr($icon_color) . '"/><path d="M9 8l1.5 1.5L14 6" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            case 'certificate':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="4" width="18" height="14" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M7 9h10M7 12h6" stroke="white" stroke-width="1.5" stroke-linecap="round"/><circle cx="16" cy="18" r="3" fill="' . esc_attr($icon_color) . '" stroke="white" stroke-width="1"/></svg>';

            case 'thumbs-up':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" fill="' . esc_attr($icon_color) . '"/></svg>';

            case 'verified':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l2.4 2.4h3.4v3.4L20 10l-2 2.2v3.4h-3.4L12 18l-2.4-2.4H6.2v-3.4L4 10l2-2.2V4.4h3.4L12 2z" fill="' . esc_attr($icon_color) . '"/><path d="M9 10l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            case 'checkbox-square':
            default:
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="22" height="22" rx="4" fill="' . esc_attr($icon_color) . '"/><path d="M7 12.5L10.5 16L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
    }

    /**
     * Get stock badge icon SVG based on settings
     */
    private function get_stock_icon_svg($is_in_stock = true, $size = 18, $is_archive = false) {
        if ($is_in_stock) {
            // Get product page settings
            $icon_type = get_option('wcepi_stock_badge_in_stock_icon_type', 'circle-check');
            $icon_color = get_option('wcepi_stock_badge_in_stock_icon_color', '#16a34a');

            // For archive pages, check archive-specific icon color (falls back to product page if empty)
            if ($is_archive) {
                $archive_icon_color = get_option('wcepi_archive_stock_in_icon_color', '');
                if (!empty($archive_icon_color)) {
                    $icon_color = $archive_icon_color;
                }
            }
        } else {
            // Get product page settings
            $icon_type = get_option('wcepi_stock_badge_out_of_stock_icon_type', 'circle-x');
            $icon_color = get_option('wcepi_stock_badge_out_of_stock_icon_color', '#dc2626');

            // For archive pages, check archive-specific icon color (falls back to product page if empty)
            if ($is_archive) {
                $archive_icon_color = get_option('wcepi_archive_stock_out_icon_color', '');
                if (!empty($archive_icon_color)) {
                    $icon_color = $archive_icon_color;
                }
            }
        }

        if ($icon_type === 'none') {
            return '';
        }

        if ($is_in_stock) {
            // In stock icons
            switch ($icon_type) {
                case 'square-check':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="22" height="22" rx="4" fill="' . esc_attr($icon_color) . '"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                case 'checkmark-only':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 12l5.5 5.5L20 7" stroke="' . esc_attr($icon_color) . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                case 'dot':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="6" fill="' . esc_attr($icon_color) . '"/></svg>';

                case 'truck':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 3H1v13h15V3z" fill="' . esc_attr($icon_color) . '"/><path d="M16 8h4l3 3v5h-7V8z" fill="' . esc_attr($icon_color) . '"/><circle cx="5.5" cy="18.5" r="2.5" fill="' . esc_attr($icon_color) . '" stroke="white" stroke-width="1"/><circle cx="18.5" cy="18.5" r="2.5" fill="' . esc_attr($icon_color) . '" stroke="white" stroke-width="1"/></svg>';

                case 'box':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" fill="' . esc_attr($icon_color) . '"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="white" stroke-width="1.5"/></svg>';

                case 'warehouse':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 20V8l-10-6L2 8v12h20z" fill="' . esc_attr($icon_color) . '"/><rect x="6" y="12" width="4" height="8" fill="white"/><rect x="14" y="12" width="4" height="8" fill="white"/></svg>';

                case 'lightning':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="' . esc_attr($icon_color) . '"/></svg>';

                case 'clock':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M12 6v6l4 2" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';

                case 'thumbs-up':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" fill="' . esc_attr($icon_color) . '"/></svg>';

                case 'circle-check':
                default:
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="' . esc_attr($icon_color) . '"/><path d="M7 12l3.5 3.5L17 9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            }
        } else {
            // Out of stock icons
            switch ($icon_type) {
                case 'square-x':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="22" height="22" rx="4" fill="' . esc_attr($icon_color) . '"/><path d="M8 8l8 8M16 8l-8 8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                case 'x-only':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 6l12 12M18 6l-12 12" stroke="' . esc_attr($icon_color) . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                case 'dot':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="6" fill="' . esc_attr($icon_color) . '"/></svg>';

                case 'clock':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M12 6v6l4 2" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';

                case 'calendar':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="4" width="18" height="18" rx="2" fill="' . esc_attr($icon_color) . '"/><path d="M16 2v4M8 2v4M3 10h18" stroke="white" stroke-width="1.5"/><path d="M8 14h2v2H8zM14 14h2v2h-2z" fill="white"/></svg>';

                case 'alert':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" fill="' . esc_attr($icon_color) . '"/><path d="M12 9v4M12 17h.01" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>';

                case 'ban':
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" fill="' . esc_attr($icon_color) . '"/><path d="M4.93 4.93l14.14 14.14" stroke="white" stroke-width="2"/></svg>';

                case 'circle-x':
                default:
                    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="' . esc_attr($icon_color) . '"/><path d="M8 8l8 8M16 8l-8 8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            }
        }
    }
    
    /**
     * Remove default WooCommerce description output
     */
    public function remove_woocommerce_description_output() {
        // Remove short description from product summary
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
        
        // Remove theme's custom description output (astra-child theme)
        remove_action('woocommerce_after_single_product_summary', 'show_custom_description', 15);
        
        // Keep theme's custom reviews display - it will show below the tabs
        // The theme adds reviews at priority 15 which is after our tabs
    }
    
    /**
     * Add body class for display mode
     */
    public function add_body_class($classes) {
        if (is_product()) {
            $display_mode = get_option('wcepi_display_mode', 'tabs');
            if ($display_mode === 'accordion') {
                $classes[] = 'wcepi-accordion-mode';

                // Auto-expand the description (or first) section on load
                if (get_option('wcepi_accordion_default_open', 'first') === 'first') {
                    $classes[] = 'wcepi-accordion-open-first';
                }
            }

            // Add inline badges mode class
            $inline_layout = get_option('wcepi_badges_inline_layout', 'stacked');
            if ($inline_layout === 'inline') {
                $classes[] = 'wcepi-inline-badges-mode';
            }
        }
        return $classes;
    }
    
    /**
     * Add free shipping badge to price HTML
     */
    public function add_free_shipping_to_price($price, $product) {
        if (get_option('wcepi_enable_free_shipping_badge', 'yes') !== 'yes') {
            return $price;
        }

        // Check context - single product page or archive page
        $is_single = is_product();
        $is_archive = is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy();
        $show_on_archive = get_option('wcepi_archive_show_free_shipping', 'no') === 'yes';

        // Only show on single product pages, or archives if enabled
        if (!$is_single && !($is_archive && $show_on_archive)) {
            return $price;
        }

        // On single product pages, check if free shipping should be shown next to price
        if ($is_single) {
            $badge_positions = WCEPI_Settings::get_badge_positions();
            $free_shipping_position = isset($badge_positions['free_shipping']) ? $badge_positions['free_shipping'] : 'next_to_price';

            // Only show next to price if position is 'next_to_price'
            if ($free_shipping_position !== 'next_to_price') {
                return $price;
            }
        }

        $free_shipping = get_post_meta($product->get_id(), '_wcepi_free_shipping', true);

        // Check if free shipping is enabled for this product (handle both string and potential other types)
        if ($free_shipping == 'yes' || $free_shipping === true || $free_shipping === 1) {
            $text = get_option('wcepi_free_shipping_text', 'Free Shipping');
            if ($is_archive) {
                // Smaller badge for archive pages
                $price .= ' <span class="wcepi-free-shipping-badge wcepi-archive-badge">' . esc_html($text) . '</span>';
            } else {
                // Full badge for single product pages - next to price
                $price .= ' <span class="wcepi-free-shipping-badge">' . esc_html($text) . '</span>';
            }
        }

        return $price;
    }
    
    /**
     * Add warranty info below price (if enabled for product)
     */
    public function add_warranty_to_price($price, $product) {
        if (!$product) {
            return $price;
        }

        // Check context - single product page or archive page
        $is_single = is_product();
        $is_archive = is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy();
        $show_on_archive = get_option('wcepi_archive_show_warranty', 'no') === 'yes';

        // Only show on single product pages, or archives if enabled
        if (!$is_single && !($is_archive && $show_on_archive)) {
            return $price;
        }

        // On single product pages, skip - badges rendered via display_inline_badges_section
        // This allows the position setting to control where badges appear
        if ($is_single) {
            return $price;
        }

        $warranty_lifetime = get_post_meta($product->get_id(), '_wcepi_warranty_lifetime', true);
        $warranty_period = get_post_meta($product->get_id(), '_wcepi_warranty_period', true);
        $warranty_unit = get_post_meta($product->get_id(), '_wcepi_warranty_unit', true);
        $warranty_type = get_post_meta($product->get_id(), '_wcepi_warranty_type', true);

        // Only display if warranty info exists
        if ($warranty_lifetime !== 'yes' && empty($warranty_period)) {
            return $price;
        }

        // Build warranty text - simplified for display (schema remains detailed)
        $warranty_text = '';

        // If warranty type is LifetimeWarranty OR lifetime is checked, show "Lifetime Warranty"
        if ($warranty_type === 'LifetimeWarranty' || $warranty_lifetime === 'yes') {
            $warranty_text = __('Lifetime Warranty', 'wc-enhanced-product-info');
        } elseif (!empty($warranty_period)) {
            // Simple duration display without "Replacement" text
            if ($warranty_unit === 'days') {
                $warranty_text = sprintf(_n('%s-Day Warranty', '%s-Day Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
            } elseif ($warranty_unit === 'months') {
                $warranty_text = sprintf(_n('%s-Month Warranty', '%s-Month Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
            } else {
                $warranty_text = sprintf(_n('%s-Year Warranty', '%s-Year Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
            }
        }

        // Add warranty badge to price with customizable icon
        if (!empty($warranty_text)) {
            $archive_class = $is_archive ? ' wcepi-archive-warranty' : '';
            // Use 18px icons for single product page, 14px for archive
            $icon_size = $is_archive ? 14 : 18;

            // Check if inline layout is enabled (only for single product pages)
            $inline_layout = get_option('wcepi_badges_inline_layout', 'stacked');
            $inline_class = ($inline_layout === 'inline' && $is_single) ? ' wcepi-inline-badge' : '';

            $price .= '<div class="wcepi-warranty-badge-price' . $archive_class . $inline_class . '">';
            $icon_svg = $this->get_warranty_icon_svg($icon_size);
            if (!empty($icon_svg)) {
                $price .= '<span class="wcepi-warranty-icon">' . $icon_svg . '</span>';
            }
            $price .= '<span class="wcepi-warranty-text">' . esc_html($warranty_text) . '</span>';
            $price .= '</div>';
        }

        return $price;
    }

    /**
     * Add stock badge to price HTML (for archive pages)
     * Combines "In Stock" with "Ships in X Days" like the product page does
     */
    public function add_stock_to_price_archive($price, $product) {
        if (!$product) {
            return $price;
        }

        // Only show on archive pages if enabled
        $is_archive = is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy();
        $show_on_archive = get_option('wcepi_archive_show_stock', 'no') === 'yes';

        if (!$is_archive || !$show_on_archive) {
            return $price;
        }

        // Check if stock status feature is enabled
        if (get_option('wcepi_enable_stock_status', 'yes') !== 'yes') {
            return $price;
        }

        if ($product->is_in_stock()) {
            $in_stock_text = get_option('wcepi_in_stock_text', 'In Stock');

            // Check if ships in days should be combined (like on product page)
            $show_ships_in = get_option('wcepi_archive_show_ships_in', 'no') === 'yes';
            $ships_in_days = get_post_meta($product->get_id(), '_wcepi_ships_in_days', true);

            // Combine "In Stock - Ships in X Days" if ships in is enabled and has a value
            if ($show_ships_in && !empty($ships_in_days) && intval($ships_in_days) > 0) {
                $ships_in_text = get_option('wcepi_ships_in_text', 'Ships in %s Days');
                $in_stock_text = $in_stock_text . ' - ' . sprintf($ships_in_text, intval($ships_in_days));
            }

            $price .= '<div class="wcepi-archive-stock wcepi-archive-in-stock">';
            $icon_svg = $this->get_stock_icon_svg(true, 12, true); // true for is_archive
            if (!empty($icon_svg)) {
                $price .= '<span class="wcepi-stock-icon">' . $icon_svg . '</span>';
            }
            $price .= '<span class="wcepi-stock-text">' . esc_html($in_stock_text) . '</span>';
            $price .= '</div>';
        } else {
            $out_of_stock_text = get_option('wcepi_out_of_stock_text', 'Out of Stock');
            $price .= '<div class="wcepi-archive-stock wcepi-archive-out-of-stock">';
            $icon_svg = $this->get_stock_icon_svg(false, 12, true); // true for is_archive
            if (!empty($icon_svg)) {
                $price .= '<span class="wcepi-stock-icon">' . $icon_svg . '</span>';
            }
            $price .= '<span class="wcepi-stock-text">' . esc_html($out_of_stock_text) . '</span>';
            $price .= '</div>';
        }

        return $price;
    }

    /**
     * Add custom badges to price HTML (for archive pages)
     */
    public function add_custom_badges_to_price_archive($price, $product) {
        if (!$product) {
            return $price;
        }

        // Only show on archive pages if enabled
        $is_archive = is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy();
        $show_on_archive = get_option('wcepi_archive_show_custom_badges', 'no') === 'yes';

        if (!$is_archive || !$show_on_archive) {
            return $price;
        }

        // Get badge keys sorted by priority
        $badge_order = WCEPI_Settings::get_badge_order();
        asort($badge_order);
        $badges_order = array_keys($badge_order);
        $custom_badges = WCEPI_Settings::get_custom_badges();

        // Include custom badges that haven't been assigned an order yet
        foreach (array_keys($custom_badges) as $custom_id) {
            if (!in_array('custom_' . $custom_id, $badges_order, true)) {
                $badges_order[] = 'custom_' . $custom_id;
            }
        }

        // Loop through ordered badges and display custom ones
        foreach ($badges_order as $badge_key) {
            if (strpos($badge_key, 'custom_') === 0) {
                $custom_badge_id = str_replace('custom_', '', $badge_key);
                if (isset($custom_badges[$custom_badge_id])) {
                    $badge_data = $custom_badges[$custom_badge_id];

                    // Skip badges disabled globally
                    if (($badge_data['enabled'] ?? 'yes') !== 'yes') {
                        continue;
                    }

                    // Per-product opt-out: hide when meta is explicitly set to 'no'
                    $badge_enabled = get_post_meta($product->get_id(), '_wcepi_custom_badge_' . $custom_badge_id, true);
                    if ($badge_enabled === 'no') {
                        continue;
                    }

                    $badge_html = $this->get_archive_custom_badge_html($custom_badge_id, $badge_data);
                    if (!empty($badge_html)) {
                        $price .= $badge_html;
                    }
                }
            }
        }

        return $price;
    }

    /**
     * Get custom badge HTML for archive pages
     */
    private function get_archive_custom_badge_html($badge_id, $badge_data) {
        // Badges are saved with a 'label' key; fall back to legacy 'text' key
        $text = isset($badge_data['label']) ? $badge_data['label'] : (isset($badge_data['text']) ? $badge_data['text'] : '');
        $icon = isset($badge_data['icon']) ? $badge_data['icon'] : 'none';
        $bg_color = isset($badge_data['bg_color']) ? $badge_data['bg_color'] : '#f8f9fa';
        $text_color = isset($badge_data['text_color']) ? $badge_data['text_color'] : '#1a1a1a';
        $icon_color = isset($badge_data['icon_color']) ? $badge_data['icon_color'] : '#2563eb';
        $border_color = isset($badge_data['border_color']) ? $badge_data['border_color'] : '';

        if (empty($text)) {
            return '';
        }

        // Build inline styles
        $style = 'background-color: ' . esc_attr($bg_color) . '; ';
        $style .= 'color: ' . esc_attr($text_color) . '; ';
        if (!empty($border_color)) {
            $style .= 'border-color: ' . esc_attr($border_color) . '; ';
        }

        $html = '<div class="wcepi-archive-custom-badge" style="' . $style . '">';
        if ($icon !== 'none') {
            $icon_svg = $this->get_custom_badge_icon_svg($icon, $icon_color, 12);
            if (!empty($icon_svg)) {
                $html .= '<span class="wcepi-badge-icon">' . $icon_svg . '</span>';
            }
        }
        $html .= '<span class="wcepi-badge-text">' . esc_html($text) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Display payment methods badge after add to cart button
     */
    public function display_payment_methods_badge() {
        // Check if feature is enabled
        if (get_option('wcepi_enable_payment_methods', 'no') !== 'yes') {
            return;
        }

        // Get selected payment methods
        $payment_methods = get_option('wcepi_payment_methods', array());

        if (empty($payment_methods) || !is_array($payment_methods)) {
            return;
        }

        // Map payment method keys to icon filenames in assets/paymenticons
        $payment_images = array(
            'visa' => 'visa.svg',
            'mastercard' => 'mastercard.svg',
            'amex' => 'american-express.svg',
            'discover' => 'discover.svg',
            'paypal' => 'paypal.svg',
            'apple_pay' => 'apple-pay.svg',
            'google_pay' => 'google-pay.svg',
            'venmo' => 'venmo.svg',
            'afterpay' => 'afterpay.svg',
            'klarna' => 'klarna.svg',
            'stripe' => 'stripe.svg',
            'cash' => 'cash.svg',
            'check' => 'check.svg',
            'bank_transfer' => 'bank-transfer.svg',
            'cirrus' => 'cirrus.svg',
            'maestro' => 'maestro.svg',
            'worldpay' => 'worldpay.svg'
        );

        $images_url = WCEPI_PLUGIN_URL . 'assets/paymenticons/';
        $images_dir = WCEPI_PLUGIN_DIR . 'assets/paymenticons/';

        // Get icon sizes from settings
        $icon_size = get_option('wcepi_payment_icon_size_desktop', '52');
        $tablet_size = get_option('wcepi_payment_icon_size_tablet', '48');
        $mobile_size = get_option('wcepi_payment_icon_size_mobile', '45');

        // Force clear and new line
        echo '<div style="clear:both;width:100%;height:0;"></div>';

        echo '<div class="wcepi-payment-methods-badge" style="display:block!important;width:100%!important;clear:both!important;margin-top:15px!important;">';
        echo '<div class="wcepi-payment-methods-icons">';

        foreach ($payment_methods as $method) {
            // Skip unknown methods and missing icon files rather than render a broken image
            if (!isset($payment_images[$method]) || !file_exists($images_dir . $payment_images[$method])) {
                continue;
            }

            $image_url = $images_url . $payment_images[$method];
            $method_name = ucwords(str_replace('_', ' ', $method));

            echo '<img src="' . esc_url($image_url) . '" ';
            echo 'alt="' . esc_attr($method_name) . '" ';
            echo 'title="' . esc_attr($method_name) . '" ';
            echo 'width="' . esc_attr($icon_size) . '" height="' . esc_attr($icon_size) . '" ';
            echo 'style="width:' . esc_attr($icon_size) . 'px!important;height:' . esc_attr($icon_size) . 'px!important;max-width:' . esc_attr($icon_size) . 'px!important;max-height:' . esc_attr($icon_size) . 'px!important;display:inline-block!important;margin:0 6px!important;object-fit:contain!important;" ';
            echo 'class="wcepi-payment-icon" data-desktop-size="' . esc_attr($icon_size) . '" data-tablet-size="' . esc_attr($tablet_size) . '" data-mobile-size="' . esc_attr($mobile_size) . '" />';
        }

        // Display custom payment methods
        $custom_payment_methods = get_option('wcepi_custom_payment_methods', array());
        if (!empty($custom_payment_methods) && is_array($custom_payment_methods)) {
            foreach ($custom_payment_methods as $custom_method) {
                if (!empty($custom_method['name']) && !empty($custom_method['image'])) {
                    echo '<img src="' . esc_url($custom_method['image']) . '" ';
                    echo 'alt="' . esc_attr($custom_method['name']) . '" ';
                    echo 'title="' . esc_attr($custom_method['name']) . '" ';
                    echo 'width="' . esc_attr($icon_size) . '" height="' . esc_attr($icon_size) . '" ';
                    echo 'style="width:' . esc_attr($icon_size) . 'px!important;height:' . esc_attr($icon_size) . 'px!important;max-width:' . esc_attr($icon_size) . 'px!important;max-height:' . esc_attr($icon_size) . 'px!important;display:inline-block!important;margin:0 6px!important;object-fit:contain!important;" ';
                    echo 'class="wcepi-payment-icon" data-desktop-size="' . esc_attr($icon_size) . '" data-tablet-size="' . esc_attr($tablet_size) . '" data-mobile-size="' . esc_attr($mobile_size) . '" />';
                }
            }
        }

        echo '</div>';
        echo '</div>';
    }

    
    /**
     * Custom stock HTML - complete control over stock display
     */
    public function custom_stock_html($html, $product) {
        if (get_option('wcepi_enable_stock_status', 'yes') !== 'yes') {
            return $html;
        }

        if (!$product instanceof WC_Product) {
            return $html;
        }

        // On single product pages, return empty - stock rendered via display_inline_badges_section
        // This allows the position setting to control where badges appear
        if (is_product()) {
            return ''; // Hide default stock - it's rendered in the badges wrapper
        }

        $show_quantity = get_post_meta($product->get_id(), '_wcepi_show_stock_quantity', true);
        $return_date = get_post_meta($product->get_id(), '_wcepi_return_date', true);
        $ships_in_days = get_post_meta($product->get_id(), '_wcepi_ships_in_days', true);

        // Check if inline layout is enabled
        $inline_layout = get_option('wcepi_badges_inline_layout', 'stacked');
        $inline_class = ($inline_layout === 'inline') ? ' wcepi-inline-badge' : '';

        if ($product->is_in_stock()) {
            $in_stock_text = get_option('wcepi_in_stock_text', 'In Stock');

            // Build stock status with ships in days
            $ships_in_days_int = intval($ships_in_days);
            if ($ships_in_days_int > 0) {
                $ships_in_text = get_option('wcepi_ships_in_text', 'Ships in %s Days');
                $stock_text = $in_stock_text . ' - ' . sprintf($ships_in_text, $ships_in_days_int);
            } else {
                $stock_text = $in_stock_text;
            }

            if (($show_quantity == 'yes' || $show_quantity === true || $show_quantity === 1) && $product->managing_stock()) {
                $stock_quantity = $product->get_stock_quantity();
                $stock_text .= ' (' . $stock_quantity . ' ' . __('available', 'wc-enhanced-product-info') . ')';
            }

            // Build complete HTML with customizable icon
            $html = '<p class="stock wcepi-in-stock' . $inline_class . '">';
            $icon_svg = $this->get_stock_icon_svg(true, 18);
            if (!empty($icon_svg)) {
                $html .= '<span class="wcepi-stock-icon">' . $icon_svg . '</span>';
            }
            $html .= '<span class="wcepi-stock-text">' . esc_html($stock_text) . '</span>';
            $html .= '</p>';
        } else {
            $out_of_stock_text = get_option('wcepi_out_of_stock_text', 'Out of Stock');
            $stock_text = $out_of_stock_text;

            if (!empty($return_date)) {
                $formatted_date = date_i18n(get_option('date_format'), strtotime($return_date));
                $stock_text .= ' - ' . sprintf(__('Expected: %s', 'wc-enhanced-product-info'), $formatted_date);
            }

            // Build complete HTML with customizable icon
            $html = '<p class="stock wcepi-out-of-stock' . $inline_class . '">';
            $icon_svg = $this->get_stock_icon_svg(false, 18);
            if (!empty($icon_svg)) {
                $html .= '<span class="wcepi-stock-icon">' . $icon_svg . '</span>';
            }
            $html .= '<span class="wcepi-stock-text">' . esc_html($stock_text) . '</span>';
            $html .= '</p>';
        }

        return $html;
    }
    
    /**
     * Get tab priorities based on sort order settings
     */
    private function get_tab_priorities($product_id) {
        // Check if product has custom tab order enabled
        $use_custom = get_post_meta($product_id, '_wcepi_use_custom_tab_order', true) === 'yes';

        if ($use_custom) {
            $product_order = get_post_meta($product_id, '_wcepi_tab_order', true);
            if (!empty($product_order) && is_array($product_order)) {
                return $product_order;
            }
        }

        // Fall back to global order
        return WCEPI_Settings::get_tab_order();
    }

    /**
     * Customize product tabs
     */
    public function customize_product_tabs($tabs) {
        $display_mode = get_option('wcepi_display_mode', 'tabs');

        // Remove WooCommerce's default description and additional info tabs (we'll add our own)
        unset($tabs['description']);
        unset($tabs['additional_information']);

        // Only modify tabs if we're using tabs or accordion mode
        if ($display_mode === 'stacked') {
            // In stacked mode, remove reviews too since we don't want tabs at all
            unset($tabs['reviews']);
            return $tabs;
        }

        global $product;

        // Remove reviews tab - reviews will display below all tabs instead
        unset($tabs['reviews']);

        // Get tab priorities from settings (global or per-product)
        $tab_priorities = $this->get_tab_priorities($product->get_id());

        // Add our custom Description tab
        if ($product->get_description()) {
            $label = get_option('wcepi_label_description', 'Description');
            $tabs['description'] = array(
                'title' => !empty($label) ? $label : __('Description', 'wc-enhanced-product-info'),
                'priority' => isset($tab_priorities['description']) ? $tab_priorities['description'] : 10,
                'callback' => array($this, 'description_tab_content')
            );
        }

        // Add custom dimensions tab
        if (get_option('wcepi_enable_custom_dimensions', 'yes') === 'yes') {
            $custom_dimensions = get_post_meta($product->get_id(), '_wcepi_custom_dimensions', true);
            // Show if has WooCommerce dimensions OR custom dimensions array with items
            $has_custom = !empty($custom_dimensions) && is_array($custom_dimensions) && count($custom_dimensions) > 0;
            if ($has_custom || $product->has_dimensions()) {
                $label = get_option('wcepi_label_dimensions', 'Dimensions');
                $tabs['dimensions'] = array(
                    'title' => !empty($label) ? $label : __('Dimensions', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['dimensions']) ? $tab_priorities['dimensions'] : 15,
                    'callback' => array($this, 'dimensions_tab_content')
                );
            }
        }
        
        // Add specifications tab
        if (get_option('wcepi_enable_specifications', 'yes') === 'yes') {
            $specifications = get_post_meta($product->get_id(), '_wcepi_specifications', true);
            // Check if array exists and has items
            if (!empty($specifications) && is_array($specifications) && count($specifications) > 0) {
                $label = get_option('wcepi_label_specifications', 'Specifications');
                $tabs['specifications'] = array(
                    'title' => !empty($label) ? $label : __('Specifications', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['specifications']) ? $tab_priorities['specifications'] : 20,
                    'callback' => array($this, 'specifications_tab_content')
                );
            }
        }

        // Add downloads tab
        if (get_option('wcepi_enable_downloads', 'yes') === 'yes') {
            $downloads = get_post_meta($product->get_id(), '_wcepi_downloads', true);
            // Check if array exists and has items
            if (!empty($downloads) && is_array($downloads) && count($downloads) > 0) {
                $label = get_option('wcepi_label_downloads', 'Downloads/Manuals');
                $tabs['downloads'] = array(
                    'title' => !empty($label) ? $label : __('Downloads/Manuals', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['downloads']) ? $tab_priorities['downloads'] : 25,
                    'callback' => array($this, 'downloads_tab_content')
                );
            }
        }

        // Add shipping tab
        if (get_option('wcepi_enable_shipping_returns', 'yes') === 'yes') {
            // Check if there's custom or global content before showing the tab
            $custom_content = get_post_meta($product->get_id(), '_wcepi_custom_shipping_returns', true);
            $global_content = get_option('wcepi_shipping_returns_content', '');

            if (!empty($custom_content) || !empty($global_content)) {
                $label = get_option('wcepi_label_shipping_returns', 'Shipping');
                $tabs['shipping_returns'] = array(
                    'title' => !empty($label) ? $label : __('Shipping', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['shipping_returns']) ? $tab_priorities['shipping_returns'] : 30,
                    'callback' => array($this, 'shipping_returns_tab_content')
                );
            }
        }

        // Add returns tab
        if (get_option('wcepi_enable_returns', 'yes') === 'yes') {
            // Check if there's custom or global content before showing the tab
            $custom_returns = get_post_meta($product->get_id(), '_wcepi_custom_returns', true);
            $global_returns = get_option('wcepi_returns_content', '');

            if (!empty($custom_returns) || !empty($global_returns)) {
                $label = get_option('wcepi_label_returns', 'Returns');
                $tabs['returns'] = array(
                    'title' => !empty($label) ? $label : __('Returns', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['returns']) ? $tab_priorities['returns'] : 32,
                    'callback' => array($this, 'returns_tab_content')
                );
            }
        }

        // Add warranty tab
        if (get_option('wcepi_enable_warranty', 'yes') === 'yes') {
            $warranty_lifetime = get_post_meta($product->get_id(), '_wcepi_warranty_lifetime', true);
            $warranty_period = get_post_meta($product->get_id(), '_wcepi_warranty_period', true);
            $warranty_url = get_post_meta($product->get_id(), '_wcepi_warranty_url', true);
            $warranty_file = get_post_meta($product->get_id(), '_wcepi_warranty_file', true);
            if ($warranty_lifetime === 'yes' || !empty($warranty_period) || !empty($warranty_url) || !empty($warranty_file)) {
                $label = get_option('wcepi_label_warranty', 'Warranty');
                $tabs['warranty'] = array(
                    'title' => !empty($label) ? $label : __('Warranty', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['warranty']) ? $tab_priorities['warranty'] : 35,
                    'callback' => array($this, 'warranty_tab_content')
                );
            }
        }

        // Add FAQ tab
        if (get_option('wcepi_enable_faq', 'yes') === 'yes') {
            $faqs = get_post_meta($product->get_id(), '_wcepi_faqs', true);
            // Check if array exists and has items
            if (!empty($faqs) && is_array($faqs) && count($faqs) > 0) {
                $label = get_option('wcepi_label_faq', 'FAQ');
                $tabs['faq'] = array(
                    'title' => !empty($label) ? $label : __('FAQ', 'wc-enhanced-product-info'),
                    'priority' => isset($tab_priorities['faq']) ? $tab_priorities['faq'] : 40,
                    'callback' => array($this, 'faq_tab_content')
                );
            }
        }

        // Add custom sections tabs
        if (get_option('wcepi_enable_custom_sections', 'yes') === 'yes') {
            $custom_sections = get_post_meta($product->get_id(), '_wcepi_custom_sections', true);
            if (!empty($custom_sections) && is_array($custom_sections)) {
                $base_priority = isset($tab_priorities['custom_sections']) ? $tab_priorities['custom_sections'] : 45;
                foreach ($custom_sections as $index => $section) {
                    if (!empty($section['name'])) {
                        $tab_key = 'custom_section_' . $index;
                        $tabs[$tab_key] = array(
                            'title' => esc_html($section['name']),
                            'priority' => $base_priority + $index,
                            'callback' => function() use ($section) {
                                $this->custom_section_tab_content($section);
                            }
                        );
                    }
                }
            }
        }
        
        return $tabs;
    }
    
    /**
     * Description tab content
     */
    public function description_tab_content() {
        global $product;
        
        $label = get_option('wcepi_label_description', 'Description');
        echo '<div class="wcepi-description-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Description', 'wc-enhanced-product-info')) . '</h2>';
        
        // Output the product description
        $description = $product->get_description();
        if ($description) {
            echo apply_filters('the_content', $description);
        }
        
        echo '</div>';
    }
    
    /**
     * Dimensions tab content
     */
    public function dimensions_tab_content() {
        global $product;

        // Get table styling settings
        $padding = get_option('wcepi_table_cell_padding', '12');
        $border_color = get_option('wcepi_table_border_color', '#eeeeee');
        $border_width = get_option('wcepi_table_border_width', '1');
        $margin_bottom = get_option('wcepi_table_margin_bottom', '20');

        $cell_style = 'padding: ' . intval($padding) . 'px 15px; border-bottom: ' . intval($border_width) . 'px solid ' . esc_attr($border_color) . '; border-top: none; border-left: none; border-right: none;';
        $last_cell_style = 'padding: ' . intval($padding) . 'px 15px; border: none;';
        $table_style = 'margin-bottom: ' . intval($margin_bottom) . 'px;';

        $label = get_option('wcepi_label_dimensions', 'Dimensions');
        echo '<div class="wcepi-dimensions-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Dimensions', 'wc-enhanced-product-info')) . '</h2>';

        // Default WooCommerce dimensions
        if ($product->has_dimensions()) {
            echo '<table class="wcepi-dimensions-table" style="' . $table_style . '">';

            $rows = array();
            if ($product->get_length()) {
                $rows[] = '<tr><th style="' . $cell_style . '">' . __('Length', 'wc-enhanced-product-info') . '</th><td style="' . $cell_style . '">' .
                     esc_html($product->get_length()) . ' ' . get_option('woocommerce_dimension_unit') . '</td></tr>';
            }
            if ($product->get_width()) {
                $rows[] = '<tr><th style="' . $cell_style . '">' . __('Width', 'wc-enhanced-product-info') . '</th><td style="' . $cell_style . '">' .
                     esc_html($product->get_width()) . ' ' . get_option('woocommerce_dimension_unit') . '</td></tr>';
            }
            if ($product->get_height()) {
                $rows[] = '<tr><th style="' . $cell_style . '">' . __('Height', 'wc-enhanced-product-info') . '</th><td style="' . $cell_style . '">' .
                     esc_html($product->get_height()) . ' ' . get_option('woocommerce_dimension_unit') . '</td></tr>';
            }

            // Output rows, last row without border
            $total_rows = count($rows);
            foreach ($rows as $index => $row) {
                if ($index === $total_rows - 1) {
                    // Last row - no border
                    $row = str_replace($cell_style, $last_cell_style, $row);
                }
                echo $row;
            }

            echo '</table>';
        }

        // Custom dimensions
        $custom_dimensions = get_post_meta($product->get_id(), '_wcepi_custom_dimensions', true);
        if (!empty($custom_dimensions)) {
            echo '<table class="wcepi-dimensions-table wcepi-custom-dimensions" style="' . $table_style . '">';
            $total = count($custom_dimensions);
            $count = 0;
            foreach ($custom_dimensions as $dimension) {
                $count++;
                $style = ($count === $total) ? $last_cell_style : $cell_style;
                echo '<tr><th style="' . $style . '">' . esc_html($dimension['label']) . '</th><td style="' . $style . '">' .
                     esc_html($dimension['value']) . '</td></tr>';
            }
            echo '</table>';
        }

        echo '</div>';
    }
    
    /**
     * Specifications tab content
     */
    public function specifications_tab_content() {
        global $product;

        // Get table styling settings
        $padding = get_option('wcepi_table_cell_padding', '12');
        $border_color = get_option('wcepi_table_border_color', '#eeeeee');
        $border_width = get_option('wcepi_table_border_width', '1');
        $margin_bottom = get_option('wcepi_table_margin_bottom', '20');

        $cell_style = 'padding: ' . intval($padding) . 'px 15px; border-bottom: ' . intval($border_width) . 'px solid ' . esc_attr($border_color) . '; border-top: none; border-left: none; border-right: none;';
        $last_cell_style = 'padding: ' . intval($padding) . 'px 15px; border: none;';
        $table_style = 'margin-bottom: ' . intval($margin_bottom) . 'px;';

        $specifications = get_post_meta($product->get_id(), '_wcepi_specifications', true);

        $label = get_option('wcepi_label_specifications', 'Specifications');
        echo '<div class="wcepi-specifications-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Product Specifications', 'wc-enhanced-product-info')) . '</h2>';

        if (!empty($specifications)) {
            echo '<table class="wcepi-specifications-table" style="' . $table_style . '">';
            $total = count($specifications);
            $count = 0;
            foreach ($specifications as $spec) {
                $count++;
                $style = ($count === $total) ? $last_cell_style : $cell_style;
                $value_html = esc_html($spec['value']);
                if (!empty($spec['url'])) {
                    $value_html = '<a href="' . esc_url($spec['url']) . '" target="_blank" rel="noopener nofollow">' . $value_html . '</a>';
                }
                echo '<tr><th style="' . $style . '">' . esc_html($spec['label']) . '</th><td style="' . $style . '">' .
                     $value_html . '</td></tr>';
            }
            echo '</table>';
        }

        echo '</div>';
    }
    
    /**
     * Downloads tab content
     */
    public function downloads_tab_content() {
        global $product;
        
        $downloads = get_post_meta($product->get_id(), '_wcepi_downloads', true);
        
        $label = get_option('wcepi_label_downloads', 'Downloads/Manuals');
        echo '<div class="wcepi-downloads-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Downloads & Manuals', 'wc-enhanced-product-info')) . '</h2>';
        
        if (!empty($downloads)) {
            echo '<ul class="wcepi-downloads-list">';
            foreach ($downloads as $download) {
                echo '<li>';
                echo '<a href="' . esc_url($download['url']) . '" target="_blank" class="wcepi-download-link">';
                echo '<span class="wcepi-download-icon">📄</span>';
                echo '<span class="wcepi-download-title">' . esc_html($download['title']) . '</span>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }
        
        echo '</div>';
    }
    
    /**
     * Shipping tab content
     */
    public function shipping_returns_tab_content() {
        global $product;
        
        $label = get_option('wcepi_label_shipping_returns', 'Shipping');
        echo '<div class="wcepi-shipping-returns-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Shipping', 'wc-enhanced-product-info')) . '</h2>';
        
        // Check for custom content first
        $custom_content = get_post_meta($product->get_id(), '_wcepi_custom_shipping_returns', true);
        
        if (!empty($custom_content)) {
            echo wp_kses_post($custom_content);
        } else {
            // Use global template
            $global_content = get_option('wcepi_shipping_returns_content', '');
            if (!empty($global_content)) {
                echo wp_kses_post($global_content);
            } else {
                echo '<p>' . __('No shipping information available.', 'wc-enhanced-product-info') . '</p>';
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Returns tab content
     */
    public function returns_tab_content() {
        global $product;
        
        $label = get_option('wcepi_label_returns', 'Returns');
        echo '<div class="wcepi-returns-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Returns', 'wc-enhanced-product-info')) . '</h2>';
        
        // Check for custom content first
        $custom_content = get_post_meta($product->get_id(), '_wcepi_custom_returns', true);
        
        if (!empty($custom_content)) {
            echo wp_kses_post($custom_content);
        } else {
            // Use global template
            $global_content = get_option('wcepi_returns_content', '');
            if (!empty($global_content)) {
                echo wp_kses_post($global_content);
            } else {
                echo '<p>' . __('No returns information available.', 'wc-enhanced-product-info') . '</p>';
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Warranty tab content
     */
    public function warranty_tab_content() {
        global $product;
        
        $warranty_lifetime = get_post_meta($product->get_id(), '_wcepi_warranty_lifetime', true);
        $warranty_period = get_post_meta($product->get_id(), '_wcepi_warranty_period', true);
        $warranty_unit = get_post_meta($product->get_id(), '_wcepi_warranty_unit', true);
        $warranty_type = get_post_meta($product->get_id(), '_wcepi_warranty_type', true);
        $warranty_url = get_post_meta($product->get_id(), '_wcepi_warranty_url', true);
        $warranty_url_text = get_post_meta($product->get_id(), '_wcepi_warranty_url_text', true);
        $warranty_file = get_post_meta($product->get_id(), '_wcepi_warranty_file', true);
        $warranty_file_text = get_post_meta($product->get_id(), '_wcepi_warranty_file_text', true);
        $warranty_content = get_post_meta($product->get_id(), '_wcepi_warranty_content', true);
        
        $label = get_option('wcepi_label_warranty', 'Warranty');
        echo '<div class="wcepi-warranty-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Warranty Information', 'wc-enhanced-product-info')) . '</h2>';
        
        // Display warranty period - simplified for display (schema remains detailed)
        if ($warranty_lifetime === 'yes' || !empty($warranty_period)) {
            echo '<p class="wcepi-warranty-period">';
            echo '<strong>' . __('Warranty Period:', 'wc-enhanced-product-info') . '</strong> ';
            
            // Build simple duration text
            $duration_text = '';
            
            // If warranty type is LifetimeWarranty OR lifetime is checked, show "Lifetime Warranty"
            if ($warranty_type === 'LifetimeWarranty' || $warranty_lifetime === 'yes') {
                $duration_text = __('Lifetime Warranty', 'wc-enhanced-product-info');
            } elseif (!empty($warranty_period)) {
                // Simple duration display without technical warranty type
                if ($warranty_unit === 'days') {
                    $duration_text = sprintf(_n('%s-day Warranty', '%s-day Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
                } elseif ($warranty_unit === 'months') {
                    $duration_text = sprintf(_n('%s-month Warranty', '%s-month Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
                } else {
                    $duration_text = sprintf(_n('%s-year Warranty', '%s-year Warranty', $warranty_period, 'wc-enhanced-product-info'), $warranty_period);
                }
            }
            
            echo $duration_text;
            echo '</p>';
        }
        
        // Display warranty policy link with custom text
        if (!empty($warranty_url)) {
            $url_button_text = !empty($warranty_url_text) ? $warranty_url_text : __('View Full Warranty Policy', 'wc-enhanced-product-info');
            echo '<p class="wcepi-warranty-link">';
            echo '<a href="' . esc_url($warranty_url) . '" target="_blank" class="button">';
            echo esc_html($url_button_text);
            echo '</a>';
            echo '</p>';
        }
        
        // Display warranty file with custom text
        if (!empty($warranty_file)) {
            $file_button_text = !empty($warranty_file_text) ? $warranty_file_text : __('Download Warranty Document', 'wc-enhanced-product-info');
            echo '<p class="wcepi-warranty-file">';
            echo '<a href="' . esc_url($warranty_file) . '" target="_blank" class="button">';
            echo '<span class="dashicons dashicons-media-document"></span> ';
            echo esc_html($file_button_text);
            echo '</a>';
            echo '</p>';
        }
        
        // Display warranty content
        if (!empty($warranty_content)) {
            echo '<div class="wcepi-warranty-additional-content">';
            echo wp_kses_post($warranty_content);
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * FAQ tab content
     */
    public function faq_tab_content() {
        global $product;
        
        $faqs = get_post_meta($product->get_id(), '_wcepi_faqs', true);
        
        $label = get_option('wcepi_label_faq', 'FAQ');
        echo '<div class="wcepi-faq-content">';
        echo '<h2>' . (!empty($label) ? esc_html($label) : __('Frequently Asked Questions', 'wc-enhanced-product-info')) . '</h2>';
        
        if (!empty($faqs)) {
            // Output FAQ Schema markup
            $this->output_faq_schema($faqs, $product);
            
            echo '<div class="wcepi-faq-list">';
            foreach ($faqs as $index => $faq) {
                echo '<div class="wcepi-faq-item">';
                echo '<div class="wcepi-faq-question"><strong>' . esc_html($faq['question']) . '</strong></div>';
                echo '<div class="wcepi-faq-answer">' . wpautop(esc_html($faq['answer'])) . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Output FAQ Schema markup for SEO
     */
    private function output_faq_schema($faqs, $product) {
        if (empty($faqs) || !is_array($faqs)) {
            return;
        }
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array()
        );
        
        foreach ($faqs as $faq) {
            if (!empty($faq['question']) && !empty($faq['answer'])) {
                $schema['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name' => wp_strip_all_tags($faq['question']),
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => wp_strip_all_tags($faq['answer'])
                    )
                );
            }
        }
        
        // Only output if we have at least one valid FAQ
        if (!empty($schema['mainEntity'])) {
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
        }
    }
    
    /**
     * Custom section tab content
     */
    public function custom_section_tab_content($section) {
        // Get table styling settings
        $padding = get_option('wcepi_table_cell_padding', '12');
        $border_color = get_option('wcepi_table_border_color', '#eeeeee');
        $border_width = get_option('wcepi_table_border_width', '1');
        $margin_bottom = get_option('wcepi_table_margin_bottom', '20');

        $cell_style = 'padding: ' . intval($padding) . 'px 15px; border-bottom: ' . intval($border_width) . 'px solid ' . esc_attr($border_color) . '; border-top: none; border-left: none; border-right: none;';
        $last_cell_style = 'padding: ' . intval($padding) . 'px 15px; border: none;';
        $table_style = 'margin-bottom: ' . intval($margin_bottom) . 'px;';

        echo '<div class="wcepi-custom-section-content">';
        echo '<h2>' . esc_html($section['name']) . '</h2>';

        if ($section['type'] === 'fields' && !empty($section['fields'])) {
            echo '<table class="wcepi-custom-section-table" style="' . $table_style . '">';
            $total = count($section['fields']);
            $count = 0;
            foreach ($section['fields'] as $field) {
                $count++;
                $style = ($count === $total) ? $last_cell_style : $cell_style;
                echo '<tr><th style="' . $style . '">' . esc_html($field['label']) . '</th><td style="' . $style . '">' .
                     esc_html($field['value']) . '</td></tr>';
            }
            echo '</table>';
        } elseif ($section['type'] === 'textarea' && !empty($section['content'])) {
            echo wp_kses_post($section['content']);
        }

        echo '</div>';
    }
    
    /**
     * Display stacked content (when not using tabs)
     */
    public function display_stacked_content() {
        $display_mode = get_option('wcepi_display_mode', 'tabs');
        
        if ($display_mode !== 'stacked') {
            return;
        }
        
        global $product;
        
        echo '<div class="wcepi-stacked-content">';
        
        // Description - display first
        if ($product->get_description()) {
            $this->description_tab_content();
        }
        
        // Dimensions
        if (get_option('wcepi_enable_custom_dimensions', 'yes') === 'yes') {
            $custom_dimensions = get_post_meta($product->get_id(), '_wcepi_custom_dimensions', true);
            // Show if has WooCommerce dimensions OR custom dimensions array with items
            $has_custom = !empty($custom_dimensions) && is_array($custom_dimensions) && count($custom_dimensions) > 0;
            if ($has_custom || $product->has_dimensions()) {
                $this->dimensions_tab_content();
            }
        }
        
        // Specifications
        if (get_option('wcepi_enable_specifications', 'yes') === 'yes') {
            $specifications = get_post_meta($product->get_id(), '_wcepi_specifications', true);
            // Check if array exists and has items
            if (!empty($specifications) && is_array($specifications) && count($specifications) > 0) {
                $this->specifications_tab_content();
            }
        }
        
        // Downloads
        if (get_option('wcepi_enable_downloads', 'yes') === 'yes') {
            $downloads = get_post_meta($product->get_id(), '_wcepi_downloads', true);
            // Check if array exists and has items
            if (!empty($downloads) && is_array($downloads) && count($downloads) > 0) {
                $this->downloads_tab_content();
            }
        }
        
        // Shipping & Returns
        if (get_option('wcepi_enable_shipping_returns', 'yes') === 'yes') {
            // Check if there's custom or global content before showing
            $custom_content = get_post_meta($product->get_id(), '_wcepi_custom_shipping_returns', true);
            $global_content = get_option('wcepi_shipping_returns_content', '');
            
            if (!empty($custom_content) || !empty($global_content)) {
                $this->shipping_returns_tab_content();
            }
        }
        
        // Warranty
        if (get_option('wcepi_enable_warranty', 'yes') === 'yes') {
            $warranty_lifetime = get_post_meta($product->get_id(), '_wcepi_warranty_lifetime', true);
            $warranty_period = get_post_meta($product->get_id(), '_wcepi_warranty_period', true);
            $warranty_url = get_post_meta($product->get_id(), '_wcepi_warranty_url', true);
            $warranty_file = get_post_meta($product->get_id(), '_wcepi_warranty_file', true);
            if ($warranty_lifetime === 'yes' || !empty($warranty_period) || !empty($warranty_url) || !empty($warranty_file)) {
                $this->warranty_tab_content();
            }
        }
        
        // FAQ
        if (get_option('wcepi_enable_faq', 'yes') === 'yes') {
            $faqs = get_post_meta($product->get_id(), '_wcepi_faqs', true);
            // Check if array exists and has items
            if (!empty($faqs) && is_array($faqs) && count($faqs) > 0) {
                $this->faq_tab_content();
            }
        }
        
        // Custom Sections
        if (get_option('wcepi_enable_custom_sections', 'yes') === 'yes') {
            $custom_sections = get_post_meta($product->get_id(), '_wcepi_custom_sections', true);
            if (!empty($custom_sections) && is_array($custom_sections)) {
                foreach ($custom_sections as $section) {
                    if (!empty($section['name'])) {
                        $this->custom_section_tab_content($section);
                    }
                }
            }
        }

        echo '</div>'; // Close .wcepi-stacked-content
    }

    /**
     * Output comprehensive product schema markup in product page head
     * Includes: Product info, Offers, Warranty, Shipping, Returns, Stock, Reviews
     */
    public function output_warranty_schema() {
        if (!is_product()) {
            return;
        }

        // Allow disabling when an SEO plugin already outputs Product schema
        if (get_option('wcepi_enable_product_schema', 'yes') !== 'yes') {
            return;
        }

        global $product;
        if (!$product) {
            return;
        }

        $product_id = $product->get_id();

        // Get all product meta
        $warranty_lifetime = get_post_meta($product_id, '_wcepi_warranty_lifetime', true);
        $warranty_period = get_post_meta($product_id, '_wcepi_warranty_period', true);
        $warranty_unit = get_post_meta($product_id, '_wcepi_warranty_unit', true);
        $warranty_type = get_post_meta($product_id, '_wcepi_warranty_type', true);
        $free_shipping = get_post_meta($product_id, '_wcepi_free_shipping', true);
        $ships_in_days = get_post_meta($product_id, '_wcepi_ships_in_days', true);

        // Build base Product schema
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => get_permalink($product_id) . '#wcepi-product',
            'name' => $product->get_name(),
            'url' => get_permalink($product_id),
        );

        // Add product description
        $description = $product->get_short_description();
        if (empty($description)) {
            $description = $product->get_description();
        }
        if (!empty($description)) {
            $schema['description'] = wp_strip_all_tags($description);
        }

        // Add SKU
        $sku = $product->get_sku();
        if (!empty($sku)) {
            $schema['sku'] = $sku;
        }

        // Add product image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $schema['image'] = $image_url;
            }
        }

        // Add brand (from product attributes or global setting)
        $brand = $this->get_product_brand($product);
        if (!empty($brand)) {
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name' => $brand
            );
        }

        // Add GTIN/MPN if available
        $gtin = get_post_meta($product_id, '_gtin', true);
        if (empty($gtin)) {
            $gtin = get_post_meta($product_id, '_global_unique_id', true); // WooCommerce default
        }
        if (!empty($gtin)) {
            $schema['gtin'] = $gtin;
        }

        $mpn = get_post_meta($product_id, '_mpn', true);
        if (!empty($mpn)) {
            $schema['mpn'] = $mpn;
        }

        // Build Offers schema
        $offer = array(
            '@type' => 'Offer',
            'url' => get_permalink($product_id),
            'priceCurrency' => get_woocommerce_currency(),
            'price' => $product->get_price(),
            'priceValidUntil' => date('Y-m-d', strtotime('+1 year')),
            'itemCondition' => 'https://schema.org/NewCondition',
        );

        // Availability
        if ($product->is_in_stock()) {
            $offer['availability'] = 'https://schema.org/InStock';

            // Add stock quantity if managed
            if ($product->managing_stock()) {
                $stock_qty = $product->get_stock_quantity();
                if ($stock_qty !== null && $stock_qty > 0) {
                    $offer['inventoryLevel'] = array(
                        '@type' => 'QuantitativeValue',
                        'value' => $stock_qty
                    );
                }
            }
        } else {
            if ($product->is_on_backorder()) {
                $offer['availability'] = 'https://schema.org/BackOrder';
            } else {
                $offer['availability'] = 'https://schema.org/OutOfStock';
            }
        }

        // Seller information
        $offer['seller'] = array(
            '@type' => 'Organization',
            'name' => get_bloginfo('name'),
            'url' => home_url()
        );

        // Shipping Details
        $shipping_details = $this->build_shipping_schema($product, $free_shipping, $ships_in_days);
        if (!empty($shipping_details)) {
            $offer['shippingDetails'] = $shipping_details;
        }

        // Return Policy (MerchantReturnPolicy) — uses the global return window setting.
        // (The per-product _wcepi_return_date meta is a restock date, not a return window.)
        $return_policy = $this->build_return_policy_schema($product, '');
        if (!empty($return_policy)) {
            $offer['hasMerchantReturnPolicy'] = $return_policy;
        }

        // Warranty
        $warranty = $this->build_warranty_schema($warranty_lifetime, $warranty_period, $warranty_unit, $warranty_type);
        if (!empty($warranty)) {
            $offer['warranty'] = $warranty;
        }

        $schema['offers'] = $offer;

        // Add aggregate rating if reviews exist
        $review_count = $product->get_review_count();
        $average_rating = $product->get_average_rating();
        if ($review_count > 0 && $average_rating > 0) {
            $schema['aggregateRating'] = array(
                '@type' => 'AggregateRating',
                'ratingValue' => $average_rating,
                'reviewCount' => $review_count,
                'bestRating' => '5',
                'worstRating' => '1'
            );
        }

        // Output the schema
        echo "\n" . '<!-- WC Enhanced Product Info - Product Schema -->' . "\n";
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo "\n" . '</script>' . "\n";
        echo '<!-- /WC Enhanced Product Info - Product Schema -->' . "\n";
    }

    /**
     * Get product brand from taxonomies, attributes, or settings
     */
    private function get_product_brand($product) {
        $product_id = $product->get_id();

        // Check WooCommerce Brands plugin taxonomy (product_brand)
        $brands = get_the_terms($product_id, 'product_brand');
        if (!is_wp_error($brands) && !empty($brands)) {
            return $brands[0]->name;
        }

        // Check Perfect Brands for WooCommerce taxonomy (pwb-brand)
        $pwb_brands = get_the_terms($product_id, 'pwb-brand');
        if (!is_wp_error($pwb_brands) && !empty($pwb_brands)) {
            return $pwb_brands[0]->name;
        }

        // Check YITH Brands taxonomy (yith_product_brand)
        $yith_brands = get_the_terms($product_id, 'yith_product_brand');
        if (!is_wp_error($yith_brands) && !empty($yith_brands)) {
            return $yith_brands[0]->name;
        }

        // Check for brand attribute
        $brand = $product->get_attribute('brand');
        if (!empty($brand)) {
            return $brand;
        }

        // Check for pa_brand taxonomy attribute
        $brand = $product->get_attribute('pa_brand');
        if (!empty($brand)) {
            return $brand;
        }

        // Check for manufacturer attribute
        $manufacturer = $product->get_attribute('manufacturer');
        if (!empty($manufacturer)) {
            return $manufacturer;
        }

        // Fallback to global setting
        $global_brand = get_option('wcepi_schema_brand', '');
        if (!empty($global_brand)) {
            return $global_brand;
        }

        // No brand found - return empty (don't use site name as brand)
        return '';
    }

    /**
     * Build shipping schema (OfferShippingDetails)
     */
    private function build_shipping_schema($product, $free_shipping, $ships_in_days) {
        $shipping = array(
            '@type' => 'OfferShippingDetails',
        );

        // Shipping destination (default to US, can be extended)
        $shipping['shippingDestination'] = array(
            '@type' => 'DefinedRegion',
            'addressCountry' => get_option('wcepi_schema_shipping_country', 'US')
        );

        // Shipping rate
        if ($free_shipping === 'yes') {
            $shipping['shippingRate'] = array(
                '@type' => 'MonetaryAmount',
                'value' => '0',
                'currency' => get_woocommerce_currency()
            );
        } else {
            // Get shipping cost from settings or use default
            $shipping_cost = get_option('wcepi_schema_shipping_cost', '');
            if (!empty($shipping_cost)) {
                $shipping['shippingRate'] = array(
                    '@type' => 'MonetaryAmount',
                    'value' => $shipping_cost,
                    'currency' => get_woocommerce_currency()
                );
            }
        }

        // Delivery time (handling + transit)
        if (!empty($ships_in_days)) {
            $handling_days = intval($ships_in_days);
            $transit_min = intval(get_option('wcepi_schema_transit_time_min', '3'));
            $transit_max = intval(get_option('wcepi_schema_transit_time_max', '7'));

            $shipping['deliveryTime'] = array(
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => array(
                    '@type' => 'QuantitativeValue',
                    'minValue' => 0,
                    'maxValue' => $handling_days,
                    'unitCode' => 'DAY'
                ),
                'transitTime' => array(
                    '@type' => 'QuantitativeValue',
                    'minValue' => $transit_min,
                    'maxValue' => $transit_max,
                    'unitCode' => 'DAY'
                )
            );
        }

        return $shipping;
    }

    /**
     * Build return policy schema (MerchantReturnPolicy)
     */
    private function build_return_policy_schema($product, $return_days) {
        // Use product-specific return days or global setting
        if (empty($return_days)) {
            $return_days = get_option('wcepi_schema_return_days', '30');
        }

        if (empty($return_days) || intval($return_days) <= 0) {
            return null;
        }

        $return_policy = array(
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => get_option('wcepi_schema_shipping_country', 'US'),
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => intval($return_days),
            'returnMethod' => 'https://schema.org/ReturnByMail',
        );

        // Return shipping fees (who pays for return shipping)
        $return_fees = get_option('wcepi_schema_return_fees', 'FreeReturn');
        switch ($return_fees) {
            case 'FreeReturn':
                $return_policy['returnFees'] = 'https://schema.org/FreeReturn';
                break;
            case 'ReturnShippingFees':
                $return_policy['returnFees'] = 'https://schema.org/ReturnShippingFees';
                break;
            case 'RestockingFees':
                $return_policy['returnFees'] = 'https://schema.org/RestockingFees';
                break;
            default:
                $return_policy['returnFees'] = 'https://schema.org/FreeReturn';
        }

        return $return_policy;
    }

    /**
     * Build warranty schema (WarrantyPromise)
     */
    private function build_warranty_schema($warranty_lifetime, $warranty_period, $warranty_unit, $warranty_type) {
        // Calculate duration in ISO 8601 format
        $duration = '';
        if ($warranty_lifetime === 'yes') {
            $duration = 'P100Y'; // Lifetime warranty
        } else if (!empty($warranty_period)) {
            $period_value = intval($warranty_period);
            if ($period_value > 0) {
                switch ($warranty_unit) {
                    case 'days':
                        $duration = 'P' . $period_value . 'D';
                        break;
                    case 'months':
                        $duration = 'P' . $period_value . 'M';
                        break;
                    case 'years':
                    default:
                        $duration = 'P' . $period_value . 'Y';
                        break;
                }
            }
        }

        if (empty($duration)) {
            return null;
        }

        // Default warranty type
        if (empty($warranty_type)) {
            $warranty_type = 'LimitedWarranty';
        }

        return array(
            '@type' => 'WarrantyPromise',
            'durationOfWarranty' => array(
                '@type' => 'QuantitativeValue',
                'value' => $duration,
                'unitCode' => 'ANN' // Annual for ISO 8601 duration
            ),
            'warrantyScope' => $warranty_type
        );
    }
}