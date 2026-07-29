<?php
/**
 * Shortcodes & Blocks Class
 * Manual placement of plugin sections via shortcodes and Gutenberg blocks,
 * plus the Editorial Team section
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ECP_Shortcodes {

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
        add_shortcode('map_contributors', array($this, 'shortcode_contributors'));
        add_shortcode('map_sources', array($this, 'shortcode_sources'));
        add_shortcode('map_faq', array($this, 'shortcode_faq'));
        add_shortcode('map_corrections', array($this, 'shortcode_corrections'));
        add_shortcode('map_editorial_team', array($this, 'shortcode_editorial_team'));

        add_action('init', array($this, 'register_blocks'), 20);
    }

    /**
     * Register Gutenberg blocks (server-side rendered)
     */
    public function register_blocks() {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'map-blocks',
            ECP_PLUGIN_URL . 'admin/js/blocks.js',
            array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'),
            ECP_VERSION,
            true
        );

        $blocks = array(
            'contributors' => array($this, 'shortcode_contributors'),
            'sources' => array($this, 'shortcode_sources'),
            'faq' => array($this, 'shortcode_faq'),
            'corrections' => array($this, 'shortcode_corrections'),
        );

        foreach ($blocks as $name => $callback) {
            register_block_type('multi-author-plugin/' . $name, array(
                'editor_script' => 'map-blocks',
                'render_callback' => $callback,
            ));
        }

        register_block_type('multi-author-plugin/editorial-team', array(
            'editor_script' => 'map-blocks',
            'render_callback' => array($this, 'shortcode_editorial_team'),
            'attributes' => array(
                'include' => array('type' => 'string', 'default' => ''),
                'columns' => array('type' => 'number', 'default' => 3),
            ),
        ));
    }

    /**
     * Editor placeholder for empty sections (REST/block-renderer only)
     */
    private function editor_placeholder($message) {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return '<p style="padding:12px;background:#f0f0f1;border:1px dashed #c3c4c7;color:#646970;">' . esc_html($message) . '</p>';
        }
        return '';
    }

    /**
     * [map_contributors] — contributor badges for the current post
     */
    public function shortcode_contributors() {
        $html = ECP_Frontend_Display::get_instance()->render_contributors();
        return $html ? $html : $this->editor_placeholder(__('Contributor badges will appear here once contributors are assigned.', 'enhanced-content-plugin'));
    }

    /**
     * [map_sources] — sources list for the current post
     */
    public function shortcode_sources() {
        $html = ECP_Frontend_Display::get_instance()->render_sources();
        return $html ? $html : $this->editor_placeholder(__('The sources list will appear here once sources are added.', 'enhanced-content-plugin'));
    }

    /**
     * [map_faq] — FAQ accordion for the current post
     */
    public function shortcode_faq() {
        $post_id = get_the_ID();
        if (!$post_id || get_post_meta($post_id, '_map_faq_enabled', true) !== '1') {
            return $this->editor_placeholder(__('The FAQ section will appear here once enabled for this post.', 'enhanced-content-plugin'));
        }

        $html = ECP_FAQ::get_instance()->render_faq_section($post_id);
        return $html ? $html : $this->editor_placeholder(__('The FAQ section will appear here once questions are added.', 'enhanced-content-plugin'));
    }

    /**
     * [map_corrections] — corrections log for the current post
     */
    public function shortcode_corrections() {
        $html = ECP_Frontend_Display::get_instance()->render_corrections();
        return $html ? $html : $this->editor_placeholder(__('The corrections log will appear here once corrections are added.', 'enhanced-content-plugin'));
    }

    /**
     * [map_editorial_team] — cards for the site's editorial team.
     *
     * Members are NEVER auto-selected: a user appears only if an administrator
     * ticked "Show on Editorial Team page" on their profile, or if their ID is
     * explicitly listed in the include attribute.
     *
     * Attributes:
     *   include - comma-separated user IDs; overrides the opt-in list and
     *             controls display order
     *   exclude - comma-separated user IDs to hide
     *   columns - 1 to 4 (default 3)
     */
    public function shortcode_editorial_team($atts = array()) {
        $atts = shortcode_atts(array(
            'include' => '',
            'exclude' => '',
            'columns' => 3,
        ), (array) $atts, 'map_editorial_team');

        $exclude = wp_parse_id_list($atts['exclude']);
        $user_ids = array();

        if (!empty($atts['include'])) {
            // Explicit list: exact members in exact order
            $user_ids = wp_parse_id_list($atts['include']);
        } else {
            // Opt-in flag, set by administrators on user profiles
            $user_ids = get_users(array(
                'meta_key' => '_map_show_on_team',
                'meta_value' => '1',
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => 'ID',
            ));
        }

        $team_members = array();
        foreach ($user_ids as $user_id) {
            if (in_array((int) $user_id, $exclude, true)) {
                continue;
            }
            $data = ECP_User_Profile::get_contributor_data($user_id);
            if ($data) {
                $data['user_id'] = (int) $user_id;
                $team_members[] = $data;
            }
        }

        if (empty($team_members)) {
            return $this->editor_placeholder(__('No team members yet. Enable "Show on Editorial Team page" on user profiles, or pass IDs via the include attribute.', 'enhanced-content-plugin'));
        }

        $columns = min(4, max(1, intval($atts['columns'])));

        $template = ECP_Frontend_Display::locate_template('editorial-team.php');
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }
}
