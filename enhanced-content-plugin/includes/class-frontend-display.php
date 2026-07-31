<?php
/**
 * Frontend Display Class
 * Handles displaying contributors and sources on the frontend
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ECP_Frontend_Display {
    
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
        // Add contributors before content. Runs at 999 — after wpautop (10) and
        // do_shortcode (11) — so the badge markup is not reformatted and
        // shortcode-like text in contributor bios is never executed.
        add_filter('the_content', array($this, 'add_contributors_to_content'), 999);

        // Add corrections log before sources/FAQ
        add_filter('the_content', array($this, 'add_corrections_to_content'), 997);

        // Add sources at the end of content
        add_filter('the_content', array($this, 'add_sources_to_content'), 999);

        // Add filters to disable theme author/date output if setting is enabled
        add_action('wp', array($this, 'maybe_disable_theme_author_date'));

        // Reviewer/fact-checker credits on author archives
        add_filter('get_the_archive_description', array($this, 'add_author_archive_credits'));
    }

    /**
     * Conditionally disable theme's author and date output
     */
    public function maybe_disable_theme_author_date() {
        // Only apply on single posts
        if (!is_singular(ECP_Settings::get_enabled_post_types())) {
            return;
        }

        // Check if the setting is enabled
        $hide_theme_author = ECP_Settings::get_setting('hide_theme_author', 0);

        if ($hide_theme_author) {
            // Remove author output (get_the_author() applies 'the_author' too)
            add_filter('the_author', array($this, 'return_empty_for_theme'), 999);

            // Remove date output
            add_filter('the_date', array($this, 'return_empty_for_theme'), 999);
            add_filter('get_the_date', array($this, 'return_empty_for_date'), 999, 3);
            add_filter('the_time', array($this, 'return_empty_for_theme'), 999);
            add_filter('the_modified_date', array($this, 'return_empty_for_theme'), 999);
            add_filter('get_the_modified_date', array($this, 'return_empty_for_date'), 999, 3);

            // Astra theme specific hooks - use early priority (1) to catch before theme processes
            add_filter('astra_post_meta_enabled', '__return_false', 1);
            add_filter('astra_single_post_meta', '__return_empty_string', 1);  // Return string, not array
            add_filter('astra_the_post_meta', '__return_empty_string', 1);
            add_filter('astra_post_meta', '__return_empty_string', 1);

            // Additional Astra filters that may output meta - all return empty string
            add_filter('astra_get_post_meta', '__return_empty_string', 1);
            add_filter('astra_blog_post_meta', '__return_empty_string', 1);
            add_filter('astra_single_post_structure', array($this, 'filter_astra_post_structure'), 1);

            // Filter that might output "Array" if given an array
            add_filter('astra_single_post_meta_output', '__return_empty_string', 1);
            add_filter('astra_entry_meta', '__return_empty_string', 1);

            // Remove Astra's post meta actions
            $this->remove_astra_post_meta();

            // Builder and block bylines never pass through the classic
            // filters above. get_the_author_meta('display_name') powers
            // Elementor's Post Info and most builder author widgets; the
            // core post-author/post-date blocks power block themes and
            // Site/Theme Builder templates. Both are removed server-side
            // — the markup is never rendered, not hidden with CSS. The
            // plugin's own byline reads get_userdata() directly, so it
            // is untouched by any of this.
            add_filter('get_the_author_display_name', array($this, 'return_empty_on_singular'), 999);
            add_filter('render_block', array($this, 'blank_theme_author_blocks'), 999, 2);
        }
    }

    /**
     * Empty on the front of an enabled single post, untouched anywhere
     * else. Looser than return_empty_for_theme on purpose: builder
     * widgets render outside the classic loop.
     */
    public function return_empty_on_singular($value) {
        if (is_admin() || !is_singular(ECP_Settings::get_enabled_post_types())) {
            return $value;
        }

        return '';
    }

    /**
     * Server-side removal of the author/date blocks a block theme or
     * builder template renders around the article.
     */
    public function blank_theme_author_blocks($content, $block) {
        if (is_admin() || !is_singular(ECP_Settings::get_enabled_post_types())) {
            return $content;
        }

        $byline_blocks = array(
            'core/post-author',
            'core/post-author-name',
            'core/post-author-biography',
            'core/post-date',
            'core/avatar',
        );

        if (isset($block['blockName']) && in_array($block['blockName'], $byline_blocks, true)) {
            return '';
        }

        return $content;
    }

    /**
     * Filter Astra post structure to remove meta elements
     */
    public function filter_astra_post_structure($structure) {
        if (!is_singular(ECP_Settings::get_enabled_post_types())) {
            return $structure;
        }

        if (is_array($structure)) {
            // Remove meta-related keys
            $remove_keys = array('single-meta', 'single-meta-primary', 'single-meta-secondary');
            foreach ($remove_keys as $key) {
                $found = array_search($key, $structure);
                if ($found !== false) {
                    unset($structure[$found]);
                }
            }
        }

        return $structure;
    }

    /**
     * Return empty string for theme author/date filters
     */
    public function return_empty_for_theme($value) {
        // Only return empty on single post frontend, not in admin
        if (is_admin() || !is_singular(ECP_Settings::get_enabled_post_types()) || !in_the_loop()) {
            return $value;
        }
        return '';
    }

    /**
     * Return empty string for date filters (handles different signatures)
     */
    public function return_empty_for_date($the_date, $format = '', $post = null) {
        // Only return empty on single post frontend, not in admin
        if (is_admin() || !is_singular(ECP_Settings::get_enabled_post_types()) || !in_the_loop()) {
            return $the_date;
        }
        return '';
    }

    /**
     * Remove Astra theme's post meta output
     */
    public function remove_astra_post_meta() {
        // Remove Astra's entry meta
        remove_action('astra_entry_content_before', 'astra_entry_header', 10);

        // Try to remove Astra Pro's post meta if it exists
        if (class_exists('Astra_Ext_Blog_Pro_Markup')) {
            remove_action('astra_entry_content_before', array(Astra_Ext_Blog_Pro_Markup::get_instance(), 'single_post_author'), 10);
        }
    }
    
    /**
     * Locate a plugin template, allowing theme overrides.
     *
     * Themes can override any template by copying it to:
     *   your-theme/enhanced-content-plugin/{template-name}.php
     * The legacy multi-author-plugin/ directory is still honoured so existing
     * theme overrides keep working after the rename.
     * Developers can also reroute paths via the 'map_template_path' filter.
     */
    public static function locate_template($template) {
        $theme_template = locate_template(array(
            'enhanced-content-plugin/' . $template,
            'multi-author-plugin/' . $template,
        ));
        $path = $theme_template ? $theme_template : ECP_PLUGIN_DIR . 'templates/' . $template;

        return apply_filters('map_template_path', $path, $template);
    }

    /**
     * Add contributors to content
     */
    public function add_contributors_to_content($content) {
        if (!is_singular(ECP_Settings::get_enabled_post_types()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        if ('manual' === ECP_Settings::get_setting('contributors_placement', 'auto')) {
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
    public function render_contributors() {
        global $post;

        $contributors = get_post_meta($post->ID, '_article_contributors', true);

        if (!is_array($contributors)) {
            // The template still renders the primary author and trust badges
            // when no contributor meta has been saved yet.
            $contributors = array();
        }

        $template = self::locate_template('contributor-badges.php');
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }
    
    /**
     * Add sources to content
     */
    public function add_sources_to_content($content) {
        if (!is_singular(ECP_Settings::get_enabled_post_types()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        if ('manual' === ECP_Settings::get_setting('sources_placement', 'auto')) {
            return $content;
        }

        $sources_html = $this->render_sources();

        if ($sources_html) {
            $content .= $sources_html;
        }

        return $content;
    }

    /**
     * Add corrections log to content
     */
    public function add_corrections_to_content($content) {
        if (!is_singular(ECP_Settings::get_enabled_post_types()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $corrections_html = $this->render_corrections();

        if ($corrections_html) {
            $content .= $corrections_html;
        }

        return $content;
    }

    /**
     * Render corrections log
     */
    public function render_corrections() {
        global $post;

        if (!$post) {
            return '';
        }

        $corrections = get_post_meta($post->ID, '_map_corrections', true);

        if (empty($corrections) || !is_array($corrections)) {
            return '';
        }

        $template = self::locate_template('corrections-log.php');
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }
    
    /**
     * Render sources
     */
    public function render_sources() {
        global $post;

        $sources = get_post_meta($post->ID, '_article_sources', true);

        if (empty($sources) || !is_array($sources)) {
            return '';
        }

        $template = self::locate_template('sources-list.php');
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }

    /**
     * Add AI disclaimer badge to content
     */
    public function add_ai_disclaimer_to_content($content) {
        if (!is_singular(ECP_Settings::get_enabled_post_types()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $ai_html = $this->render_ai_disclaimer();

        if ($ai_html) {
            $content = $ai_html . $content;
        }

        return $content;
    }

    /**
     * Render AI disclaimer badge
     */
    private function render_ai_disclaimer() {
        global $post;

        $ai_settings = get_post_meta($post->ID, '_map_ai_disclaimer', true);

        if (!is_array($ai_settings) || empty($ai_settings['badge_type']) || $ai_settings['badge_type'] === 'none') {
            return '';
        }

        $badge_type = $ai_settings['badge_type'];
        $ai_uses = isset($ai_settings['ai_uses']) ? $ai_settings['ai_uses'] : array();
        $custom_uses = isset($ai_settings['custom_uses']) ? $ai_settings['custom_uses'] : array();

        // Get AI disclaimer page URL from settings
        $ai_disclaimer_url = ECP_Settings::get_setting('ai_disclaimer_page', '');

        $template = self::locate_template('ai-disclaimer-badge.php');
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }

    /**
     * Append reviewer/fact-checker credits to author archive descriptions
     */
    public function add_author_archive_credits($description) {
        if (!is_author() || !ECP_Settings::get_setting('author_archive_credits', 0)) {
            return $description;
        }

        $user_id = get_queried_object_id();
        if (!$user_id) {
            return $description;
        }

        $counts = self::get_credit_counts($user_id);

        if (!$counts['reviewed'] && !$counts['fact_checked']) {
            return $description;
        }

        $parts = array();
        if ($counts['reviewed']) {
            /* translators: %s: number of articles */
            $parts[] = sprintf(_n('Reviewed %s article', 'Reviewed %s articles', $counts['reviewed'], 'enhanced-content-plugin'), number_format_i18n($counts['reviewed']));
        }
        if ($counts['fact_checked']) {
            /* translators: %s: number of articles */
            $parts[] = sprintf(_n('Fact-checked %s article', 'Fact-checked %s articles', $counts['fact_checked'], 'enhanced-content-plugin'), number_format_i18n($counts['fact_checked']));
        }

        $credits = '<p class="map-archive-credits">' . esc_html(implode(' · ', $parts)) . '</p>';

        return $description . $credits;
    }

    /**
     * Count published posts a user has reviewed / fact-checked.
     * Cached in a transient because it requires a meta scan.
     */
    public static function get_credit_counts($user_id) {
        $cache_key = 'map_credits_' . intval($user_id);
        $counts = get_transient($cache_key);
        if (is_array($counts)) {
            return $counts;
        }

        global $wpdb;

        $counts = array('reviewed' => 0, 'fact_checked' => 0);

        // Pre-filter with LIKE on the serialized ID, then verify in PHP
        $like = '%' . $wpdb->esc_like('i:' . intval($user_id) . ';') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
             WHERE pm.meta_key = '_article_contributors' AND pm.meta_value LIKE %s
             LIMIT 2000",
            $like
        ));

        foreach ($rows as $row) {
            $contributors = maybe_unserialize($row->meta_value);
            if (!is_array($contributors)) {
                continue;
            }
            if (!empty($contributors['reviewers']) && in_array($user_id, array_map('intval', (array) $contributors['reviewers']), true)) {
                $counts['reviewed']++;
            }
            if (!empty($contributors['fact_checkers']) && in_array($user_id, array_map('intval', (array) $contributors['fact_checkers']), true)) {
                $counts['fact_checked']++;
            }
        }

        set_transient($cache_key, $counts, HOUR_IN_SECONDS);

        return $counts;
    }

    /**
     * Get AI use labels
     */
    public static function get_ai_use_labels() {
        return array(
            'research' => __('Research & Information Gathering', 'enhanced-content-plugin'),
            'writing_assistance' => __('Writing Assistance', 'enhanced-content-plugin'),
            'editing' => __('Editing & Proofreading', 'enhanced-content-plugin'),
            'spellcheck' => __('Spell Checking', 'enhanced-content-plugin'),
            'grammar' => __('Grammar Checking', 'enhanced-content-plugin'),
            'formatting' => __('Formatting & Structure', 'enhanced-content-plugin'),
            'summarization' => __('Summarization', 'enhanced-content-plugin'),
            'translation' => __('Translation', 'enhanced-content-plugin'),
            'image_creation' => __('Image Creation/Generation', 'enhanced-content-plugin'),
            'image_editing' => __('Image Editing/Enhancement', 'enhanced-content-plugin'),
            'video_creation' => __('Video Creation/Generation', 'enhanced-content-plugin'),
            'video_editing' => __('Video Editing', 'enhanced-content-plugin'),
            'audio_creation' => __('Audio/Voice Generation', 'enhanced-content-plugin'),
            'transcription' => __('Transcription', 'enhanced-content-plugin'),
            'data_analysis' => __('Data Analysis', 'enhanced-content-plugin'),
            'code_generation' => __('Code Generation', 'enhanced-content-plugin'),
            'seo_optimization' => __('SEO Optimization', 'enhanced-content-plugin'),
            'headline_generation' => __('Headline/Title Generation', 'enhanced-content-plugin'),
            'outline_creation' => __('Outline Creation', 'enhanced-content-plugin'),
            'fact_checking' => __('Fact Checking Assistance', 'enhanced-content-plugin')
        );
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
            'author' => __('Written by', 'enhanced-content-plugin'),
            'reviewer' => __('Reviewed by', 'enhanced-content-plugin'),
            'fact_checker' => __('Fact-checked by', 'enhanced-content-plugin')
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
     * Get editorial process link label
     */
    public static function get_editorial_process_label() {
        return apply_filters('map_editorial_process_label', __('Editorial Process', 'enhanced-content-plugin'));
    }
    
    /**
     * Check if post has contributors
     */
    public static function has_contributors($post_id = null) {
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return false;
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
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return false;
        }

        $sources = get_post_meta($post_id, '_article_sources', true);
        
        return !empty($sources) && is_array($sources);
    }
    
    /**
     * Get contributor count by role
     */
    public static function get_contributor_count($role, $post_id = null) {
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return 0;
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
            $post_id = get_the_ID();
        }

        $contributors = $post_id ? get_post_meta($post_id, '_article_contributors', true) : null;
        
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
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return array();
        }

        $sources = get_post_meta($post_id, '_article_sources', true);
        
        return is_array($sources) ? $sources : array();
    }
}