<?php
/**
 * Frontend Display Class
 * Handles displaying contributors and sources on the frontend
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_Frontend_Display {
    
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
        // Add contributors after title
        add_filter('the_content', array($this, 'add_contributors_to_content'), 5);
        
        // Add sources at the end of content
        add_filter('the_content', array($this, 'add_sources_to_content'), 999);
        
        // Add AJAX endpoint for contributor popups
        add_action('wp_ajax_map_get_contributor_popup', array($this, 'ajax_get_contributor_popup'));
        add_action('wp_ajax_nopriv_map_get_contributor_popup', array($this, 'ajax_get_contributor_popup'));
        
        // Output author ID for JavaScript
        add_action('wp_head', array($this, 'output_author_data'));
    }
    
    /**
     * Output author ID as JavaScript data
     */
    public function output_author_data() {
        if (!is_singular('post')) {
            return;
        }
        
        global $post;
        $author_id = $post->post_author;
        
        ?>
        <script type="text/javascript">
            var mapPostData = {
                authorId: <?php echo intval($author_id); ?>
            };
        </script>
        <?php
    }
    
    /**
     * Add contributors to content
     */
    public function add_contributors_to_content($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        
        $contributors_html = $this->render_contributors();
        
        if ($contributors_html) {
            $content = $contributors_html . $content;
        }
        
        return $content;
    }
    
    /**
     * Render contributors
     */
    private function render_contributors() {
        global $post;
        
        $contributors = get_post_meta($post->ID, '_article_contributors', true);
        
        if (!is_array($contributors)) {
            return '';
        }
        
        ob_start();
        include MAP_PLUGIN_DIR . 'templates/contributor-badges.php';
        return ob_get_clean();
    }
    
    /**
     * Add sources to content
     */
    public function add_sources_to_content($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        
        $sources_html = $this->render_sources();
        
        if ($sources_html) {
            $content .= $sources_html;
        }
        
        return $content;
    }
    
    /**
     * Render sources
     */
    private function render_sources() {
        global $post;
        
        $sources = get_post_meta($post->ID, '_article_sources', true);
        
        if (empty($sources) || !is_array($sources)) {
            return '';
        }
        
        ob_start();
        include MAP_PLUGIN_DIR . 'templates/sources-list.php';
        return ob_get_clean();
    }
    
    /**
     * Get contributor badge HTML
     */
    public static function get_contributor_badge($user_id, $role) {
        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }
        
        $role_labels = array(
            'author' => __('Written by', 'multi-author-plugin'),
            'reviewer' => __('Reviewed by', 'multi-author-plugin'),
            'fact_checker' => __('Fact-checked by', 'multi-author-plugin')
        );
        
        $role_label = isset($role_labels[$role]) ? $role_labels[$role] : '';
        $author_url = get_author_posts_url($user_id);
        
        ob_start();
        ?>
        <span class="map-contributor-badge" data-user-id="<?php echo esc_attr($user_id); ?>" data-role="<?php echo esc_attr($role); ?>">
            <span class="map-contributor-role"><?php echo esc_html($role_label); ?></span>
            <a href="<?php echo esc_url($author_url); ?>" class="map-contributor-link" data-user-id="<?php echo esc_attr($user_id); ?>">
                <?php echo esc_html($user->display_name); ?>
            </a>
        </span>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX: Get contributor popup content
     */
    public function ajax_get_contributor_popup() {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
            return;
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error('User not found');
            return;
        }
        
        $data = array(
            'name' => $user->display_name,
            'avatar' => get_avatar_url($user_id, array('size' => 80)),
            'bio' => $this->get_user_short_bio($user_id),
            'profile_url' => get_author_posts_url($user_id),
            'editorial_process_url' => get_user_meta($user_id, '_user_editorial_process_link', true)
        );
        
        ob_start();
        include MAP_PLUGIN_DIR . 'templates/contributor-popup.php';
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'data' => $data
        ));
    }
    
    /**
     * Get user short bio
     */
    private function get_user_short_bio($user_id) {
        $short_bio = get_user_meta($user_id, '_user_short_bio', true);
        
        if (!$short_bio) {
            // Fallback to regular bio, trimmed
            $bio = get_user_meta($user_id, 'description', true);
            if ($bio) {
                $short_bio = wp_trim_words($bio, 30, '...');
            }
        }
        
        return $short_bio;
    }
    
    /**
     * Get editorial process link label
     */
    public static function get_editorial_process_label() {
        return apply_filters('map_editorial_process_label', __('Editorial Process', 'multi-author-plugin'));
    }
    
    /**
     * Check if post has contributors
     */
    public static function has_contributors($post_id = null) {
        if (!$post_id) {
            global $post;
            $post_id = $post->ID;
        }
        
        $contributors = get_post_meta($post_id, '_article_contributors', true);
        
        if (!is_array($contributors)) {
            return false;
        }
        
        return !empty($contributors['authors']) || 
               !empty($contributors['reviewers']) || 
               !empty($contributors['fact_checkers']);
    }
    
    /**
     * Check if post has sources
     */
    public static function has_sources($post_id = null) {
        if (!$post_id) {
            global $post;
            $post_id = $post->ID;
        }
        
        $sources = get_post_meta($post_id, '_article_sources', true);
        
        return !empty($sources) && is_array($sources);
    }
    
    /**
     * Get contributor count by role
     */
    public static function get_contributor_count($role, $post_id = null) {
        if (!$post_id) {
            global $post;
            $post_id = $post->ID;
        }
        
        $contributors = get_post_meta($post_id, '_article_contributors', true);
        
        if (!is_array($contributors) || !isset($contributors[$role])) {
            return 0;
        }
        
        return count($contributors[$role]);
    }
    
    /**
     * Get all contributors for a post
     */
    public static function get_all_contributors($post_id = null) {
        if (!$post_id) {
            global $post;
            $post_id = $post->ID;
        }
        
        $contributors = get_post_meta($post_id, '_article_contributors', true);
        
        if (!is_array($contributors)) {
            return array(
                'authors' => array(),
                'reviewers' => array(),
                'fact_checkers' => array()
            );
        }
        
        return $contributors;
    }
    
    /**
     * Get sources for a post
     */
    public static function get_sources($post_id = null) {
        if (!$post_id) {
            global $post;
            $post_id = $post->ID;
        }
        
        $sources = get_post_meta($post_id, '_article_sources', true);
        
        return is_array($sources) ? $sources : array();
    }
}