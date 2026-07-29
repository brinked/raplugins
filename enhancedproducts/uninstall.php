<?php
/**
 * Uninstall handler for WooCommerce Enhanced Product Info.
 *
 * Removes all plugin options and transients. Product-level data
 * (_wcepi_* post meta such as specifications, FAQs, and warranty info)
 * is intentionally preserved so nothing is lost if the plugin is
 * reinstalled later.
 *
 * @package WC_Enhanced_Product_Info
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete all plugin options (they all use the wcepi_ prefix)
$option_names = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('wcepi_') . '%'
    )
);

foreach ($option_names as $option_name) {
    delete_option($option_name);
}

// Delete plugin transients
delete_transient('wcepi_product_hooks_data');

// Multisite: clean each site
if (is_multisite()) {
    $site_ids = get_sites(array('fields' => 'ids'));
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);

        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('wcepi_') . '%'
            )
        );
        foreach ($option_names as $option_name) {
            delete_option($option_name);
        }
        delete_transient('wcepi_product_hooks_data');

        restore_current_blog();
    }
}
