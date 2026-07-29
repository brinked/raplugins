<?php
/**
 * Plugin Name: Multi-Author Contributor Plugin
 * Plugin URI: https://example.com/multi-author-plugin
 * Description: Allows multiple contributors (authors, reviewers, fact checkers) for articles with schema markup and sources management
 * Version: 1.3.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: multi-author-plugin
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MAP_VERSION', '1.3.0');
define('MAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MAP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MAP_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
class Multi_Author_Plugin {
    
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
        $this->init_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once MAP_PLUGIN_DIR . 'includes/class-meta-boxes.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-schema-generator.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-frontend-display.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-user-profile.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-settings.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-article-health.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-faq.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-shortcodes.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-bulk-edit.php';
        require_once MAP_PLUGIN_DIR . 'includes/class-integrations.php';

        if (defined('WP_CLI') && WP_CLI) {
            require_once MAP_PLUGIN_DIR . 'includes/class-cli.php';
        }
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_classes'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Enqueue public scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));

        // "Settings" link on the Plugins screen
        add_filter('plugin_action_links_' . plugin_basename(MAP_PLUGIN_FILE), array($this, 'add_action_links'));
    }

    /**
     * Add quick links to the plugin row on the Plugins screen
     */
    public function add_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=multi-author-settings')),
            esc_html__('Settings', 'multi-author-plugin')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'multi-author-plugin',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Initialize plugin classes
     */
    public function init_classes() {
        MAP_Meta_Boxes::get_instance();
        MAP_Schema_Generator::get_instance();
        MAP_Frontend_Display::get_instance();
        MAP_User_Profile::get_instance();
        MAP_Settings::get_instance();
        MAP_Article_Health::get_instance();
        MAP_FAQ::get_instance();
        MAP_Shortcodes::get_instance();
        MAP_Bulk_Edit::get_instance();
        MAP_Integrations::get_instance();
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
        if ($screen && !in_array($screen->post_type, MAP_Settings::get_enabled_post_types(), true)) {
            return;
        }
        
        wp_enqueue_style(
            'map-admin-styles',
            MAP_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            MAP_VERSION
        );
        
        wp_enqueue_script(
            'map-admin-scripts',
            MAP_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery', 'jquery-ui-sortable'),
            MAP_VERSION,
            true
        );
        
        // Localize script for AJAX + translatable UI strings
        wp_localize_script('map-admin-scripts', 'mapAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('map_admin_nonce'),
            'i18n' => array(
                'searchPrompt' => __('Enter a name or email to search...', 'multi-author-plugin'),
                'searchMinChars' => __('Enter at least 2 characters to search...', 'multi-author-plugin'),
                'searching' => __('Searching...', 'multi-author-plugin'),
                'noUsersFound' => __('No users found.', 'multi-author-plugin'),
                'searchError' => __('Error searching users.', 'multi-author-plugin'),
                'alreadyAdded' => __(' (Already added)', 'multi-author-plugin'),
                'remove' => __('Remove', 'multi-author-plugin'),
                'noContributors' => __('No contributors added yet.', 'multi-author-plugin'),
                'multiRoleWarningSingle' => __('is credited in more than one role on this post. Self-review can undermine reader trust.', 'multi-author-plugin'),
                'multiRoleWarningPlural' => __('are credited in more than one role on this post. Self-review can undermine reader trust.', 'multi-author-plugin')
            )
        ));
    }
    
    /**
     * Enqueue public assets
     */
    public function enqueue_public_assets() {
        if (!is_singular(MAP_Settings::get_enabled_post_types())) {
            return;
        }
        
        wp_enqueue_style(
            'map-public-styles',
            MAP_PLUGIN_URL . 'public/css/public-styles.css',
            array(),
            MAP_VERSION
        );
        
        wp_enqueue_script(
            'map-public-scripts',
            MAP_PLUGIN_URL . 'public/js/hover-popup.js',
            array('jquery'),
            MAP_VERSION,
            true
        );
    }
}

/**
 * Initialize the plugin
 */
function map_init() {
    return Multi_Author_Plugin::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'map_init');

