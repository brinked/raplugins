<?php
/**
 * Plugin Name: Enhanced Content
 * Plugin URI: https://rankaudit.com/enhanced-content
 * Description: An autonomous SEO content agent for WordPress. Scores your articles, finds ranking opportunities, drafts evidence-based improvements with AI, and applies them only after you approve each change. Includes the full E-E-A-T contributor, sources, FAQ and schema toolkit.
 * Version: 2.12.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: RankAudit
 * Author URI: https://rankaudit.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enhanced-content-plugin
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ECP_VERSION', '2.12.0');
define('ECP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ECP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ECP_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
class Enhanced_Content_Plugin {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance of plugin
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
        $this->load_dependencies();

        // Called directly, not hooked.
        //
        // This used to be add_action('plugins_loaded', ..., 5). That never
        // fired even once: this class is itself instantiated from
        // plugins_loaded at the default priority 10, so by the time the
        // callback was registered the hook had already gone past priority 5.
        // Schema upgrades were therefore only ever applied by the activation
        // hook, and upgrading by overwriting the files over FTP — which is
        // the normal way to do it — left the tables at whatever shape they
        // had on the day the plugin was first activated. New columns were
        // missing, every query touching them failed, and the screens showed
        // empty results rather than an error.
        //
        // A version check is one get_option on a normal request, so there is
        // no reason for it to be deferred at all.
        $this->maybe_upgrade();

        $this->init_hooks();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // --- Editorial / E-E-A-T toolkit (v1.x feature set) ---
        require_once ECP_PLUGIN_DIR . 'includes/class-meta-boxes.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-schema-generator.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-frontend-display.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-user-profile.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-settings.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-article-health.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-faq.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-shortcodes.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-bulk-edit.php';
        require_once ECP_PLUGIN_DIR . 'includes/class-integrations.php';

        // --- SEO agent ---
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-db.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-log.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-limits.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-site-profile.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-agent-settings.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-capabilities.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-content-map.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-inventory.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-classifier.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-signals.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-search-data.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-opportunity-engine.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-roadmap.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-vault.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-topical-map.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-clusters.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-rankings.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-content-gaps.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-link-suggestions.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/providers/class-ecp-provider.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/providers/class-ecp-provider-anthropic.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/providers/class-ecp-provider-openai.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/providers/class-ecp-provider-rankaudit.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-ai-client.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-analyzer.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-proposals.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-guardrails.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-applier.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-diff.php';
        // Preview runs on the front end, not just in wp-admin — it renders a
        // real page with an unsaved change applied.
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-preview.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-trust-ladder.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-memory.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-measurement.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-refresh.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-scheduler.php';
        require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-digest.php';

        // --- Agent admin UI ---
        if (is_admin()) {
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-admin-menu.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-dashboard.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-review.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-opportunities.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-roadmap.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-vault.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-map.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-rankings.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-clusters.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-intelligence.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-history.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-screen-agent-settings.php';
            require_once ECP_PLUGIN_DIR . 'admin/agent/class-ecp-ajax.php';
        }

        if (defined('WP_CLI') && WP_CLI) {
            require_once ECP_PLUGIN_DIR . 'includes/class-cli.php';
            require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-agent-cli.php';
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Registered here rather than on `init`, because a capability check
        // can happen at any point in the request and the user_has_cap filter
        // has to already be in place when it does.
        ECP_Capabilities::get_instance();

        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_classes'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Enqueue public scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));

        // "Settings" link on the Plugins screen
        add_filter('plugin_action_links_' . plugin_basename(ECP_PLUGIN_FILE), array($this, 'add_action_links'));
    }

    /**
     * Add quick links to the plugin row on the Plugins screen
     */
    public function add_action_links($links) {
        $custom = array(
            sprintf(
                '<a href="%s"><strong>%s</strong></a>',
                esc_url(admin_url('admin.php?page=ecp-review')),
                esc_html__('Review Changes', 'enhanced-content-plugin')
            ),
            sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=ecp-agent-settings')),
                esc_html__('Settings', 'enhanced-content-plugin')
            ),
        );

        return array_merge($custom, $links);
    }

    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'enhanced-content-plugin',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Initialize plugin classes
     */
    public function init_classes() {
        // Editorial toolkit
        ECP_Meta_Boxes::get_instance();
        ECP_Schema_Generator::get_instance();
        ECP_Frontend_Display::get_instance();
        ECP_User_Profile::get_instance();
        ECP_Settings::get_instance();
        ECP_Article_Health::get_instance();
        ECP_FAQ::get_instance();
        ECP_Shortcodes::get_instance();
        ECP_Bulk_Edit::get_instance();
        ECP_Integrations::get_instance();

        // Agent runtime.
        ECP_Preview::get_instance();
        ECP_Scheduler::get_instance();
        ECP_Digest::get_instance();

        if (is_admin()) {
            ECP_Admin_Menu::get_instance();
            ECP_Ajax::get_instance();
        }
    }

    /**
     * Run install/upgrade routines when the stored version is behind the code.
     *
     * Runs on every request (one cheap option read) rather than only on the
     * activation hook, so the tables also appear after a manual file update
     * or a network upgrade where the activation hook never fires.
     */
    public function maybe_upgrade() {
        $installed = get_option('ecp_db_version', '0');

        if (version_compare($installed, ECP_DB::SCHEMA_VERSION, '>=')) {
            return;
        }

        ECP_DB::install();

        // Only record success if the tables really came out the right shape.
        // Stamping the version unconditionally meant one failed migration was
        // permanent: the next request saw a current version number, skipped
        // the upgrade, and the broken schema stayed broken forever with
        // nothing anywhere saying so. Leaving the old number in place costs a
        // retry per request until it works, which is the right trade.
        $status = ECP_DB::metrics_schema_status();

        if (empty($status['ok'])) {
            ECP_Log::warn(
                'db.upgrade_incomplete',
                __('The database upgrade did not complete. The metrics table is still missing its reporting-period column or indexes; it will be retried on the next request.', 'enhanced-content-plugin')
            );

            return;
        }

        // Change types added after a site saved its settings would otherwise
        // arrive permanently switched off — the saved list predates them, so
        // absence is not a choice anyone made. Added once, on the upgrade
        // that introduces them; from then on disabling is respected.
        if (version_compare($installed, '2.6.0', '<')) {
            $saved = get_option('ecp_agent_settings', array());

            if (is_array($saved) && isset($saved['enabled_change_types']) && is_array($saved['enabled_change_types'])
                && !in_array('section_trim', $saved['enabled_change_types'], true)
            ) {
                $saved['enabled_change_types'][] = 'section_trim';
                update_option('ecp_agent_settings', $saved);
            }
        }

        // 2.11.0: owner answers move from per-post meta into the Knowledge
        // Vault, where every page (and the owner) can see them.
        if (version_compare($installed, '2.11.0', '<')) {
            ECP_Vault::migrate_meta();
        }

        update_option('ecp_db_version', ECP_DB::SCHEMA_VERSION, false);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on post edit screens
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        // Meta boxes are only registered for the enabled post types
        $screen = get_current_screen();
        if ($screen && !in_array($screen->post_type, ECP_Settings::get_enabled_post_types(), true)) {
            return;
        }

        wp_enqueue_style(
            'ecp-admin-styles',
            ECP_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            ECP_VERSION
        );

        wp_enqueue_script(
            'ecp-admin-scripts',
            ECP_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery', 'jquery-ui-sortable'),
            ECP_VERSION,
            true
        );

        // Localize script for AJAX + translatable UI strings
        wp_localize_script('ecp-admin-scripts', 'mapAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('map_admin_nonce'),
            'i18n' => array(
                'searchPrompt' => __('Enter a name or email to search...', 'enhanced-content-plugin'),
                'searchMinChars' => __('Enter at least 2 characters to search...', 'enhanced-content-plugin'),
                'searching' => __('Searching...', 'enhanced-content-plugin'),
                'noUsersFound' => __('No users found.', 'enhanced-content-plugin'),
                'searchError' => __('Error searching users.', 'enhanced-content-plugin'),
                'alreadyAdded' => __(' (Already added)', 'enhanced-content-plugin'),
                'remove' => __('Remove', 'enhanced-content-plugin'),
                'noContributors' => __('No contributors added yet.', 'enhanced-content-plugin'),
                'multiRoleWarningSingle' => __('is credited in more than one role on this post. Self-review can undermine reader trust.', 'enhanced-content-plugin'),
                'multiRoleWarningPlural' => __('are credited in more than one role on this post. Self-review can undermine reader trust.', 'enhanced-content-plugin')
            )
        ));
    }

    /**
     * Enqueue public assets
     */
    public function enqueue_public_assets() {
        if (!is_singular(ECP_Settings::get_enabled_post_types())) {
            return;
        }

        wp_enqueue_style(
            'ecp-public-styles',
            ECP_PLUGIN_URL . 'public/css/public-styles.css',
            array(),
            ECP_VERSION
        );

        wp_enqueue_script(
            'ecp-public-scripts',
            ECP_PLUGIN_URL . 'public/js/hover-popup.js',
            array('jquery'),
            ECP_VERSION,
            true
        );
    }
}

/**
 * Initialize the plugin
 */
function ecp_init() {
    return Enhanced_Content_Plugin::get_instance();
}

add_action('plugins_loaded', 'ecp_init');

/**
 * Activation: create tables and schedule cron immediately so the first
 * request after activation already has a working agent.
 */
function ecp_activate() {
    require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-db.php';
    require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-agent-settings.php';
    require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-scheduler.php';

    ECP_DB::install();
    update_option('ecp_db_version', ECP_DB::SCHEMA_VERSION, false);
    ECP_Scheduler::schedule_events();

    // First-run flag drives the setup checklist on the agent dashboard.
    add_option('ecp_onboarded', 0, '', false);
}
register_activation_hook(__FILE__, 'ecp_activate');

/**
 * Deactivation: stop all background work. Tables and proposals are kept so
 * nothing is lost by toggling the plugin off; uninstall.php clears them.
 */
function ecp_deactivate() {
    require_once ECP_PLUGIN_DIR . 'includes/agent/class-ecp-scheduler.php';
    ECP_Scheduler::unschedule_events();
}
register_deactivation_hook(__FILE__, 'ecp_deactivate');
