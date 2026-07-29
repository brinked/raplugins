<?php
/**
 * Product Cache Clearing Utility
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to your WordPress root directory (same folder as wp-config.php)
 * 2. Visit: https://yoursite.com/clear-product-cache.php?product_id=XXXX
 * 3. Replace XXXX with the actual product ID
 * 4. DELETE this file after use for security!
 * 
 * For the specific product:
 * https://yoursite.com/clear-product-cache.php?product_id=PRODUCT_ID_HERE
 */

// Load WordPress
require_once('wp-load.php');

// Security check - only allow admin users
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page. Please log in as an administrator.');
}

// Get product ID from URL
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

if (!$product_id) {
    wp_die('Please provide a product ID: ?product_id=XXXX');
}

// Check if product exists
$product = wc_get_product($product_id);
if (!$product) {
    wp_die('Product ID ' . $product_id . ' not found.');
}

echo '<h1>Product Cache Clearing Utility</h1>';
echo '<h2>Product: ' . $product->get_name() . ' (ID: ' . $product_id . ')</h2>';
echo '<hr>';

$results = array();

// 1. Clear WooCommerce product transients
if (function_exists('wc_delete_product_transients')) {
    wc_delete_product_transients($product_id);
    $results[] = '✓ WooCommerce product transients cleared';
} else {
    $results[] = '✗ WooCommerce function not available';
}

// 2. Clear WP Rocket cache for this post
if (function_exists('rocket_clean_post')) {
    rocket_clean_post($product_id);
    $results[] = '✓ WP Rocket post cache cleared';
} else {
    $results[] = '⚠ WP Rocket not active or rocket_clean_post not available';
}

// 3. Clear WP Rocket domain cache
if (function_exists('rocket_clean_domain')) {
    rocket_clean_domain();
    $results[] = '✓ WP Rocket domain cache cleared';
}

// 4. Clear WP Rocket mobile cache
if (function_exists('rocket_clean_files')) {
    rocket_clean_files(get_permalink($product_id));
    $results[] = '✓ WP Rocket files cleared for product URL';
}

// 5. Clear Elementor cache
if (class_exists('\Elementor\Plugin')) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    $results[] = '✓ Elementor cache cleared';
} else {
    $results[] = '⚠ Elementor not active';
}

// 6. Clear WordPress object cache
wp_cache_flush();
$results[] = '✓ WordPress object cache flushed';

// 7. Delete all product-related transients
global $wpdb;
$transients_deleted = $wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE %s 
        OR option_name LIKE %s",
        '%_transient_wc_product_' . $product_id . '%',
        '%_transient_timeout_wc_product_' . $product_id . '%'
    )
);
$results[] = '✓ Product-specific transients deleted: ' . $transients_deleted;

// 8. Clear product meta cache
wp_cache_delete($product_id, 'post_meta');
wp_cache_delete($product_id, 'posts');
$results[] = '✓ Product meta cache cleared';

// 9. Force product data refresh
$product = wc_get_product($product_id);
if ($product) {
    $product->save();
    $results[] = '✓ Product data refreshed';
}

// Display results
echo '<h3>Actions Performed:</h3>';
echo '<ul>';
foreach ($results as $result) {
    echo '<li>' . $result . '</li>';
}
echo '</ul>';

echo '<hr>';
echo '<h3>Next Steps:</h3>';
echo '<ol>';
echo '<li><strong>Clear your mobile browser cache</strong></li>';
echo '<li><strong>Test the product page on mobile</strong>: <a href="' . get_permalink($product_id) . '" target="_blank">' . get_permalink($product_id) . '</a></li>';
echo '<li><strong>View mobile source code</strong> to verify HTML is present</li>';
echo '<li><strong>If still not working</strong>, try re-saving the product in WordPress admin</li>';
echo '<li><strong>DELETE THIS FILE</strong> after use for security!</li>';
echo '</ol>';

echo '<hr>';
echo '<p><strong>Product Information Found:</strong></p>';
echo '<ul>';
echo '<li>Warranty Period: ' . get_post_meta($product_id, '_wcepi_warranty_period', true) . '</li>';
echo '<li>Ships In Days: ' . get_post_meta($product_id, '_wcepi_ships_in_days', true) . '</li>';
echo '<li>Free Shipping: ' . get_post_meta($product_id, '_wcepi_free_shipping', true) . '</li>';
echo '<li>Warranty Content: ' . (get_post_meta($product_id, '_wcepi_warranty_content', true) ? 'YES' : 'NO') . '</li>';
echo '<li>Shipping Returns: ' . (get_post_meta($product_id, '_wcepi_custom_shipping_returns', true) ? 'Custom' : 'Global') . '</li>';
echo '</ul>';

echo '<hr>';
echo '<p style="color: red;"><strong>SECURITY WARNING: Delete this file immediately after use!</strong></p>';
echo '<p>File location: ' . __FILE__ . '</p>';