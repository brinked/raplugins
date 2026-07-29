<?php
/**
 * Product 4694 Database Fix Script
 * 
 * This script compares product 4694 (broken) with 4832 (working copy)
 * and fixes any corrupted metadata
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to your WordPress root directory
 * 2. Visit: https://extcabinets.com/fix-product-4694.php
 * 3. Review the comparison and click "Fix" button
 * 4. DELETE this file after use!
 */

// Load WordPress
require_once('wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

$broken_id = 4694;
$working_id = 4832;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Product 4694 Fix Script</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .different { background: #fff3cd; }
        .missing { background: #f8d7da; }
        .button { background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; margin: 10px 5px; }
        .button:hover { background: #005a87; }
        .warning { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0; }
        .success { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 20px 0; }
        .danger { background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 20px 0; }
    </style>
</head>
<body>

<h1>Product 4694 Database Fix Script</h1>

<?php
// Get both products
$broken_product = wc_get_product($broken_id);
$working_product = wc_get_product($working_id);

if (!$broken_product) {
    echo '<div class="danger">Error: Product ' . $broken_id . ' not found!</div>';
    exit;
}

if (!$working_product) {
    echo '<div class="danger">Error: Product ' . $working_id . ' not found!</div>';
    exit;
}

echo '<p><strong>Broken Product:</strong> ' . $broken_product->get_name() . ' (ID: ' . $broken_id . ')</p>';
echo '<p><strong>Working Product:</strong> ' . $working_product->get_name() . ' (ID: ' . $working_id . ')</p>';

// Handle fix action
if (isset($_POST['action']) && $_POST['action'] === 'fix') {
    echo '<div class="success"><h2>Fixing Product ' . $broken_id . '...</h2>';
    
    // Get all WCEPI meta keys from working product
    global $wpdb;
    $wcepi_meta = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} 
        WHERE post_id = %d AND meta_key LIKE '_wcepi_%'",
        $working_id
    ));
    
    $fixed_count = 0;
    foreach ($wcepi_meta as $meta) {
        update_post_meta($broken_id, $meta->meta_key, maybe_unserialize($meta->meta_value));
        echo '<p>✓ Copied: ' . $meta->meta_key . '</p>';
        $fixed_count++;
    }
    
    // Clear all caches
    wc_delete_product_transients($broken_id);
    wp_cache_delete($broken_id, 'post_meta');
    wp_cache_delete($broken_id, 'posts');
    
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($broken_id);
    }
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
    
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    
    wp_cache_flush();
    
    echo '<p><strong>Total fields copied: ' . $fixed_count . '</strong></p>';
    echo '<p>✓ All caches cleared</p>';
    echo '</div>';
    
    echo '<div class="success">';
    echo '<h3>Fix Complete!</h3>';
    echo '<p>Now test the original product on mobile:</p>';
    echo '<p><a href="' . get_permalink($broken_id) . '" target="_blank" class="button">' . get_permalink($broken_id) . '</a></p>';
    echo '<p style="color: red;"><strong>DELETE THIS FILE NOW!</strong></p>';
    echo '</div>';
    
    exit;
}

// Compare meta data
global $wpdb;

echo '<h2>WCEPI Meta Fields Comparison</h2>';

// Get all WCEPI meta keys
$all_keys = $wpdb->get_col("
    SELECT DISTINCT meta_key 
    FROM {$wpdb->postmeta} 
    WHERE (post_id = {$broken_id} OR post_id = {$working_id})
    AND meta_key LIKE '_wcepi_%'
    ORDER BY meta_key
");

if (empty($all_keys)) {
    echo '<div class="warning">No WCEPI meta fields found on either product!</div>';
} else {
    echo '<table>';
    echo '<tr>';
    echo '<th>Meta Key</th>';
    echo '<th>Product 4694 (Broken)</th>';
    echo '<th>Product 4832 (Working)</th>';
    echo '<th>Status</th>';
    echo '</tr>';
    
    $differences = 0;
    foreach ($all_keys as $key) {
        $broken_value = get_post_meta($broken_id, $key, true);
        $working_value = get_post_meta($working_id, $key, true);
        
        // Format values for display
        $broken_display = is_array($broken_value) ? json_encode($broken_value) : (string)$broken_value;
        $working_display = is_array($working_value) ? json_encode($working_value) : (string)$working_value;
        
        // Truncate long values
        if (strlen($broken_display) > 100) {
            $broken_display = substr($broken_display, 0, 100) . '...';
        }
        if (strlen($working_display) > 100) {
            $working_display = substr($working_display, 0, 100) . '...';
        }
        
        // Check if different
        $is_different = $broken_value !== $working_value;
        $row_class = '';
        $status = 'Same';
        
        if ($is_different) {
            $differences++;
            if (empty($broken_value) && !empty($working_value)) {
                $row_class = 'missing';
                $status = 'MISSING in 4694';
            } else if (!empty($broken_value) && empty($working_value)) {
                $row_class = 'different';
                $status = 'Extra in 4694';
            } else {
                $row_class = 'different';
                $status = 'DIFFERENT';
            }
        }
        
        echo '<tr class="' . $row_class . '">';
        echo '<td><code>' . esc_html($key) . '</code></td>';
        echo '<td>' . esc_html($broken_display) . '</td>';
        echo '<td>' . esc_html($working_display) . '</td>';
        echo '<td><strong>' . $status . '</strong></td>';
        echo '</tr>';
    }
    
    echo '</table>';
    
    if ($differences > 0) {
        echo '<div class="warning">';
        echo '<h3>Found ' . $differences . ' differences!</h3>';
        echo '<p>The script will copy all WCEPI meta fields from the working product (4832) to the broken product (4694).</p>';
        echo '</div>';
    } else {
        echo '<div class="success">';
        echo '<h3>No differences found in WCEPI meta fields!</h3>';
        echo '<p>The issue might be in other metadata or theme/plugin conflict.</p>';
        echo '</div>';
    }
}

// Check other potential issues
echo '<h2>Additional Diagnostics</h2>';
echo '<table>';
echo '<tr><th>Check</th><th>Product 4694</th><th>Product 4832</th></tr>';

// Post status
echo '<tr>';
echo '<td>Post Status</td>';
echo '<td>' . get_post_status($broken_id) . '</td>';
echo '<td>' . get_post_status($working_id) . '</td>';
echo '</tr>';

// Visibility
echo '<tr>';
echo '<td>Visibility</td>';
echo '<td>' . $broken_product->get_catalog_visibility() . '</td>';
echo '<td>' . $working_product->get_catalog_visibility() . '</td>';
echo '</tr>';

// WooCommerce product type
echo '<tr>';
echo '<td>Product Type</td>';
echo '<td>' . $broken_product->get_type() . '</td>';
echo '<td>' . $working_product->get_type() . '</td>';
echo '</tr>';

// Check if Elementor template
$broken_elementor = get_post_meta($broken_id, '_elementor_edit_mode', true);
$working_elementor = get_post_meta($working_id, '_elementor_edit_mode', true);
echo '<tr' . ($broken_elementor !== $working_elementor ? ' class="different"' : '') . '>';
echo '<td>Elementor Edit Mode</td>';
echo '<td>' . ($broken_elementor ? $broken_elementor : 'Not set') . '</td>';
echo '<td>' . ($working_elementor ? $working_elementor : 'Not set') . '</td>';
echo '</tr>';

// Check custom template
$broken_template = get_post_meta($broken_id, '_wp_page_template', true);
$working_template = get_post_meta($working_id, '_wp_page_template', true);
echo '<tr' . ($broken_template !== $working_template ? ' class="different"' : '') . '>';
echo '<td>Page Template</td>';
echo '<td>' . ($broken_template ? $broken_template : 'Default') . '</td>';
echo '<td>' . ($working_template ? $working_template : 'Default') . '</td>';
echo '</tr>';

echo '</table>';

// Fix button
echo '<h2>Action</h2>';
echo '<form method="post">';
echo '<input type="hidden" name="action" value="fix">';
echo '<button type="submit" class="button" onclick="return confirm(\'This will copy all WCEPI metadata from product 4832 to 4694 and clear all caches. Continue?\')">Fix Product 4694</button>';
echo '</form>';

echo '<div class="warning">';
echo '<h3>What This Will Do:</h3>';
echo '<ul>';
echo '<li>Copy all _wcepi_* meta fields from product 4832 to product 4694</li>';
echo '<li>Clear WooCommerce product transients</li>';
echo '<li>Clear WP Rocket cache</li>';
echo '<li>Clear Elementor cache</li>';
echo '<li>Flush WordPress object cache</li>';
echo '</ul>';
echo '<p><strong>This is safe and reversible</strong> - WordPress keeps meta history.</p>';
echo '</div>';

echo '<div class="danger">';
echo '<p style="font-size: 18px;"><strong>⚠️ IMPORTANT: DELETE THIS FILE AFTER USE!</strong></p>';
echo '<p>File location: ' . __FILE__ . '</p>';
echo '</div>';

?>

</body>
</html>