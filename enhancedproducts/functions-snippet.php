<?php
/**
 * Temporary Functions.php Snippet
 * Add this to your theme's functions.php file temporarily
 * 
 * INSTRUCTIONS:
 * 1. Copy the code between the START and END markers
 * 2. Paste at the bottom of your theme's functions.php file
 * 3. Use the URL commands below
 * 4. REMOVE this code after fixing the issue!
 */

// ========== START: COPY FROM HERE ==========

/**
 * Quick Cache Clear for Specific Product
 * Visit: https://yoursite.com/?clear_wcepi_cache=1&product_id=XXXX
 */
add_action('init', function() {
    if (!isset($_GET['clear_wcepi_cache']) || !current_user_can('manage_options')) {
        return;
    }
    
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    
    if (!$product_id) {
        wp_die('Please provide product_id parameter');
    }
    
    // Clear WooCommerce transients
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
    
    // Clear WP Rocket cache
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($product_id);
    }
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
    
    // Clear Elementor cache
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    
    // Flush cache
    wp_cache_flush();
    
    // Clear product meta cache
    wp_cache_delete($product_id, 'post_meta');
    wp_cache_delete($product_id, 'posts');
    
    wp_die('<h2>Cache Cleared!</h2><p>Product ID: ' . $product_id . '</p><p>Now test on mobile: <a href="' . get_permalink($product_id) . '">' . get_permalink($product_id) . '</a></p>');
}, 1);

/**
 * Debug Product Data in HTML Comments
 * Adds HTML comments to footer showing what data exists
 */
add_action('wp_footer', function() {
    if (!is_product()) {
        return;
    }
    
    global $product;
    $product_id = $product->get_id();
    
    echo "\n" . '<!-- WCEPI DEBUG INFO -->' . "\n";
    echo '<!-- Product ID: ' . $product_id . ' -->' . "\n";
    echo '<!-- User Agent: ' . (isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 50) : 'Unknown') . ' -->' . "\n";
    echo '<!-- Is Mobile (WP): ' . (wp_is_mobile() ? 'YES' : 'NO') . ' -->' . "\n";
    echo '<!-- Warranty Period: ' . get_post_meta($product_id, '_wcepi_warranty_period', true) . ' -->' . "\n";
    echo '<!-- Ships In Days: ' . get_post_meta($product_id, '_wcepi_ships_in_days', true) . ' -->' . "\n";
    echo '<!-- Free Shipping: ' . get_post_meta($product_id, '_wcepi_free_shipping', true) . ' -->' . "\n";
    echo '<!-- Custom Shipping Returns: ' . (get_post_meta($product_id, '_wcepi_custom_shipping_returns', true) ? 'YES' : 'NO') . ' -->' . "\n";
    echo '<!-- Display Mode: ' . get_option('wcepi_display_mode', 'tabs') . ' -->' . "\n";
    echo '<!-- Plugin Active: ' . (class_exists('WCEPI_Frontend') ? 'YES' : 'NO') . ' -->' . "\n";
    echo '<!-- END WCEPI DEBUG -->' . "\n";
}, 999);

/**
 * Force Mobile Cache Bypass Test
 * Visit: https://yoursite.com/product/your-product/?bypass_cache=1
 */
add_action('template_redirect', function() {
    if (isset($_GET['bypass_cache']) && is_product()) {
        // Send no-cache headers
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Disable WP Rocket for this request
        if (!defined('DONOTROCKETOPTIMIZE')) {
            define('DONOTROCKETOPTIMIZE', true);
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }
}, 1);

// ========== END: COPY UP TO HERE ==========

/**
 * USAGE INSTRUCTIONS:
 * 
 * 1. Clear Specific Product Cache:
 *    https://extcabinets.com/?clear_wcepi_cache=1&product_id=YOUR_PRODUCT_ID
 * 
 * 2. Test Product Without Cache:
 *    https://extcabinets.com/shop/outdoor-grills/your-product/?bypass_cache=1
 * 
 * 3. View Debug Info:
 *    View page source on mobile and look for "WCEPI DEBUG INFO" comments
 *    near the end of the HTML
 * 
 * 4. After fixing, REMOVE this code from functions.php!
 */