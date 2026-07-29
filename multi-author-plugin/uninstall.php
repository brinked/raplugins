<?php
/**
 * Uninstall handler for Multi-Author Contributor Plugin
 *
 * Removes all plugin data when the plugin is deleted through the
 * WordPress admin. Deactivating the plugin leaves data intact.
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Plugin options and transients
delete_option('map_settings');
delete_option('map_recent_contributors');
delete_option('map_seo_notice_dismissed');
delete_transient('map_health_stats');

// Per-user credit-count transients
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_map\_credits\_%'
        OR option_name LIKE '\_transient\_timeout\_map\_credits\_%'"
);

// Post meta written by the plugin
$post_meta_keys = array(
    '_article_contributors',
    '_article_sources',
    '_map_expert_verified',
    '_map_process_links',
    '_map_ai_disclaimer',
    '_map_faq',
    '_map_faq_enabled',
    '_map_faq_title',
    '_map_word_count',
    '_map_citation_count',
    '_map_fact_checked_date',
    '_map_corrections',
);

foreach ($post_meta_keys as $meta_key) {
    delete_post_meta_by_key($meta_key);
}

// User meta written by the plugin. Generic keys the plugin also reads
// (job_title, twitter, linkedin, facebook, instagram, youtube) are shared
// with themes and other plugins, so they are intentionally left in place.
$user_meta_keys = array(
    '_user_short_bio',
    '_user_editorial_process_link',
    '_contact_email',
    '_website_url',
    '_map_show_on_team',
);

$user_ids = get_users(array('fields' => 'ID'));
foreach ($user_ids as $user_id) {
    foreach ($user_meta_keys as $meta_key) {
        delete_user_meta($user_id, $meta_key);
    }
}
