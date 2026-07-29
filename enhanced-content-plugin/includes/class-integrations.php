<?php
/**
 * Integrations Class
 * Housekeeping and interoperability: user-deletion cleanup, SEO plugin
 * detection, WordPress privacy tools, and Site Health checks
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ECP_Integrations {

    /**
     * Instance of this class
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
        // Remove deleted users from contributor lists
        add_action('deleted_user', array($this, 'cleanup_deleted_user'));

        // SEO plugin schema-conflict notice
        add_action('admin_notices', array($this, 'maybe_show_seo_notice'));
        add_action('admin_init', array($this, 'handle_seo_notice_dismissal'));

        // WordPress privacy tools
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_privacy_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_privacy_eraser'));

        // Site Health checks
        add_filter('site_status_tests', array($this, 'register_site_health_tests'));
    }

    /**
     * Whether a known SEO plugin that outputs Article schema is active
     */
    public static function seo_plugin_active() {
        if (defined('WPSEO_VERSION')) {
            return 'Yoast SEO';
        }
        if (class_exists('RankMath')) {
            return 'Rank Math';
        }
        if (defined('AIOSEO_VERSION')) {
            return 'All in One SEO';
        }
        return false;
    }

    /**
     * When a user is deleted, remove their ID from every post's
     * contributor lists so the stored data stays truthful
     */
    public function cleanup_deleted_user($user_id) {
        global $wpdb;

        $user_id = intval($user_id);
        if (!$user_id) {
            return;
        }

        // Pre-filter with LIKE on the serialized integer, verify in PHP
        $like = '%' . $wpdb->esc_like('i:' . $user_id . ';') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_article_contributors' AND meta_value LIKE %s",
            $like
        ));

        foreach ($rows as $row) {
            $contributors = maybe_unserialize($row->meta_value);
            if (!is_array($contributors)) {
                continue;
            }

            $changed = false;
            foreach (array('authors', 'reviewers', 'fact_checkers') as $role) {
                if (empty($contributors[$role]) || !is_array($contributors[$role])) {
                    continue;
                }
                $filtered = array_values(array_filter(array_map('intval', $contributors[$role]), function($id) use ($user_id) {
                    return $id !== $user_id;
                }));
                if (count($filtered) !== count($contributors[$role])) {
                    $contributors[$role] = $filtered;
                    $changed = true;
                }
            }

            if ($changed) {
                update_post_meta($row->post_id, '_article_contributors', $contributors);
            }
        }

        // Drop from the recent-contributors picker list
        $recent = get_option('map_recent_contributors', array());
        if (is_array($recent) && in_array($user_id, array_map('intval', $recent), true)) {
            update_option('map_recent_contributors', array_values(array_diff(array_map('intval', $recent), array($user_id))), false);
        }

        delete_transient('map_credits_' . $user_id);
    }

    /**
     * One-time dismissible notice when an SEO plugin is active and our
     * Article schema is still enabled
     */
    public function maybe_show_seo_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $seo_plugin = self::seo_plugin_active();
        if (!$seo_plugin) {
            return;
        }

        if (ECP_Settings::get_setting('disable_article_schema', 0)) {
            return;
        }

        if (get_option('map_seo_notice_dismissed')) {
            return;
        }

        // Only on screens where it's relevant
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, array('dashboard', 'plugins', 'settings_page_ecp-settings'), true)) {
            return;
        }

        $dismiss_url = wp_nonce_url(add_query_arg('map_dismiss_seo_notice', '1'), 'map_dismiss_seo_notice');
        $settings_url = admin_url('options-general.php?page=ecp-settings');
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Multi-Author Contributor Plugin:', 'enhanced-content-plugin'); ?></strong>
                <?php
                printf(
                    /* translators: %s: SEO plugin name */
                    esc_html__('%s is active and may already output Article schema. Emitting two Article schemas on the same page can cause Search Console conflicts. You can disable this plugin\'s Article schema under Display Options.', 'enhanced-content-plugin'),
                    esc_html($seo_plugin)
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary"><?php esc_html_e('Review Settings', 'enhanced-content-plugin'); ?></a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button"><?php esc_html_e('Dismiss — keep both', 'enhanced-content-plugin'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Persist the SEO notice dismissal
     */
    public function handle_seo_notice_dismissal() {
        if (!isset($_GET['map_dismiss_seo_notice'])) {
            return;
        }
        if (!current_user_can('manage_options') || !check_admin_referer('map_dismiss_seo_notice')) {
            return;
        }
        update_option('map_seo_notice_dismissed', 1, false);
        wp_safe_redirect(remove_query_arg(array('map_dismiss_seo_notice', '_wpnonce')));
        exit;
    }

    /**
     * Register personal data exporter for contributor profile fields
     */
    public function register_privacy_exporter($exporters) {
        $exporters['enhanced-content-plugin'] = array(
            'exporter_friendly_name' => __('Multi-Author Contributor Plugin', 'enhanced-content-plugin'),
            'callback' => array($this, 'export_personal_data'),
        );
        return $exporters;
    }

    /**
     * Export contributor profile fields for a user
     */
    public function export_personal_data($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);

        $export_items = array();

        if ($user) {
            $fields = array(
                '_user_short_bio' => __('Short Bio', 'enhanced-content-plugin'),
                '_user_editorial_process_link' => __('Editorial Process Link', 'enhanced-content-plugin'),
                '_contact_email' => __('Public Contact Email', 'enhanced-content-plugin'),
                '_website_url' => __('Personal Website', 'enhanced-content-plugin'),
                'job_title' => __('Job Title', 'enhanced-content-plugin'),
                'twitter' => __('Twitter/X Profile', 'enhanced-content-plugin'),
                'linkedin' => __('LinkedIn Profile', 'enhanced-content-plugin'),
                'facebook' => __('Facebook Profile', 'enhanced-content-plugin'),
                'instagram' => __('Instagram Profile', 'enhanced-content-plugin'),
                'youtube' => __('YouTube Channel', 'enhanced-content-plugin'),
            );

            $data = array();
            foreach ($fields as $meta_key => $label) {
                $value = get_user_meta($user->ID, $meta_key, true);
                if ($value !== '' && $value !== false) {
                    $data[] = array('name' => $label, 'value' => $value);
                }
            }

            if (!empty($data)) {
                $export_items[] = array(
                    'group_id' => 'map-contributor-profile',
                    'group_label' => __('Contributor Profile', 'enhanced-content-plugin'),
                    'item_id' => 'map-contributor-' . $user->ID,
                    'data' => $data,
                );
            }
        }

        return array(
            'data' => $export_items,
            'done' => true,
        );
    }

    /**
     * Register personal data eraser for plugin-owned contributor fields
     */
    public function register_privacy_eraser($erasers) {
        $erasers['enhanced-content-plugin'] = array(
            'eraser_friendly_name' => __('Multi-Author Contributor Plugin', 'enhanced-content-plugin'),
            'callback' => array($this, 'erase_personal_data'),
        );
        return $erasers;
    }

    /**
     * Erase plugin-owned contributor fields for a user. Shared keys
     * (job_title, social profiles) are left for their owning plugins/themes.
     */
    public function erase_personal_data($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);
        $items_removed = false;

        if ($user) {
            foreach (array('_user_short_bio', '_user_editorial_process_link', '_contact_email', '_website_url', '_map_show_on_team') as $meta_key) {
                if (get_user_meta($user->ID, $meta_key, true) !== '') {
                    delete_user_meta($user->ID, $meta_key);
                    $items_removed = true;
                }
            }
        }

        return array(
            'items_removed' => $items_removed,
            'items_retained' => false,
            'messages' => array(),
            'done' => true,
        );
    }

    /**
     * Register Site Health checks
     */
    public function register_site_health_tests($tests) {
        $tests['direct']['map_orphaned_contributors'] = array(
            'label' => __('Multi-Author: contributor data is valid', 'enhanced-content-plugin'),
            'test' => array($this, 'site_health_orphaned_contributors'),
        );
        $tests['direct']['map_schema_conflict'] = array(
            'label' => __('Multi-Author: no duplicate Article schema', 'enhanced-content-plugin'),
            'test' => array($this, 'site_health_schema_conflict'),
        );
        return $tests;
    }

    /**
     * Site Health: look for contributor IDs pointing at deleted users
     * (bounded scan of the most recent posts)
     */
    public function site_health_orphaned_contributors() {
        global $wpdb;

        $result = array(
            'label' => __('Contributor data references valid users', 'enhanced-content-plugin'),
            'status' => 'good',
            'badge' => array('label' => __('Content', 'enhanced-content-plugin'), 'color' => 'blue'),
            'description' => '<p>' . esc_html__('All contributor assignments checked point at existing user accounts.', 'enhanced-content-plugin') . '</p>',
            'test' => 'map_orphaned_contributors',
        );

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = '_article_contributors'
             ORDER BY pm.post_id DESC
             LIMIT 200"
        );

        $orphaned_posts = array();
        foreach ($rows as $row) {
            $contributors = maybe_unserialize($row->meta_value);
            if (!is_array($contributors)) {
                continue;
            }
            foreach (array('authors', 'reviewers', 'fact_checkers') as $role) {
                if (empty($contributors[$role]) || !is_array($contributors[$role])) {
                    continue;
                }
                foreach ($contributors[$role] as $user_id) {
                    if ($user_id && !get_userdata($user_id)) {
                        $orphaned_posts[$row->post_id] = true;
                        continue 3;
                    }
                }
            }
        }

        if (!empty($orphaned_posts)) {
            $result['status'] = 'recommended';
            $result['label'] = __('Some posts credit deleted user accounts', 'enhanced-content-plugin');
            $result['description'] = '<p>' . sprintf(
                /* translators: %d: number of posts */
                esc_html(_n('%d post credits a contributor whose account was deleted. Deleted contributors are hidden on the front end, but you may want to reassign these credits. Post edits will clean the stored data.', '%d posts credit contributors whose accounts were deleted. Deleted contributors are hidden on the front end, but you may want to reassign these credits. Post edits will clean the stored data.', count($orphaned_posts), 'enhanced-content-plugin')),
                count($orphaned_posts)
            ) . '</p>';
        }

        return $result;
    }

    /**
     * Site Health: warn when two Article schemas are emitted
     */
    public function site_health_schema_conflict() {
        $result = array(
            'label' => __('Only one plugin outputs Article schema', 'enhanced-content-plugin'),
            'status' => 'good',
            'badge' => array('label' => __('SEO', 'enhanced-content-plugin'), 'color' => 'blue'),
            'description' => '<p>' . esc_html__('No duplicate Article structured data detected.', 'enhanced-content-plugin') . '</p>',
            'test' => 'map_schema_conflict',
        );

        $seo_plugin = self::seo_plugin_active();

        if ($seo_plugin && !ECP_Settings::get_setting('disable_article_schema', 0)) {
            $result['status'] = 'recommended';
            $result['label'] = __('Two plugins may output Article schema', 'enhanced-content-plugin');
            $result['description'] = '<p>' . sprintf(
                /* translators: %s: SEO plugin name */
                esc_html__('%s is active and may output Article schema alongside this plugin\'s. Consider disabling one of them (Settings > Multi-Author > Display Options > Disable Article Schema).', 'enhanced-content-plugin'),
                esc_html($seo_plugin)
            ) . '</p>';
            $result['actions'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('options-general.php?page=ecp-settings')),
                esc_html__('Open Multi-Author settings', 'enhanced-content-plugin')
            );
        }

        return $result;
    }
}
