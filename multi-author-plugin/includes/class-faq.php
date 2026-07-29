<?php
/**
 * FAQ Section Class
 * Adds FAQ functionality with schema markup
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_FAQ {

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
        // Add FAQ meta box
        add_action('add_meta_boxes', array($this, 'add_faq_meta_box'));

        // Save FAQ data
        add_action('save_post', array($this, 'save_faq_data'), 10, 2);

        // Add FAQ section to content
        add_filter('the_content', array($this, 'add_faq_to_content'), 998);

        // Output FAQ schema
        add_action('wp_head', array($this, 'output_faq_schema'));

        // Enqueue admin assets for FAQ
        add_action('admin_enqueue_scripts', array($this, 'enqueue_faq_admin_assets'));
    }

    /**
     * Enqueue FAQ admin assets
     */
    public function enqueue_faq_admin_assets($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        // The main admin scripts already handle the FAQ functionality
        // We just need to make sure they're loaded
    }

    /**
     * Add FAQ meta box
     */
    public function add_faq_meta_box() {
        add_meta_box(
            'map_faq',
            __('FAQ Section', 'multi-author-plugin'),
            array($this, 'render_faq_meta_box'),
            MAP_Settings::get_enabled_post_types(),
            'normal',
            'default'
        );
    }

    /**
     * Render FAQ meta box
     */
    public function render_faq_meta_box($post) {
        wp_nonce_field('map_faq_nonce', 'map_faq_nonce_field');

        $faq_data = get_post_meta($post->ID, '_map_faq', true);
        $faq_enabled = get_post_meta($post->ID, '_map_faq_enabled', true);
        $faq_title = get_post_meta($post->ID, '_map_faq_title', true);

        if (!is_array($faq_data)) {
            $faq_data = array();
        }

        if (empty($faq_title)) {
            $faq_title = __('Frequently Asked Questions', 'multi-author-plugin');
        }

        ?>
        <div class="map-faq-wrapper">
            <p class="description">
                <?php _e('Add a FAQ section to this article. FAQ schema will be automatically added for SEO.', 'multi-author-plugin'); ?>
            </p>

            <!-- Enable FAQ Toggle -->
            <div class="map-faq-enable">
                <label>
                    <input type="checkbox"
                           name="map_faq_enabled"
                           value="1"
                           <?php checked($faq_enabled, '1'); ?> />
                    <strong><?php _e('Enable FAQ section for this article', 'multi-author-plugin'); ?></strong>
                </label>
            </div>

            <div class="map-faq-settings" id="map-faq-settings" style="<?php echo $faq_enabled !== '1' ? 'display: none;' : ''; ?>">
                <!-- FAQ Title -->
                <div class="map-faq-title-field">
                    <label for="map_faq_title">
                        <strong><?php _e('FAQ Section Title', 'multi-author-plugin'); ?></strong>
                    </label>
                    <input type="text"
                           id="map_faq_title"
                           name="map_faq_title"
                           value="<?php echo esc_attr($faq_title); ?>"
                           class="widefat"
                           placeholder="<?php esc_attr_e('Frequently Asked Questions', 'multi-author-plugin'); ?>" />
                </div>

                <!-- FAQ Items -->
                <div class="map-faq-items-header">
                    <h4><?php _e('FAQ Items', 'multi-author-plugin'); ?></h4>
                    <p class="description"><?php _e('Add questions and answers. Each FAQ item will include schema markup.', 'multi-author-plugin'); ?></p>
                </div>

                <div id="map-faq-list" class="map-faq-list">
                    <?php if (!empty($faq_data)) : ?>
                        <?php foreach ($faq_data as $index => $faq) : ?>
                            <?php $this->render_faq_item($index, $faq); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="button" id="map-add-faq">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                    <?php _e('Add FAQ Item', 'multi-author-plugin'); ?>
                </button>
            </div>
        </div>

        <!-- FAQ Item Template -->
        <script type="text/template" id="map-faq-template">
            <div class="map-faq-item" data-index="{{INDEX}}">
                <div class="map-faq-item-header">
                    <span class="map-faq-drag-handle">&#8942;&#8942;</span>
                    <span class="map-faq-number">{{NUMBER}}.</span>
                    <button type="button" class="button-link map-faq-toggle" aria-label="<?php esc_attr_e('Toggle', 'multi-author-plugin'); ?>">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                    <button type="button" class="button-link map-remove-faq" aria-label="<?php esc_attr_e('Remove', 'multi-author-plugin'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
                <div class="map-faq-item-content">
                    <div class="map-faq-field">
                        <label><?php _e('Question', 'multi-author-plugin'); ?></label>
                        <input type="text"
                               name="map_faq[{{INDEX}}][question]"
                               class="map-faq-question widefat"
                               value=""
                               placeholder="<?php esc_attr_e('Enter the question...', 'multi-author-plugin'); ?>" />
                    </div>
                    <div class="map-faq-field">
                        <label><?php _e('Answer', 'multi-author-plugin'); ?></label>
                        <textarea name="map_faq[{{INDEX}}][answer]"
                                  class="map-faq-answer widefat"
                                  rows="4"
                                  placeholder="<?php esc_attr_e('Enter the answer...', 'multi-author-plugin'); ?>"></textarea>
                        <p class="description"><?php _e('You can use basic HTML tags for formatting.', 'multi-author-plugin'); ?></p>
                    </div>
                </div>
            </div>
        </script>
        <?php
    }

    /**
     * Render a single FAQ item
     */
    private function render_faq_item($index, $faq) {
        $question = isset($faq['question']) ? $faq['question'] : '';
        $answer = isset($faq['answer']) ? $faq['answer'] : '';
        ?>
        <div class="map-faq-item" data-index="<?php echo esc_attr($index); ?>">
            <div class="map-faq-item-header">
                <span class="map-faq-drag-handle">&#8942;&#8942;</span>
                <span class="map-faq-number"><?php echo esc_html($index + 1); ?>.</span>
                <span class="map-faq-preview"><?php echo esc_html(wp_trim_words($question, 8, '...')); ?></span>
                <button type="button" class="button-link map-faq-toggle" aria-label="<?php esc_attr_e('Toggle', 'multi-author-plugin'); ?>">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <button type="button" class="button-link map-remove-faq" aria-label="<?php esc_attr_e('Remove', 'multi-author-plugin'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <div class="map-faq-item-content" style="display: none;">
                <div class="map-faq-field">
                    <label><?php _e('Question', 'multi-author-plugin'); ?></label>
                    <input type="text"
                           name="map_faq[<?php echo esc_attr($index); ?>][question]"
                           class="map-faq-question widefat"
                           value="<?php echo esc_attr($question); ?>"
                           placeholder="<?php esc_attr_e('Enter the question...', 'multi-author-plugin'); ?>" />
                </div>
                <div class="map-faq-field">
                    <label><?php _e('Answer', 'multi-author-plugin'); ?></label>
                    <textarea name="map_faq[<?php echo esc_attr($index); ?>][answer]"
                              class="map-faq-answer widefat"
                              rows="4"
                              placeholder="<?php esc_attr_e('Enter the answer...', 'multi-author-plugin'); ?>"><?php echo esc_textarea($answer); ?></textarea>
                    <p class="description"><?php _e('You can use basic HTML tags for formatting.', 'multi-author-plugin'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save FAQ data
     */
    public function save_faq_data($post_id, $post) {
        // Check nonce
        if (!isset($_POST['map_faq_nonce_field']) ||
            !wp_verify_nonce($_POST['map_faq_nonce_field'], 'map_faq_nonce')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save enabled state
        $faq_enabled = isset($_POST['map_faq_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_map_faq_enabled', $faq_enabled);

        // Save title
        $faq_title = isset($_POST['map_faq_title']) ? sanitize_text_field(wp_unslash($_POST['map_faq_title'])) : '';
        update_post_meta($post_id, '_map_faq_title', $faq_title);

        // Save FAQ items
        $faq_data = array();
        if (isset($_POST['map_faq']) && is_array($_POST['map_faq'])) {
            foreach (wp_unslash($_POST['map_faq']) as $index => $faq) {
                if (!is_array($faq)) {
                    continue;
                }
                $question = isset($faq['question']) ? sanitize_text_field($faq['question']) : '';
                $answer = isset($faq['answer']) ? wp_kses_post($faq['answer']) : '';

                // Only save if question is not empty
                if (!empty($question)) {
                    $faq_data[] = array(
                        'question' => $question,
                        'answer' => $answer
                    );
                }
            }
        }

        update_post_meta($post_id, '_map_faq', $faq_data);
    }

    /**
     * Add FAQ section to content
     */
    public function add_faq_to_content($content) {
        if (!is_singular(MAP_Settings::get_enabled_post_types()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        global $post;

        if ('manual' === MAP_Settings::get_setting('faq_placement', 'auto')) {
            return $content;
        }

        $faq_enabled = get_post_meta($post->ID, '_map_faq_enabled', true);

        if ($faq_enabled !== '1') {
            return $content;
        }

        $faq_html = $this->render_faq_section($post->ID);

        if ($faq_html) {
            $content .= $faq_html;
        }

        return $content;
    }

    /**
     * Render FAQ section
     */
    public function render_faq_section($post_id) {
        $faq_data = get_post_meta($post_id, '_map_faq', true);
        $faq_title = get_post_meta($post_id, '_map_faq_title', true);

        if (empty($faq_data) || !is_array($faq_data)) {
            return '';
        }

        if (empty($faq_title)) {
            $faq_title = __('Frequently Asked Questions', 'multi-author-plugin');
        }

        $template = MAP_Frontend_Display::locate_template('faq-section.php');
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }

    /**
     * Output FAQ schema in head
     */
    public function output_faq_schema() {
        if (!is_singular(MAP_Settings::get_enabled_post_types())) {
            return;
        }

        global $post;

        // Never expose FAQ content of password-protected posts in the head
        if (post_password_required($post)) {
            return;
        }

        $faq_enabled = get_post_meta($post->ID, '_map_faq_enabled', true);

        if ($faq_enabled !== '1') {
            return;
        }

        $faq_data = get_post_meta($post->ID, '_map_faq', true);

        if (empty($faq_data) || !is_array($faq_data)) {
            return;
        }

        $schema = $this->generate_faq_schema($faq_data);

        if ($schema) {
            // Default slash escaping (\/) keeps a stored "</script>" from
            // terminating this script block.
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($schema, JSON_UNESCAPED_UNICODE);
            echo "\n" . '</script>' . "\n";
        }
    }

    /**
     * Generate FAQ schema
     */
    private function generate_faq_schema($faq_data) {
        $main_entity = array();

        foreach ($faq_data as $faq) {
            // Skip items missing a question OR an answer — the template hides
            // them, and schema for invisible content violates Google policy.
            if (empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }

            $main_entity[] = array(
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => wp_strip_all_tags($faq['answer'])
                )
            );
        }

        if (empty($main_entity)) {
            return null;
        }

        return array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $main_entity
        );
    }

    /**
     * Get FAQ data for a post
     */
    public static function get_faq_data($post_id = null) {
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return array('enabled' => false, 'title' => '', 'items' => array());
        }

        return array(
            'enabled' => get_post_meta($post_id, '_map_faq_enabled', true) === '1',
            'title' => get_post_meta($post_id, '_map_faq_title', true),
            'items' => get_post_meta($post_id, '_map_faq', true) ?: array()
        );
    }

    /**
     * Check if post has FAQ
     */
    public static function has_faq($post_id = null) {
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return false;
        }

        $enabled = get_post_meta($post_id, '_map_faq_enabled', true);
        $faq_data = get_post_meta($post_id, '_map_faq', true);

        return $enabled === '1' && !empty($faq_data);
    }
}
