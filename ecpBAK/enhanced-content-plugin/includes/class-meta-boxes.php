<?php
/**
 * Meta Boxes Class
 * Handles the admin interface for contributors and sources
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ECP_Meta_Boxes {
    
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
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'), 10, 2);
        add_action('wp_ajax_map_search_users', array($this, 'ajax_search_users'));
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        $post_types = ECP_Settings::get_enabled_post_types();

        add_meta_box(
            'map_contributors',
            __('Article Contributors', 'enhanced-content-plugin'),
            array($this, 'render_contributors_meta_box'),
            $post_types,
            'normal',
            'high'
        );

        add_meta_box(
            'map_sources',
            __('Article Sources & Citations', 'enhanced-content-plugin'),
            array($this, 'render_sources_meta_box'),
            $post_types,
            'normal',
            'default'
        );

        add_meta_box(
            'map_ai_disclaimer',
            __('AI Disclosure Badge', 'enhanced-content-plugin'),
            array($this, 'render_ai_disclaimer_meta_box'),
            $post_types,
            'normal',
            'default'
        );

        add_meta_box(
            'map_fact_check',
            __('Fact Check & Corrections', 'enhanced-content-plugin'),
            array($this, 'render_fact_check_meta_box'),
            $post_types,
            'normal',
            'default'
        );
    }

    /**
     * Render fact check & corrections meta box
     */
    public function render_fact_check_meta_box($post) {
        wp_nonce_field('map_fact_check_nonce', 'map_fact_check_nonce_field');

        $fact_checked_date = get_post_meta($post->ID, '_map_fact_checked_date', true);
        $corrections = get_post_meta($post->ID, '_map_corrections', true);
        if (!is_array($corrections)) {
            $corrections = array();
        }
        ?>
        <div class="map-fact-check-wrapper">
            <div class="map-fact-check-date-field">
                <label for="map_fact_checked_date">
                    <strong><?php _e('Last fact-checked on', 'enhanced-content-plugin'); ?></strong>
                </label>
                <input type="date"
                       id="map_fact_checked_date"
                       name="map_fact_checked_date"
                       value="<?php echo esc_attr($fact_checked_date); ?>" />
                <p class="description">
                    <?php _e('Shown in the article byline, separate from the last-modified date. Leave empty to hide.', 'enhanced-content-plugin'); ?>
                </p>
            </div>

            <div class="map-corrections-header">
                <h4><?php _e('Corrections Log', 'enhanced-content-plugin'); ?></h4>
                <p class="description"><?php _e('Log corrections transparently. Entries are displayed in a "Corrections & Updates" section at the end of the article.', 'enhanced-content-plugin'); ?></p>
            </div>

            <div id="map-corrections-list">
                <?php foreach ($corrections as $index => $correction) : ?>
                    <?php $this->render_correction_item($index, $correction); ?>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button" id="map-add-correction">
                <?php _e('+ Add Correction', 'enhanced-content-plugin'); ?>
            </button>
        </div>

        <!-- Correction template (hidden) -->
        <script type="text/template" id="map-correction-template">
            <?php $this->render_correction_item('{{INDEX}}', array('date' => '', 'text' => '')); ?>
        </script>
        <?php
    }

    /**
     * Render single correction item
     */
    private function render_correction_item($index, $correction) {
        $date = isset($correction['date']) ? $correction['date'] : '';
        $text = isset($correction['text']) ? $correction['text'] : '';
        ?>
        <div class="map-correction-item">
            <input type="date"
                   name="map_corrections[<?php echo esc_attr($index); ?>][date]"
                   value="<?php echo esc_attr($date); ?>"
                   class="map-correction-date" />
            <textarea name="map_corrections[<?php echo esc_attr($index); ?>][text]"
                      placeholder="<?php esc_attr_e('Describe the correction, e.g. "Corrected the survey sample size in section 2."', 'enhanced-content-plugin'); ?>"
                      class="map-correction-text widefat"
                      rows="2"><?php echo esc_textarea($text); ?></textarea>
            <button type="button" class="button-link map-remove-correction" aria-label="<?php esc_attr_e('Remove', 'enhanced-content-plugin'); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
        <?php
    }
    
    /**
     * Render contributors meta box
     */
    public function render_contributors_meta_box($post) {
        wp_nonce_field('map_contributors_nonce', 'map_contributors_nonce_field');

        $contributors = get_post_meta($post->ID, '_article_contributors', true);
        if (!is_array($contributors)) {
            $contributors = array();
        }
        // Guarantee all keys exist even if the stored meta is a partial array
        $contributors = wp_parse_args($contributors, array(
            'authors' => array(),
            'reviewers' => array(),
            'fact_checkers' => array()
        ));

        // Get Expert Verified setting for this post
        $expert_verified = get_post_meta($post->ID, '_map_expert_verified', true);
        $expert_verified_enabled = ECP_Settings::get_setting('expert_verified_enabled', 0);

        // Get process link settings for this post
        $process_links = get_post_meta($post->ID, '_map_process_links', true);
        if (!is_array($process_links)) {
            $process_links = array();
        }

        // Get global process settings
        $editorial_enabled = ECP_Settings::get_setting('editorial_process_enabled', 0);
        $review_enabled = ECP_Settings::get_setting('review_process_enabled', 0);
        $factcheck_enabled = ECP_Settings::get_setting('factcheck_process_enabled', 0);

        ?>
        <div class="map-contributors-wrapper">
            <p class="description">
                <?php _e('Add contributors to this article. Users will be displayed in the order shown below.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if ($expert_verified_enabled) : ?>
            <!-- Expert Verified Badge Toggle -->
            <div class="map-contributor-section map-expert-verified-section" style="background: #f0f7ff; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #0073aa;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="map_expert_verified" value="1" <?php checked($expert_verified, '1'); ?> />
                    <strong><?php _e('Show "Expert Verified" badge on this post', 'enhanced-content-plugin'); ?></strong>
                </label>
                <p class="description" style="margin: 8px 0 0 26px;">
                    <?php _e('When enabled, the Expert Verified badge will appear in the author byline for this post.', 'enhanced-content-plugin'); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Co-Authors Section -->
            <div class="map-contributor-section">
                <h4><?php _e('Co-Authors', 'enhanced-content-plugin'); ?></h4>
                <p class="description"><?php _e('The post author is automatically the primary author. Add co-authors here if applicable.', 'enhanced-content-plugin'); ?></p>
                <div class="map-contributor-list" id="map-authors-list" data-type="authors" data-empty-message="<?php esc_attr_e('No co-authors added yet.', 'enhanced-content-plugin'); ?>">
                    <?php $this->render_contributor_items($contributors['authors'], 'authors'); ?>
                </div>
                <button type="button" class="button map-add-contributor" data-type="authors">
                    <?php _e('+ Add Co-Author', 'enhanced-content-plugin'); ?>
                </button>
                <?php if ($editorial_enabled) : ?>
                <div class="map-process-link-toggle" style="margin-top: 10px; padding: 8px 12px; background: #f9f9f9; border-radius: 4px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="map_process_links[show_editorial_for_authors]" value="1" <?php checked(!empty($process_links['show_editorial_for_authors'])); ?> />
                        <span><?php _e('Show Editorial Process link in author popups', 'enhanced-content-plugin'); ?></span>
                    </label>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Reviewers Section -->
            <div class="map-contributor-section">
                <h4><?php _e('Reviewers', 'enhanced-content-plugin'); ?></h4>
                <div class="map-contributor-list" id="map-reviewers-list" data-type="reviewers" data-empty-message="<?php esc_attr_e('No reviewers added yet.', 'enhanced-content-plugin'); ?>">
                    <?php $this->render_contributor_items($contributors['reviewers'], 'reviewers'); ?>
                </div>
                <button type="button" class="button map-add-contributor" data-type="reviewers">
                    <?php _e('+ Add Reviewer', 'enhanced-content-plugin'); ?>
                </button>
                <?php if ($review_enabled) : ?>
                <div class="map-process-link-toggle" style="margin-top: 10px; padding: 8px 12px; background: #f9f9f9; border-radius: 4px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="map_process_links[show_review_for_reviewers]" value="1" <?php checked(!empty($process_links['show_review_for_reviewers'])); ?> />
                        <span><?php _e('Show Review Process link in reviewer popups', 'enhanced-content-plugin'); ?></span>
                    </label>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Fact Checkers Section -->
            <div class="map-contributor-section">
                <h4><?php _e('Fact Checkers', 'enhanced-content-plugin'); ?></h4>
                <div class="map-contributor-list" id="map-fact-checkers-list" data-type="fact_checkers" data-empty-message="<?php esc_attr_e('No fact checkers added yet.', 'enhanced-content-plugin'); ?>">
                    <?php $this->render_contributor_items($contributors['fact_checkers'], 'fact_checkers'); ?>
                </div>
                <button type="button" class="button map-add-contributor" data-type="fact_checkers">
                    <?php _e('+ Add Fact Checker', 'enhanced-content-plugin'); ?>
                </button>
                <?php if ($factcheck_enabled) : ?>
                <div class="map-process-link-toggle" style="margin-top: 10px; padding: 8px 12px; background: #f9f9f9; border-radius: 4px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="map_process_links[show_factcheck_for_factcheckers]" value="1" <?php checked(!empty($process_links['show_factcheck_for_factcheckers'])); ?> />
                        <span><?php _e('Show Fact-Check Process link in fact-checker popups', 'enhanced-content-plugin'); ?></span>
                    </label>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- User Search Modal (hidden by default) -->
        <div id="map-user-search-modal" class="map-modal" style="display: none;">
            <div class="map-modal-content">
                <span class="map-modal-close">&times;</span>
                <h3><?php _e('Search Users', 'enhanced-content-plugin'); ?></h3>
                <input type="text" id="map-user-search-input" placeholder="<?php esc_attr_e('Search by name or email...', 'enhanced-content-plugin'); ?>" />
                <div id="map-user-search-results"></div>
                <button type="button" class="button button-primary" id="map-user-search-select">
                    <?php _e('Add Selected', 'enhanced-content-plugin'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render contributor items
     */
    private function render_contributor_items($user_ids, $type) {
        if (empty($user_ids)) {
            return;
        }
        
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $this->render_single_contributor($user, $type);
            }
        }
    }
    
    /**
     * Render single contributor item
     */
    private function render_single_contributor($user, $type) {
        ?>
        <div class="map-contributor-item" data-user-id="<?php echo esc_attr($user->ID); ?>">
            <span class="map-contributor-drag-handle">⋮⋮</span>
            <img src="<?php echo esc_url(get_avatar_url($user->ID, array('size' => 32))); ?>" alt="" class="map-contributor-avatar" />
            <span class="map-contributor-name"><?php echo esc_html($user->display_name); ?></span>
            <span class="map-contributor-email">(<?php echo esc_html($user->user_email); ?>)</span>
            <button type="button" class="button-link map-remove-contributor" aria-label="<?php esc_attr_e('Remove', 'enhanced-content-plugin'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
            <input type="hidden" name="map_contributors[<?php echo esc_attr($type); ?>][]" value="<?php echo esc_attr($user->ID); ?>" />
        </div>
        <?php
    }
    
    /**
     * Render sources meta box
     */
    public function render_sources_meta_box($post) {
        wp_nonce_field('map_sources_nonce', 'map_sources_nonce_field');
        
        $sources = get_post_meta($post->ID, '_article_sources', true);
        if (!is_array($sources)) {
            $sources = array();
        }
        
        ?>
        <div class="map-sources-wrapper">
            <p class="description">
                <?php _e('Add source citations for this article. These will be displayed at the bottom of the article.', 'enhanced-content-plugin'); ?>
            </p>
            
            <div id="map-sources-list">
                <?php
                if (!empty($sources)) {
                    foreach ($sources as $index => $source) {
                        $this->render_source_item($index, $source);
                    }
                }
                ?>
            </div>
            
            <button type="button" class="button" id="map-add-source">
                <?php _e('+ Add Source', 'enhanced-content-plugin'); ?>
            </button>
        </div>
        
        <!-- Source template (hidden) -->
        <script type="text/template" id="map-source-template">
            <?php $this->render_source_item('{{INDEX}}', array('url' => '', 'label' => '', 'description' => '')); ?>
        </script>
        <?php
    }
    
    /**
     * Render single source item
     */
    private function render_source_item($index, $source) {
        $url = isset($source['url']) ? $source['url'] : '';
        $label = isset($source['label']) ? $source['label'] : '';
        $description = isset($source['description']) ? $source['description'] : '';
        ?>
        <div class="map-source-item">
            <span class="map-source-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'enhanced-content-plugin'); ?>">⋮⋮</span>
            <span class="map-source-number"><?php echo is_numeric($index) ? ($index + 1) : ''; ?></span>
            <div class="map-source-fields">
                <input type="url"
                       name="map_sources[<?php echo esc_attr($index); ?>][url]"
                       placeholder="<?php esc_attr_e('https://example.com/source', 'enhanced-content-plugin'); ?>"
                       value="<?php echo esc_attr($url); ?>"
                       class="map-source-url widefat" />
                <input type="text"
                       name="map_sources[<?php echo esc_attr($index); ?>][label]"
                       placeholder="<?php esc_attr_e('Link text (optional - displays instead of raw URL)', 'enhanced-content-plugin'); ?>"
                       value="<?php echo esc_attr($label); ?>"
                       class="map-source-label widefat" />
                <textarea name="map_sources[<?php echo esc_attr($index); ?>][description]"
                          placeholder="<?php esc_attr_e('Additional details or description (optional - not linked)', 'enhanced-content-plugin'); ?>"
                          class="map-source-description widefat"
                          rows="2"><?php echo esc_textarea($description); ?></textarea>
            </div>
            <button type="button" class="button-link map-remove-source" aria-label="<?php esc_attr_e('Remove', 'enhanced-content-plugin'); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
        <?php
    }

    /**
     * Render AI disclaimer meta box
     */
    public function render_ai_disclaimer_meta_box($post) {
        wp_nonce_field('map_ai_disclaimer_nonce', 'map_ai_disclaimer_nonce_field');

        $ai_settings = get_post_meta($post->ID, '_map_ai_disclaimer', true);
        if (!is_array($ai_settings)) {
            $ai_settings = array(
                'badge_type' => 'none',
                'ai_uses' => array(),
                'custom_uses' => array()
            );
        }

        $badge_type = isset($ai_settings['badge_type']) ? $ai_settings['badge_type'] : 'none';
        $ai_uses = isset($ai_settings['ai_uses']) ? $ai_settings['ai_uses'] : array();
        $custom_uses = isset($ai_settings['custom_uses']) ? $ai_settings['custom_uses'] : array();

        // Predefined AI use options
        $predefined_uses = array(
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

        ?>
        <div class="map-ai-disclaimer-wrapper">
            <p class="description">
                <?php _e('Display an AI disclosure badge on this article to inform readers about AI usage.', 'enhanced-content-plugin'); ?>
            </p>

            <!-- Badge Type Selection -->
            <div class="map-ai-badge-type-section">
                <h4><?php _e('Badge Type', 'enhanced-content-plugin'); ?></h4>

                <label class="map-ai-badge-option">
                    <input type="radio" name="map_ai_badge_type" value="none" <?php checked($badge_type, 'none'); ?> />
                    <span class="map-ai-badge-label"><?php _e('No Badge', 'enhanced-content-plugin'); ?></span>
                    <span class="map-ai-badge-desc"><?php _e('Do not display any AI disclosure badge', 'enhanced-content-plugin'); ?></span>
                </label>

                <label class="map-ai-badge-option map-ai-badge-no-ai">
                    <input type="radio" name="map_ai_badge_type" value="no_ai" <?php checked($badge_type, 'no_ai'); ?> />
                    <span class="map-ai-badge-label"><?php _e('No AI Used', 'enhanced-content-plugin'); ?></span>
                    <span class="map-ai-badge-desc"><?php _e('This article was created entirely without AI assistance', 'enhanced-content-plugin'); ?></span>
                </label>

                <label class="map-ai-badge-option map-ai-badge-enhanced">
                    <input type="radio" name="map_ai_badge_type" value="ai_enhanced" <?php checked($badge_type, 'ai_enhanced'); ?> />
                    <span class="map-ai-badge-label"><?php _e('AI Enhanced', 'enhanced-content-plugin'); ?></span>
                    <span class="map-ai-badge-desc"><?php _e('AI tools were used in the creation of this article', 'enhanced-content-plugin'); ?></span>
                </label>
            </div>

            <!-- AI Uses Section (shown when AI Enhanced is selected) -->
            <div class="map-ai-uses-section" id="map-ai-uses-section" style="<?php echo $badge_type !== 'ai_enhanced' ? 'display: none;' : ''; ?>">
                <h4><?php _e('How was AI used?', 'enhanced-content-plugin'); ?></h4>
                <p class="description"><?php _e('Select all the ways AI was used in creating this article:', 'enhanced-content-plugin'); ?></p>

                <div class="map-ai-uses-grid">
                    <?php foreach ($predefined_uses as $key => $label) : ?>
                        <label class="map-ai-use-checkbox">
                            <input type="checkbox"
                                   name="map_ai_uses[]"
                                   value="<?php echo esc_attr($key); ?>"
                                   <?php checked(in_array($key, $ai_uses)); ?> />
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- Custom AI Uses -->
                <div class="map-ai-custom-uses">
                    <h4><?php _e('Custom AI Uses', 'enhanced-content-plugin'); ?></h4>
                    <p class="description"><?php _e('Add any additional ways AI was used that are not listed above:', 'enhanced-content-plugin'); ?></p>

                    <div id="map-ai-custom-uses-list">
                        <?php if (!empty($custom_uses)) : ?>
                            <?php foreach ($custom_uses as $index => $custom_use) : ?>
                                <div class="map-ai-custom-use-item">
                                    <input type="text"
                                           name="map_ai_custom_uses[]"
                                           value="<?php echo esc_attr($custom_use); ?>"
                                           placeholder="<?php esc_attr_e('e.g., Voice cloning, 3D modeling', 'enhanced-content-plugin'); ?>"
                                           class="widefat" />
                                    <button type="button" class="button-link map-remove-custom-use" aria-label="<?php esc_attr_e('Remove', 'enhanced-content-plugin'); ?>">
                                        <span class="dashicons dashicons-no-alt"></span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="button" id="map-add-custom-ai-use">
                        <?php _e('+ Add Custom AI Use', 'enhanced-content-plugin'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Custom AI Use template (hidden) -->
        <script type="text/template" id="map-ai-custom-use-template">
            <div class="map-ai-custom-use-item">
                <input type="text"
                       name="map_ai_custom_uses[]"
                       value=""
                       placeholder="<?php esc_attr_e('e.g., Voice cloning, 3D modeling', 'enhanced-content-plugin'); ?>"
                       class="widefat" />
                <button type="button" class="button-link map-remove-custom-use" aria-label="<?php esc_attr_e('Remove', 'enhanced-content-plugin'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
        </script>
        <?php
    }

    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id, $post) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check post type
        if (!in_array($post->post_type, ECP_Settings::get_enabled_post_types(), true)) {
            return;
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save contributors
        if (isset($_POST['map_contributors_nonce_field']) &&
            wp_verify_nonce($_POST['map_contributors_nonce_field'], 'map_contributors_nonce')) {

            $contributors = array(
                'authors' => array(),
                'reviewers' => array(),
                'fact_checkers' => array()
            );

            if (isset($_POST['map_contributors']) && is_array($_POST['map_contributors'])) {
                foreach ($_POST['map_contributors'] as $type => $user_ids) {
                    if (in_array($type, array('authors', 'reviewers', 'fact_checkers')) && is_array($user_ids)) {
                        $user_ids = array_map('intval', array_filter($user_ids));
                        // Only keep IDs that belong to real users
                        $contributors[$type] = array_values(array_filter($user_ids, function($user_id) {
                            return $user_id > 0 && get_userdata($user_id);
                        }));
                    }
                }
            }

            update_post_meta($post_id, '_article_contributors', $contributors);

            // Remember recently used contributors for the picker
            $used_ids = array_merge($contributors['authors'], $contributors['reviewers'], $contributors['fact_checkers']);
            if (!empty($used_ids)) {
                $recent = get_option('map_recent_contributors', array());
                $recent = array_slice(array_unique(array_merge($used_ids, is_array($recent) ? $recent : array())), 0, 10);
                update_option('map_recent_contributors', $recent, false);
            }

            // Save Expert Verified setting
            $expert_verified = isset($_POST['map_expert_verified']) ? '1' : '0';
            update_post_meta($post_id, '_map_expert_verified', $expert_verified);

            // Save process link settings
            $process_links = array(
                'show_editorial_for_authors' => isset($_POST['map_process_links']['show_editorial_for_authors']) ? 1 : 0,
                'show_review_for_reviewers' => isset($_POST['map_process_links']['show_review_for_reviewers']) ? 1 : 0,
                'show_factcheck_for_factcheckers' => isset($_POST['map_process_links']['show_factcheck_for_factcheckers']) ? 1 : 0
            );
            update_post_meta($post_id, '_map_process_links', $process_links);
        }
        
        // Save sources
        if (isset($_POST['map_sources_nonce_field']) &&
            wp_verify_nonce($_POST['map_sources_nonce_field'], 'map_sources_nonce')) {

            $sources = array();

            if (isset($_POST['map_sources']) && is_array($_POST['map_sources'])) {
                foreach (wp_unslash($_POST['map_sources']) as $source) {
                    if (is_array($source) && !empty($source['url'])) {
                        $sources[] = array(
                            'url' => esc_url_raw($source['url']),
                            'label' => isset($source['label']) ? sanitize_text_field($source['label']) : '',
                            'description' => isset($source['description']) ? sanitize_textarea_field($source['description']) : ''
                        );
                    }
                }
            }

            update_post_meta($post_id, '_article_sources', $sources);
        }

        // Save AI disclaimer settings
        if (isset($_POST['map_ai_disclaimer_nonce_field']) &&
            wp_verify_nonce($_POST['map_ai_disclaimer_nonce_field'], 'map_ai_disclaimer_nonce')) {

            $badge_type = isset($_POST['map_ai_badge_type']) ? sanitize_text_field($_POST['map_ai_badge_type']) : 'none';

            // Validate badge type
            if (!in_array($badge_type, array('none', 'no_ai', 'ai_enhanced'))) {
                $badge_type = 'none';
            }

            $ai_uses = array();
            if (isset($_POST['map_ai_uses']) && is_array($_POST['map_ai_uses'])) {
                $ai_uses = array_map('sanitize_text_field', $_POST['map_ai_uses']);
                // Only accept keys from the predefined list
                $ai_uses = array_values(array_intersect($ai_uses, array_keys(ECP_Frontend_Display::get_ai_use_labels())));
            }

            $custom_uses = array();
            if (isset($_POST['map_ai_custom_uses']) && is_array($_POST['map_ai_custom_uses'])) {
                foreach (wp_unslash($_POST['map_ai_custom_uses']) as $custom_use) {
                    $sanitized = sanitize_text_field($custom_use);
                    if (!empty($sanitized)) {
                        $custom_uses[] = $sanitized;
                    }
                }
            }

            $ai_settings = array(
                'badge_type' => $badge_type,
                'ai_uses' => $ai_uses,
                'custom_uses' => $custom_uses
            );

            update_post_meta($post_id, '_map_ai_disclaimer', $ai_settings);
        }

        // Save fact-check date and corrections log
        if (isset($_POST['map_fact_check_nonce_field']) &&
            wp_verify_nonce($_POST['map_fact_check_nonce_field'], 'map_fact_check_nonce')) {

            $fact_checked_date = isset($_POST['map_fact_checked_date'])
                ? sanitize_text_field(wp_unslash($_POST['map_fact_checked_date']))
                : '';
            // Only accept a real Y-m-d date
            if ($fact_checked_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fact_checked_date)) {
                $fact_checked_date = '';
            }
            if ($fact_checked_date) {
                update_post_meta($post_id, '_map_fact_checked_date', $fact_checked_date);
            } else {
                delete_post_meta($post_id, '_map_fact_checked_date');
            }

            $corrections = array();
            if (isset($_POST['map_corrections']) && is_array($_POST['map_corrections'])) {
                foreach (wp_unslash($_POST['map_corrections']) as $correction) {
                    if (!is_array($correction)) {
                        continue;
                    }
                    $date = isset($correction['date']) ? sanitize_text_field($correction['date']) : '';
                    $text = isset($correction['text']) ? sanitize_textarea_field($correction['text']) : '';
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        $date = '';
                    }
                    // A correction needs at least a description
                    if ($text !== '') {
                        $corrections[] = array('date' => $date, 'text' => $text);
                    }
                }
            }

            if (!empty($corrections)) {
                update_post_meta($post_id, '_map_corrections', $corrections);
            } else {
                delete_post_meta($post_id, '_map_corrections');
            }
        }
    }

    /**
     * AJAX: Search users
     */
    public function ajax_search_users() {
        check_ajax_referer('map_admin_nonce', 'nonce');

        // Only users who can edit posts may search; the results include
        // email addresses, which must not be exposed to arbitrary users.
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'enhanced-content-plugin'), 403);
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        // Initial modal open: offer recently used contributors before typing
        if ($search === '' && !empty($_POST['initial'])) {
            $recent_ids = get_option('map_recent_contributors', array());
            $users = array();
            foreach ((array) $recent_ids as $recent_id) {
                $user = get_userdata($recent_id);
                if ($user) {
                    $users[] = $user;
                }
            }
        } else {
            $args = array(
                'search' => '*' . $search . '*',
                'search_columns' => array('user_login', 'user_email', 'display_name'),
                'number' => 20,
                'orderby' => 'display_name',
                'order' => 'ASC'
            );

            $users = get_users($args);
        }
        
        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'avatar' => get_avatar_url($user->ID, array('size' => 32))
            );
        }
        
        wp_send_json_success($results);
    }
}