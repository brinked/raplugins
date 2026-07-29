<?php
/**
 * Meta Boxes Class
 * Handles the admin interface for contributors and sources
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_Meta_Boxes {
    
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
        add_meta_box(
            'map_contributors',
            __('Article Contributors', 'multi-author-plugin'),
            array($this, 'render_contributors_meta_box'),
            'post',
            'normal',
            'high'
        );
        
        add_meta_box(
            'map_sources',
            __('Article Sources & Citations', 'multi-author-plugin'),
            array($this, 'render_sources_meta_box'),
            'post',
            'normal',
            'default'
        );
    }
    
    /**
     * Render contributors meta box
     */
    public function render_contributors_meta_box($post) {
        wp_nonce_field('map_contributors_nonce', 'map_contributors_nonce_field');
        
        $contributors = get_post_meta($post->ID, '_article_contributors', true);
        if (!is_array($contributors)) {
            $contributors = array(
                'authors' => array(),
                'reviewers' => array(),
                'fact_checkers' => array()
            );
        }
        
        ?>
        <div class="map-contributors-wrapper">
            <p class="description">
                <?php _e('Add contributors to this article. Users will be displayed in the order shown below.', 'multi-author-plugin'); ?>
            </p>
            
            <!-- Co-Authors Section -->
            <div class="map-contributor-section">
                <h4><?php _e('Co-Authors', 'multi-author-plugin'); ?></h4>
                <p class="description"><?php _e('The post author is automatically the primary author. Add co-authors here if applicable.', 'multi-author-plugin'); ?></p>
                <div class="map-contributor-list" id="map-authors-list" data-type="authors">
                    <?php $this->render_contributor_items($contributors['authors'], 'authors'); ?>
                </div>
                <button type="button" class="button map-add-contributor" data-type="authors">
                    <?php _e('+ Add Co-Author', 'multi-author-plugin'); ?>
                </button>
            </div>
            
            <!-- Reviewers Section -->
            <div class="map-contributor-section">
                <h4><?php _e('Reviewers', 'multi-author-plugin'); ?></h4>
                <div class="map-contributor-list" id="map-reviewers-list" data-type="reviewers">
                    <?php $this->render_contributor_items($contributors['reviewers'], 'reviewers'); ?>
                </div>
                <button type="button" class="button map-add-contributor" data-type="reviewers">
                    <?php _e('+ Add Reviewer', 'multi-author-plugin'); ?>
                </button>
            </div>
            
            <!-- Fact Checkers Section -->
            <div class="map-contributor-section">
                <h4><?php _e('Fact Checkers', 'multi-author-plugin'); ?></h4>
                <div class="map-contributor-list" id="map-fact-checkers-list" data-type="fact_checkers">
                    <?php $this->render_contributor_items($contributors['fact_checkers'], 'fact_checkers'); ?>
                </div>
                <button type="button" class="button map-add-contributor" data-type="fact_checkers">
                    <?php _e('+ Add Fact Checker', 'multi-author-plugin'); ?>
                </button>
            </div>
        </div>
        
        <!-- User Search Modal (hidden by default) -->
        <div id="map-user-search-modal" class="map-modal" style="display: none;">
            <div class="map-modal-content">
                <span class="map-modal-close">&times;</span>
                <h3><?php _e('Search Users', 'multi-author-plugin'); ?></h3>
                <input type="text" id="map-user-search-input" placeholder="<?php _e('Search by name or email...', 'multi-author-plugin'); ?>" />
                <div id="map-user-search-results"></div>
                <button type="button" class="button button-primary" id="map-user-search-select">
                    <?php _e('Add Selected', 'multi-author-plugin'); ?>
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
            <button type="button" class="button-link map-remove-contributor" aria-label="<?php _e('Remove', 'multi-author-plugin'); ?>">
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
                <?php _e('Add source citations for this article. These will be displayed at the bottom of the article.', 'multi-author-plugin'); ?>
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
                <?php _e('+ Add Source', 'multi-author-plugin'); ?>
            </button>
        </div>
        
        <!-- Source template (hidden) -->
        <script type="text/template" id="map-source-template">
            <?php $this->render_source_item('{{INDEX}}', array('url' => '', 'label' => '')); ?>
        </script>
        <?php
    }
    
    /**
     * Render single source item
     */
    private function render_source_item($index, $source) {
        $url = isset($source['url']) ? $source['url'] : '';
        $label = isset($source['label']) ? $source['label'] : '';
        ?>
        <div class="map-source-item">
            <span class="map-source-number"><?php echo is_numeric($index) ? ($index + 1) : ''; ?></span>
            <div class="map-source-fields">
                <input type="url" 
                       name="map_sources[<?php echo esc_attr($index); ?>][url]" 
                       placeholder="<?php _e('https://example.com/source', 'multi-author-plugin'); ?>" 
                       value="<?php echo esc_attr($url); ?>" 
                       class="map-source-url widefat" 
                       required />
                <input type="text" 
                       name="map_sources[<?php echo esc_attr($index); ?>][label]" 
                       placeholder="<?php _e('Source label (optional)', 'multi-author-plugin'); ?>" 
                       value="<?php echo esc_attr($label); ?>" 
                       class="map-source-label widefat" />
            </div>
            <button type="button" class="button-link map-remove-source" aria-label="<?php _e('Remove', 'multi-author-plugin'); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
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
        if ('post' !== $post->post_type) {
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
                    if (in_array($type, array('authors', 'reviewers', 'fact_checkers'))) {
                        $contributors[$type] = array_map('intval', array_filter($user_ids));
                    }
                }
            }
            
            update_post_meta($post_id, '_article_contributors', $contributors);
        }
        
        // Save sources
        if (isset($_POST['map_sources_nonce_field']) && 
            wp_verify_nonce($_POST['map_sources_nonce_field'], 'map_sources_nonce')) {
            
            $sources = array();
            
            if (isset($_POST['map_sources']) && is_array($_POST['map_sources'])) {
                foreach ($_POST['map_sources'] as $source) {
                    if (!empty($source['url'])) {
                        $sources[] = array(
                            'url' => esc_url_raw($source['url']),
                            'label' => sanitize_text_field($source['label'])
                        );
                    }
                }
            }
            
            update_post_meta($post_id, '_article_sources', $sources);
        }
    }
    
    /**
     * AJAX: Search users
     */
    public function ajax_search_users() {
        check_ajax_referer('map_admin_nonce', 'nonce');
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        $args = array(
            'search' => '*' . $search . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number' => 20,
            'orderby' => 'display_name',
            'order' => 'ASC'
        );
        
        $users = get_users($args);
        
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