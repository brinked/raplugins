<?php
/**
 * Uninstall handler for Enhanced Content
 *
 * Removes all plugin data when the plugin is deleted through the
 * WordPress admin. Deactivating the plugin leaves data intact.
 *
 * Note what this destroys: the agent's proposal history, and with it the
 * rollback records for every change it ever applied. The changes themselves
 * stay on your posts — WordPress revisions were created before each one, so
 * they remain recoverable from the post editor — but the one-click undo goes
 * away with the tables.
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// --- Agent tables ---------------------------------------------------------
foreach (array('ecp_runs', 'ecp_opportunities', 'ecp_proposals', 'ecp_events', 'ecp_metrics', 'ecp_clusters') as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB.PreparedSQL
}

// --- Options and transients -----------------------------------------------
$options = array(
    // Agent
    'ecp_agent_settings',
    'ecp_db_version',
    'ecp_onboarded',
    'ecp_scan_offset',
    'ecp_trust_record',
    'ecp_site_id',
    'ecp_csv_import_meta',
    'ecp_metrics_synced_at',
    'ecp_sitekit_user',
    'ecp_dimension_summary',
    'ecp_site_profile',
    // Editorial toolkit (option keys kept from v1 for upgrade compatibility)
    'map_settings',
    'map_recent_contributors',
    'map_seo_notice_dismissed',
);

foreach ($options as $option) {
    delete_option($option);
}

delete_transient('map_health_stats');

// Cached inbound-link counts and per-day analysis counters.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_ecp\_%'
        OR option_name LIKE '\_transient\_timeout\_ecp\_%'"
);

// Scheduled events.
foreach (array('ecp_scan_cron', 'ecp_analyze_cron', 'ecp_maintenance_cron', 'ecp_digest_cron') as $hook) {
    $timestamp = wp_next_scheduled($hook);
    while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
    }
}

// Per-user credit-count transients
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
    '_ecp_owner_facts',
    '_ecp_last_refreshed',
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
