<?php
/**
 * Settings Class
 * Handles admin settings page for the Multi-Author Plugin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_Settings {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Settings option name
     */
    private $option_name = 'map_settings';
    
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
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_settings_assets'));
        add_action('wp_head', array($this, 'output_custom_styles'));
    }
    
    /**
     * Add settings page to WordPress admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __('Multi-Author Settings', 'multi-author-plugin'),
            __('Multi-Author', 'multi-author-plugin'),
            'manage_options',
            'multi-author-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'map_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        // Post Types Section
        add_settings_section(
            'map_post_types',
            __('Post Types', 'multi-author-plugin'),
            array($this, 'render_post_types_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'enabled_post_types',
            __('Enable for Post Types', 'multi-author-plugin'),
            array($this, 'render_post_types_field'),
            'multi-author-settings',
            'map_post_types',
            array('field' => 'enabled_post_types')
        );

        // Role Labels Section
        add_settings_section(
            'map_role_labels',
            __('Role Labels', 'multi-author-plugin'),
            array($this, 'render_role_labels_section'),
            'multi-author-settings'
        );
        
        add_settings_field(
            'label_written_by',
            __('Written By Label', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_role_labels',
            array('field' => 'label_written_by', 'default' => 'Written by')
        );
        
        add_settings_field(
            'label_coauthor',
            __('Co-Author Label', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_role_labels',
            array('field' => 'label_coauthor', 'default' => 'Co-Author')
        );
        
        add_settings_field(
            'label_reviewed_by',
            __('Reviewed By Label', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_role_labels',
            array('field' => 'label_reviewed_by', 'default' => 'Reviewed by')
        );
        
        add_settings_field(
            'label_fact_checked_by',
            __('Fact-Checked By Label', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_role_labels',
            array('field' => 'label_fact_checked_by', 'default' => 'Fact-Checked by')
        );

        // Expert Verified Badge Section
        add_settings_section(
            'map_expert_verified',
            __('Expert Verified Badge', 'multi-author-plugin'),
            array($this, 'render_expert_verified_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'expert_verified_enabled',
            __('Enable Expert Verified Badge', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_expert_verified',
            array(
                'field' => 'expert_verified_enabled',
                'default' => false,
                'description' => __('Show an "Expert Verified" badge in the author byline area.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'expert_verified_label',
            __('Badge Label', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_expert_verified',
            array('field' => 'expert_verified_label', 'default' => 'Expert verified')
        );

        add_settings_field(
            'expert_verified_title',
            __('Popup Title', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_expert_verified',
            array('field' => 'expert_verified_title', 'default' => 'How is this page expert verified?')
        );

        add_settings_field(
            'expert_verified_content',
            __('Popup Content', 'multi-author-plugin'),
            array($this, 'render_textarea_field'),
            'multi-author-settings',
            'map_expert_verified',
            array('field' => 'expert_verified_content', 'default' => 'We take the accuracy of our content seriously. "Expert verified" means that our Review Board thoroughly evaluated the article for accuracy and clarity. The Review Board comprises a panel of experts whose objective is to ensure that our content is always objective and balanced.')
        );

        add_settings_field(
            'expert_verified_link_text',
            __('Link Text', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_expert_verified',
            array('field' => 'expert_verified_link_text', 'default' => 'About our Review Board')
        );

        add_settings_field(
            'expert_verified_link_url',
            __('Link URL', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_expert_verified',
            array('field' => 'expert_verified_link_url', 'default' => '')
        );

        // Editorial Process Section
        add_settings_section(
            'map_editorial_process',
            __('Editorial Process Link', 'multi-author-plugin'),
            array($this, 'render_editorial_process_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'editorial_process_enabled',
            __('Enable Editorial Process Link', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_editorial_process',
            array(
                'field' => 'editorial_process_enabled',
                'default' => false,
                'description' => __('Show "Learn more about our editorial process" link in all author popups.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'editorial_process_text',
            __('Link Text', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_editorial_process',
            array('field' => 'editorial_process_text', 'default' => 'Learn more about our editorial process')
        );

        add_settings_field(
            'editorial_process_url',
            __('Editorial Process Page URL', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_editorial_process',
            array('field' => 'editorial_process_url', 'default' => '')
        );

        // Review Process Section
        add_settings_section(
            'map_review_process',
            __('Review Process Link', 'multi-author-plugin'),
            array($this, 'render_review_process_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'review_process_enabled',
            __('Enable Review Process Link', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_review_process',
            array(
                'field' => 'review_process_enabled',
                'default' => false,
                'description' => __('Show "Learn more about our review process" link in reviewer popups.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'review_process_text',
            __('Link Text', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_review_process',
            array('field' => 'review_process_text', 'default' => 'Learn more about our review process')
        );

        add_settings_field(
            'review_process_url',
            __('Review Process Page URL', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_review_process',
            array('field' => 'review_process_url', 'default' => '')
        );

        // Fact Check Process Section
        add_settings_section(
            'map_factcheck_process',
            __('Fact-Check Process Link', 'multi-author-plugin'),
            array($this, 'render_factcheck_process_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'factcheck_process_enabled',
            __('Enable Fact-Check Process Link', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_factcheck_process',
            array(
                'field' => 'factcheck_process_enabled',
                'default' => false,
                'description' => __('Show "Learn more about our fact-checking process" link in fact-checker popups.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'factcheck_process_text',
            __('Link Text', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_factcheck_process',
            array('field' => 'factcheck_process_text', 'default' => 'Learn more about our fact-checking process')
        );

        add_settings_field(
            'factcheck_process_url',
            __('Fact-Check Process Page URL', 'multi-author-plugin'),
            array($this, 'render_text_field'),
            'multi-author-settings',
            'map_factcheck_process',
            array('field' => 'factcheck_process_url', 'default' => '')
        );

        // Display Options Section
        add_settings_section(
            'map_display_options',
            __('Display Options', 'multi-author-plugin'),
            array($this, 'render_display_options_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'hide_theme_author',
            __('Hide Theme Author Byline', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'hide_theme_author',
                'default' => false,
                'description' => __('Hide the default WordPress/theme author byline to prevent duplicate author display. The plugin will display all authors including the primary author in a consistent format.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'skip_primary_author',
            __('Skip Primary Author in Plugin Display', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'skip_primary_author',
                'default' => false,
                'description' => __('Use WordPress/theme default for the primary author (with date). The plugin will only display co-authors, reviewers, and fact-checkers.', 'multi-author-plugin')
            )
        );

        $placement_options = array(
            'auto' => __('Automatic (added to content)', 'multi-author-plugin'),
            'manual' => __('Manual (use shortcode or block)', 'multi-author-plugin')
        );

        add_settings_field(
            'contributors_placement',
            __('Contributor Badges Placement', 'multi-author-plugin'),
            array($this, 'render_select_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'contributors_placement',
                'default' => 'auto',
                'options' => $placement_options,
                'description' => __('Manual: place with the [map_contributors] shortcode or the Contributors block.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'sources_placement',
            __('Sources Placement', 'multi-author-plugin'),
            array($this, 'render_select_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'sources_placement',
                'default' => 'auto',
                'options' => $placement_options,
                'description' => __('Manual: place with the [map_sources] shortcode or the Sources block.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'faq_placement',
            __('FAQ Placement', 'multi-author-plugin'),
            array($this, 'render_select_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'faq_placement',
                'default' => 'auto',
                'options' => $placement_options,
                'description' => __('Manual: place with the [map_faq] shortcode or the FAQ block.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'author_archive_credits',
            __('Author Archive Credits', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'author_archive_credits',
                'default' => false,
                'description' => __('On author archive pages, show how many articles the person has reviewed and fact-checked.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'disable_article_schema',
            __('Disable Article Schema', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_display_options',
            array(
                'field' => 'disable_article_schema',
                'default' => false,
                'description' => __('Turn off this plugin\'s Article JSON-LD output. Enable this if your SEO plugin (Yoast, Rank Math, AIOSEO) already outputs Article schema.', 'multi-author-plugin')
            )
        );

        // Primary Author Styling Section
        add_settings_section(
            'map_primary_styling',
            __('Primary Author Row Styling', 'multi-author-plugin'),
            array($this, 'render_primary_styling_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'primary_show_avatar',
            __('Show Primary Author Avatar', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_primary_styling',
            array(
                'field' => 'primary_show_avatar',
                'default' => true,
                'description' => __('Display avatar/photo for the primary author in the byline row.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'primary_avatar_size',
            __('Primary Author Avatar Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_primary_styling',
            array('field' => 'primary_avatar_size', 'default' => 40, 'min' => 20, 'max' => 80)
        );

        add_settings_field(
            'primary_font_size',
            __('Primary Author Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_primary_styling',
            array('field' => 'primary_font_size', 'default' => 15, 'min' => 12, 'max' => 24)
        );

        // Contributors Styling Section
        add_settings_section(
            'map_styling',
            __('Contributors Styling (Co-authors, Reviewers, Fact-checkers)', 'multi-author-plugin'),
            array($this, 'render_styling_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'show_contributor_avatars',
            __('Show Contributor Avatars', 'multi-author-plugin'),
            array($this, 'render_checkbox_field'),
            'multi-author-settings',
            'map_styling',
            array(
                'field' => 'show_contributor_avatars',
                'default' => true,
                'description' => __('Display avatars/photos for co-authors, reviewers, and fact-checkers.', 'multi-author-plugin')
            )
        );

        add_settings_field(
            'avatar_size',
            __('Contributor Avatar Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'avatar_size', 'default' => 50, 'min' => 30, 'max' => 100)
        );

        add_settings_field(
            'name_font_size',
            __('Contributor Name Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'name_font_size', 'default' => 15, 'min' => 10, 'max' => 24)
        );

        add_settings_field(
            'title_font_size',
            __('Job Title Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'title_font_size', 'default' => 13, 'min' => 10, 'max' => 20)
        );

        add_settings_field(
            'role_label_font_size',
            __('Role Label Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'role_label_font_size', 'default' => 11, 'min' => 9, 'max' => 16)
        );
        
        add_settings_field(
            'name_color',
            __('Name Text Color', 'multi-author-plugin'),
            array($this, 'render_color_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'name_color', 'default' => '#000000')
        );
        
        add_settings_field(
            'title_color',
            __('Job Title Text Color', 'multi-author-plugin'),
            array($this, 'render_color_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'title_color', 'default' => '#666666')
        );
        
        add_settings_field(
            'role_label_color',
            __('Role Label Text Color', 'multi-author-plugin'),
            array($this, 'render_color_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'role_label_color', 'default' => '#999999')
        );

        // AI Disclosure Section
        add_settings_section(
            'map_ai_disclosure',
            __('AI Disclosure Settings', 'multi-author-plugin'),
            array($this, 'render_ai_disclosure_section'),
            'multi-author-settings'
        );

        add_settings_field(
            'ai_disclaimer_page',
            __('AI Disclaimer Page URL', 'multi-author-plugin'),
            array($this, 'render_url_field'),
            'multi-author-settings',
            'map_ai_disclosure',
            array(
                'field' => 'ai_disclaimer_page',
                'default' => '',
                'description' => __('Link to your AI usage policy/disclaimer page. This will be shown in the AI badge popup.', 'multi-author-plugin')
            )
        );
    }

    /**
     * Render AI disclosure section description
     */
    public function render_ai_disclosure_section() {
        echo '<p>' . __('Configure AI disclosure badge settings. The AI badge can be enabled per-post in the AI Disclosure Badge meta box when editing posts.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render post types section description
     */
    public function render_post_types_section() {
        echo '<p>' . esc_html__('Choose which content types get the contributor, sources, FAQ, and schema features.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render post types checkbox list
     */
    public function render_post_types_field($args) {
        $enabled = self::get_enabled_post_types();
        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);

        foreach ($post_types as $post_type) {
            printf(
                '<label style="display: block; margin-bottom: 6px;"><input type="checkbox" name="%s[enabled_post_types][]" value="%s" %s /> %s <code>%s</code></label>',
                esc_attr($this->option_name),
                esc_attr($post_type->name),
                checked(in_array($post_type->name, $enabled, true), true, false),
                esc_html($post_type->labels->singular_name),
                esc_html($post_type->name)
            );
        }

        echo '<p class="description">' . esc_html__('The Article Health dashboard always tracks standard posts only.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render role labels section description
     */
    public function render_role_labels_section() {
        echo '<p>' . __('Customize the text labels for different contributor roles.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render expert verified section description
     */
    public function render_expert_verified_section() {
        echo '<p>' . __('Add an "Expert Verified" badge with a hover popup to show content credibility. Enable here globally, then toggle per-post in the Article Contributors meta box.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render editorial process section description
     */
    public function render_editorial_process_section() {
        echo '<p>' . __('Add a link to your editorial process page at the bottom of author hover popups. Enable here, then toggle per-post in the Article Contributors meta box.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render review process section description
     */
    public function render_review_process_section() {
        echo '<p>' . __('Add a link to your review process page at the bottom of reviewer hover popups. Enable here, then toggle per-post in the Article Contributors meta box.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render fact-check process section description
     */
    public function render_factcheck_process_section() {
        echo '<p>' . __('Add a link to your fact-checking process page at the bottom of fact-checker hover popups. Enable here, then toggle per-post in the Article Contributors meta box.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render display options section description
     */
    public function render_display_options_section() {
        echo '<p>' . __('Control how author information is displayed on your site.', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render primary styling section description
     */
    public function render_primary_styling_section() {
        echo '<p>' . __('Customize the appearance of the primary author byline row (with date).', 'multi-author-plugin') . '</p>';
    }

    /**
     * Render styling section description
     */
    public function render_styling_section() {
        echo '<p>' . __('Customize the appearance of co-authors, reviewers, and fact-checkers.', 'multi-author-plugin') . '</p>';
    }
    
    /**
     * Render text field
     */
    public function render_text_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];

        printf(
            '<input type="text" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr($this->option_name),
            esc_attr($args['field']),
            esc_attr($value)
        );
    }

    /**
     * Render URL field
     */
    public function render_url_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];

        printf(
            '<input type="url" name="%s[%s]" value="%s" class="regular-text" placeholder="https://" />',
            esc_attr($this->option_name),
            esc_attr($args['field']),
            esc_attr($value)
        );

        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    /**
     * Render number field
     */
    public function render_number_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];
        
        printf(
            '<input type="number" name="%s[%s]" value="%s" min="%d" max="%d" class="small-text" />',
            esc_attr($this->option_name),
            esc_attr($args['field']),
            esc_attr($value),
            intval($args['min']),
            intval($args['max'])
        );
    }
    
    /**
     * Render select field
     */
    public function render_select_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];

        printf('<select name="%s[%s]">', esc_attr($this->option_name), esc_attr($args['field']));
        foreach ($args['options'] as $option_value => $option_label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($option_value),
                selected($value, $option_value, false),
                esc_html($option_label)
            );
        }
        echo '</select>';

        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    /**
     * Render color field
     */
    public function render_color_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];

        printf(
            '<input type="text" name="%s[%s]" value="%s" class="map-color-picker" data-default-color="%s" />',
            esc_attr($this->option_name),
            esc_attr($args['field']),
            esc_attr($value),
            esc_attr($args['default'])
        );
    }

    /**
     * Render checkbox field
     */
    public function render_checkbox_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];

        printf(
            '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
            esc_attr($this->option_name),
            esc_attr($args['field']),
            checked($value, 1, false),
            isset($args['description']) ? esc_html($args['description']) : ''
        );
    }

    /**
     * Render textarea field
     */
    public function render_textarea_field($args) {
        $settings = get_option($this->option_name, array());
        $value = isset($settings[$args['field']]) ? $settings[$args['field']] : $args['default'];

        printf(
            '<textarea name="%s[%s]" rows="4" cols="60" class="large-text">%s</textarea>',
            esc_attr($this->option_name),
            esc_attr($args['field']),
            esc_textarea($value)
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        // Start from the saved settings so a partial submission never wipes
        // values that weren't part of the submitted form
        $sanitized = get_option($this->option_name, array());
        if (!is_array($sanitized)) {
            $sanitized = array();
        }
        if (!is_array($input)) {
            return $sanitized;
        }

        $defaults = $this->get_settings();

        // Sanitize checkbox fields (an unchecked box is simply absent)
        $checkbox_fields = array('hide_theme_author', 'skip_primary_author', 'primary_show_avatar', 'show_contributor_avatars', 'expert_verified_enabled', 'editorial_process_enabled', 'review_process_enabled', 'factcheck_process_enabled', 'author_archive_credits', 'disable_article_schema');
        foreach ($checkbox_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? 1 : 0;
        }

        // Sanitize placement selects
        $placement_fields = array('contributors_placement', 'sources_placement', 'faq_placement');
        foreach ($placement_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = in_array($input[$field], array('auto', 'manual'), true) ? $input[$field] : 'auto';
            }
        }

        // Sanitize text fields
        $text_fields = array('label_written_by', 'label_coauthor', 'label_reviewed_by', 'label_fact_checked_by', 'expert_verified_label', 'expert_verified_title', 'expert_verified_link_text', 'editorial_process_text', 'review_process_text', 'factcheck_process_text');
        foreach ($text_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_text_field($input[$field]);
            }
        }

        // Sanitize textarea fields (allow some HTML)
        if (isset($input['expert_verified_content'])) {
            $sanitized['expert_verified_content'] = wp_kses_post($input['expert_verified_content']);
        }

        // Sanitize number fields, clamped to sane ranges so a crafted POST
        // can't set a 0px or 9999px value
        $number_fields = array(
            'primary_avatar_size' => array(16, 300),
            'avatar_size' => array(16, 300),
            'primary_font_size' => array(8, 72),
            'name_font_size' => array(8, 72),
            'title_font_size' => array(8, 72),
            'role_label_font_size' => array(8, 72)
        );
        foreach ($number_fields as $field => $range) {
            if (isset($input[$field])) {
                $sanitized[$field] = min($range[1], max($range[0], absint($input[$field])));
            }
        }

        // Sanitize color fields — fall back to the default rather than
        // storing null (which would render "color: !important")
        $color_fields = array('name_color', 'title_color', 'role_label_color');
        foreach ($color_fields as $field) {
            if (isset($input[$field])) {
                $color = sanitize_hex_color($input[$field]);
                $sanitized[$field] = $color ? $color : $defaults[$field];
            }
        }

        // Sanitize URL fields as URLs, not plain text
        $url_fields = array('ai_disclaimer_page', 'expert_verified_link_url', 'editorial_process_url', 'review_process_url', 'factcheck_process_url');
        foreach ($url_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = esc_url_raw($input[$field]);
            }
        }

        // Sanitize enabled post types against the real public post types.
        // The field is always rendered, so an empty submission means the
        // user unchecked everything — fall back to 'post'.
        $valid_types = get_post_types(array('public' => true));
        unset($valid_types['attachment']);
        $submitted_types = (isset($input['enabled_post_types']) && is_array($input['enabled_post_types']))
            ? array_values(array_intersect($input['enabled_post_types'], $valid_types))
            : array();
        $sanitized['enabled_post_types'] = !empty($submitted_types) ? $submitted_types : array('post');

        return $sanitized;
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'map_messages',
                'map_message',
                __('Settings Saved', 'multi-author-plugin'),
                'updated'
            );
        }
        
        settings_errors('map_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('map_settings_group');
                do_settings_sections('multi-author-settings');
                submit_button(__('Save Settings', 'multi-author-plugin'));
                ?>
            </form>
            
            <?php $preview = $this->get_settings(); ?>
            <div class="map-settings-preview">
                <h2><?php _e('Live Preview', 'multi-author-plugin'); ?></h2>
                <p class="description"><?php _e('Updates as you change styling options. Save to apply on your site.', 'multi-author-plugin'); ?></p>

                <div id="map-live-preview" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; max-width: 640px;">
                    <!-- Primary byline sample -->
                    <div class="map-preview-primary" style="display: flex; align-items: center; gap: 8px; font-size: <?php echo intval($preview['primary_font_size']); ?>px;">
                        <span><?php esc_html_e('By', 'multi-author-plugin'); ?></span>
                        <img class="map-preview-primary-avatar"
                             src="<?php echo esc_url(get_avatar_url(get_current_user_id(), array('size' => 80))); ?>"
                             alt=""
                             style="border-radius: 50%; width: <?php echo intval($preview['primary_avatar_size']); ?>px; height: <?php echo intval($preview['primary_avatar_size']); ?>px; <?php echo empty($preview['primary_show_avatar']) ? 'display: none;' : ''; ?>" />
                        <strong class="map-preview-primary-name">Jane Doe</strong>
                        <span style="color: #999;">|</span>
                        <span><?php echo esc_html(date_i18n('F jS, Y')); ?></span>
                    </div>

                    <!-- Contributor card sample -->
                    <div class="map-preview-card" style="display: flex; align-items: center; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f1;">
                        <img class="map-preview-avatar"
                             src="<?php echo esc_url(get_avatar_url(get_current_user_id(), array('size' => 100))); ?>"
                             alt=""
                             style="border-radius: 50%; width: <?php echo intval($preview['avatar_size']); ?>px; height: <?php echo intval($preview['avatar_size']); ?>px; <?php echo empty($preview['show_contributor_avatars']) ? 'display: none;' : ''; ?>" />
                        <div>
                            <div class="map-preview-role" style="font-size: <?php echo intval($preview['role_label_font_size']); ?>px; color: <?php echo esc_attr($preview['role_label_color']); ?>; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo esc_html($preview['label_reviewed_by']); ?></div>
                            <div class="map-preview-name" style="font-size: <?php echo intval($preview['name_font_size']); ?>px; color: <?php echo esc_attr($preview['name_color']); ?>; font-weight: 600;">John Smith</div>
                            <div class="map-preview-title" style="font-size: <?php echo intval($preview['title_font_size']); ?>px; color: <?php echo esc_attr($preview['title_color']); ?>;">Senior Editor</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue settings page assets
     */
    public function enqueue_settings_assets($hook) {
        if ('settings_page_multi-author-settings' !== $hook) {
            return;
        }
        
        // Enqueue WordPress color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        // Enqueue custom settings CSS
        wp_enqueue_style(
            'map-settings-styles',
            MAP_PLUGIN_URL . 'admin/css/settings-styles.css',
            array(),
            MAP_VERSION
        );
        
        // Enqueue custom settings JS
        wp_enqueue_script(
            'map-settings-scripts',
            MAP_PLUGIN_URL . 'admin/js/settings-scripts.js',
            array('jquery', 'wp-color-picker'),
            MAP_VERSION,
            true
        );
    }
    
    /**
     * Output custom styles to frontend
     */
    public function output_custom_styles() {
        if (!is_singular(MAP_Settings::get_enabled_post_types())) {
            return;
        }

        $settings = $this->get_settings();

        ?>
        <style type="text/css">
            /* Primary author row styling */
            .map-primary-row {
                font-size: <?php echo intval($settings['primary_font_size']); ?>px !important;
            }
            .map-primary-row .map-contributor-avatar {
                width: <?php echo intval($settings['primary_avatar_size']); ?>px !important;
                height: <?php echo intval($settings['primary_avatar_size']); ?>px !important;
            }
            <?php if (empty($settings['primary_show_avatar'])) : ?>
            .map-primary-row .map-contributor-avatar {
                display: none !important;
            }
            <?php endif; ?>

            /* Contributors styling */
            .map-contributors-inline .map-contributor-avatar {
                width: <?php echo intval($settings['avatar_size']); ?>px !important;
                height: <?php echo intval($settings['avatar_size']); ?>px !important;
            }
            <?php if (empty($settings['show_contributor_avatars'])) : ?>
            .map-contributors-inline .map-contributor-photo {
                display: none !important;
            }
            <?php endif; ?>
            .map-contributor-name,
            .map-contributor-name a {
                font-size: <?php echo intval($settings['name_font_size']); ?>px !important;
                color: <?php echo esc_attr($settings['name_color']); ?> !important;
            }
            .map-contributor-title {
                font-size: <?php echo intval($settings['title_font_size']); ?>px !important;
                color: <?php echo esc_attr($settings['title_color']); ?> !important;
            }
            .map-contributor-role-label {
                font-size: <?php echo intval($settings['role_label_font_size']); ?>px !important;
                color: <?php echo esc_attr($settings['role_label_color']); ?> !important;
            }
            <?php if (!empty($settings['hide_theme_author'])) : ?>
            /* Fallback CSS to hide theme author/date elements */
            /* Hide the entire entry-meta and header meta sections on single posts */
            .single-post .entry-meta,
            .single-post .posted-by,
            .single-post .posted-on,
            .single-post .published-date,
            .single-post .last-updated-date,
            .ast-article-single .entry-meta,
            .ast-article-single .posted-by,
            .ast-article-single .posted-on,
            .ast-article-single .published-date,
            .ast-article-single .last-updated-date,
            .ast-single-post .entry-meta,
            header.entry-header .entry-meta,
            /* Hide Astra single post header meta area */
            .ast-single-post-order .ast-single-post-meta,
            .entry-header .ast-single-post-meta,
            .ast-article-single .ast-single-post-meta,
            /* Astra-only catch-all for remaining meta output; scoped so it
               cannot hide legitimate header elements on other themes */
            .ast-single-post .entry-header > :not(h1):not(.entry-title):not(.map-contributors-section) {
                display: none !important;
            }
            <?php endif; ?>
        </style>
        <?php
    }
    
    /**
     * Get settings with defaults
     */
    public function get_settings() {
        $defaults = array(
            'enabled_post_types' => array('post'),
            'hide_theme_author' => 0,
            'skip_primary_author' => 0,
            'contributors_placement' => 'auto',
            'sources_placement' => 'auto',
            'faq_placement' => 'auto',
            'author_archive_credits' => 0,
            'disable_article_schema' => 0,
            'label_written_by' => 'Written by',
            'label_coauthor' => 'Co-Author',
            'label_reviewed_by' => 'Reviewed by',
            'label_fact_checked_by' => 'Fact-Checked by',
            // Expert Verified Badge
            'expert_verified_enabled' => 0,
            'expert_verified_label' => 'Expert verified',
            'expert_verified_title' => 'How is this page expert verified?',
            'expert_verified_content' => 'We take the accuracy of our content seriously. "Expert verified" means that our Review Board thoroughly evaluated the article for accuracy and clarity. The Review Board comprises a panel of experts whose objective is to ensure that our content is always objective and balanced.',
            'expert_verified_link_text' => 'About our Review Board',
            'expert_verified_link_url' => '',
            // Editorial Process Link
            'editorial_process_enabled' => 0,
            'editorial_process_text' => 'Learn more about our editorial process',
            'editorial_process_url' => '',
            // Review Process Link
            'review_process_enabled' => 0,
            'review_process_text' => 'Learn more about our review process',
            'review_process_url' => '',
            // Fact-Check Process Link
            'factcheck_process_enabled' => 0,
            'factcheck_process_text' => 'Learn more about our fact-checking process',
            'factcheck_process_url' => '',
            // Primary author styling
            'primary_show_avatar' => 1,
            'primary_avatar_size' => 40,
            'primary_font_size' => 15,
            // Contributor styling
            'show_contributor_avatars' => 1,
            'avatar_size' => 50,
            'name_font_size' => 15,
            'title_font_size' => 13,
            'role_label_font_size' => 11,
            'name_color' => '#000000',
            'title_color' => '#666666',
            'role_label_color' => '#999999'
        );

        $settings = get_option($this->option_name, array());

        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Get the post types the plugin's features apply to
     *
     * @return array Post type names (always at least array('post'))
     */
    public static function get_enabled_post_types() {
        $types = self::get_setting('enabled_post_types', array('post'));

        if (!is_array($types) || empty($types)) {
            $types = array('post');
        }

        $types = apply_filters('map_supported_post_types', $types);

        return is_array($types) && !empty($types) ? array_values($types) : array('post');
    }

    /**
     * Get a specific setting value
     */
    public static function get_setting($key, $default = '') {
        $instance = self::get_instance();
        $settings = $instance->get_settings();
        
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
    
    /**
     * Get role label
     */
    public static function get_role_label($role) {
        $labels = array(
            'author' => self::get_setting('label_written_by', 'Written by'),
            'coauthor' => self::get_setting('label_coauthor', 'Co-Author'),
            'reviewer' => self::get_setting('label_reviewed_by', 'Reviewed by'),
            'fact_checker' => self::get_setting('label_fact_checked_by', 'Fact-Checked by')
        );
        
        return isset($labels[$role]) ? $labels[$role] : '';
    }
}