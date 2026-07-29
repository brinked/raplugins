<?php
/**
 * Article Health Dashboard Class
 * Tracks word count, citations, and article freshness
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_Article_Health {

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
        // Add admin menu
        add_action('admin_menu', array($this, 'add_dashboard_menu'));

        // Add custom columns to the posts list (scoped to the 'post' type
        // so CPT list tables are untouched)
        add_filter('manage_post_posts_columns', array($this, 'add_health_columns'));
        add_action('manage_post_posts_custom_column', array($this, 'render_health_columns'), 10, 2);
        add_filter('manage_edit-post_sortable_columns', array($this, 'make_columns_sortable'));
        add_action('pre_get_posts', array($this, 'handle_column_sorting'));

        // Save word count on post save
        add_action('save_post', array($this, 'save_word_count'), 20, 2);

        // Add health indicators to post row
        add_filter('post_row_actions', array($this, 'add_health_indicators'), 10, 2);

        // AJAX handlers
        add_action('wp_ajax_map_get_article_health', array($this, 'ajax_get_article_health'));
        add_action('wp_ajax_map_recalculate_health', array($this, 'ajax_recalculate_health'));

        // Admin notices for posts needing attention
        add_action('admin_notices', array($this, 'show_health_notices'));

        // Enqueue dashboard scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_assets'));
    }

    /**
     * Add dashboard menu
     */
    public function add_dashboard_menu() {
        add_menu_page(
            __('Article Health', 'multi-author-plugin'),
            __('Article Health', 'multi-author-plugin'),
            'edit_posts',
            'map-article-health',
            array($this, 'render_dashboard'),
            'dashicons-heart',
            26
        );
    }

    /**
     * Enqueue dashboard assets
     */
    public function enqueue_dashboard_assets($hook) {
        // Load on our dashboard page and posts list
        if ($hook !== 'toplevel_page_map-article-health' && $hook !== 'edit.php') {
            return;
        }

        wp_enqueue_style(
            'map-article-health-styles',
            MAP_PLUGIN_URL . 'admin/css/article-health-styles.css',
            array(),
            MAP_VERSION
        );

        wp_enqueue_script(
            'map-article-health-scripts',
            MAP_PLUGIN_URL . 'admin/js/article-health-scripts.js',
            array('jquery', 'jquery-ui-tooltip'),
            MAP_VERSION,
            true
        );

        wp_localize_script('map-article-health-scripts', 'mapArticleHealth', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('map_article_health_nonce')
        ));
    }

    /**
     * Add health columns to posts list
     */
    public function add_health_columns($columns) {
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            // Insert after title
            if ($key === 'title') {
                $new_columns['map_word_count'] = '<span class="dashicons dashicons-editor-paste-word" title="' . esc_attr__('Word Count', 'multi-author-plugin') . '"></span>';
                $new_columns['map_citations'] = '<span class="dashicons dashicons-admin-links" title="' . esc_attr__('Citations', 'multi-author-plugin') . '"></span>';
                $new_columns['map_freshness'] = '<span class="dashicons dashicons-calendar-alt" title="' . esc_attr__('Days Since Update', 'multi-author-plugin') . '"></span>';
            }
        }

        return $new_columns;
    }

    /**
     * Render health columns
     */
    public function render_health_columns($column, $post_id) {
        switch ($column) {
            case 'map_word_count':
                $word_count = $this->get_word_count($post_id);
                $class = $word_count < 500 ? 'map-health-warning' : 'map-health-good';
                echo '<span class="' . esc_attr($class) . '" title="' . esc_attr(sprintf(__('%d words', 'multi-author-plugin'), $word_count)) . '">';
                echo esc_html($word_count);
                if ($word_count < 500) {
                    echo ' <span class="dashicons dashicons-warning" style="color: #d63638; font-size: 14px;"></span>';
                }
                echo '</span>';
                break;

            case 'map_citations':
                $citations = $this->get_citation_count($post_id);
                $class = $citations === 0 ? 'map-health-warning' : 'map-health-good';
                echo '<span class="' . esc_attr($class) . '" title="' . esc_attr(sprintf(__('%d citations', 'multi-author-plugin'), $citations)) . '">';
                echo esc_html($citations);
                if ($citations === 0) {
                    echo ' <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 14px;"></span>';
                }
                echo '</span>';
                break;

            case 'map_freshness':
                $days = $this->get_days_since_update($post_id);
                $class = 'map-health-good';
                if ($days > 365) {
                    $class = 'map-health-critical';
                } elseif ($days > 180) {
                    $class = 'map-health-warning';
                }
                echo '<span class="' . esc_attr($class) . '" title="' . esc_attr(sprintf(__('%d days since last update', 'multi-author-plugin'), $days)) . '">';
                echo esc_html($days) . 'd';
                if ($days > 365) {
                    echo ' <span class="dashicons dashicons-warning" style="color: #d63638; font-size: 14px;"></span>';
                } elseif ($days > 180) {
                    echo ' <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 14px;"></span>';
                }
                echo '</span>';
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function make_columns_sortable($columns) {
        $columns['map_word_count'] = 'map_word_count';
        $columns['map_citations'] = 'map_citations';
        $columns['map_freshness'] = 'map_freshness';
        return $columns;
    }

    /**
     * Handle column sorting
     */
    public function handle_column_sorting($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        // Only for the 'post' list table
        $post_type = $query->get('post_type');
        if ($post_type && 'post' !== $post_type) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'map_word_count':
                $query->set('meta_query', $this->sortable_meta_clauses('_map_word_count'));
                $query->set('orderby', 'map_metric');
                break;

            case 'map_citations':
                $query->set('meta_query', $this->sortable_meta_clauses('_map_citation_count'));
                $query->set('orderby', 'map_metric');
                break;

            case 'map_freshness':
                $query->set('orderby', 'modified');
                break;
        }
    }

    /**
     * Meta query that keeps posts WITHOUT the cached meta in the result set
     * (a plain meta_key sort INNER JOINs and silently drops them)
     */
    private function sortable_meta_clauses($meta_key) {
        return array(
            'relation' => 'OR',
            'map_metric' => array(
                'key' => $meta_key,
                'compare' => 'EXISTS',
                'type' => 'NUMERIC'
            ),
            'map_metric_missing' => array(
                'key' => $meta_key,
                'compare' => 'NOT EXISTS'
            )
        );
    }

    /**
     * Count words in post content (multibyte-safe, unlike str_word_count,
     * and with shortcodes stripped so they don't inflate the count)
     */
    public static function calculate_word_count($content) {
        $plain = wp_strip_all_tags(strip_shortcodes((string) $content));
        return (int) preg_match_all('/\S+/u', $plain, $matches);
    }

    /**
     * Save word count on post save
     */
    public function save_word_count($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post->post_type !== 'post') {
            return;
        }

        // Calculate and save word count
        update_post_meta($post_id, '_map_word_count', self::calculate_word_count($post->post_content));

        // Calculate and save citation count
        $sources = get_post_meta($post_id, '_article_sources', true);
        $citation_count = is_array($sources) ? count($sources) : 0;
        update_post_meta($post_id, '_map_citation_count', $citation_count);

        // Stats are stale now
        delete_transient('map_health_stats');
    }

    /**
     * Get word count for a post
     */
    public function get_word_count($post_id) {
        $word_count = get_post_meta($post_id, '_map_word_count', true);

        if ($word_count === '' || $word_count === false) {
            // Calculate if not cached
            $post = get_post($post_id);
            if ($post) {
                $word_count = self::calculate_word_count($post->post_content);
                update_post_meta($post_id, '_map_word_count', $word_count);
            } else {
                $word_count = 0;
            }
        }

        return intval($word_count);
    }

    /**
     * Get citation count for a post
     */
    public function get_citation_count($post_id) {
        $sources = get_post_meta($post_id, '_article_sources', true);
        return is_array($sources) ? count($sources) : 0;
    }

    /**
     * Get days since last update
     */
    public function get_days_since_update($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $modified = strtotime($post->post_modified);
        $now = current_time('timestamp');
        $diff = $now - $modified;

        return floor($diff / DAY_IN_SECONDS);
    }

    /**
     * Add health indicators to post row actions
     */
    public function add_health_indicators($actions, $post) {
        if ($post->post_type !== 'post') {
            return $actions;
        }

        $issues = array();

        $word_count = $this->get_word_count($post->ID);
        if ($word_count < 500) {
            $issues[] = sprintf(__('Low word count (%d)', 'multi-author-plugin'), $word_count);
        }

        $citations = $this->get_citation_count($post->ID);
        if ($citations === 0) {
            $issues[] = __('No citations', 'multi-author-plugin');
        }

        $days = $this->get_days_since_update($post->ID);
        if ($days > 365) {
            $issues[] = sprintf(__('Not updated in %d days', 'multi-author-plugin'), $days);
        }

        if (!empty($issues)) {
            $actions['map_health'] = '<span style="color: #d63638;">' . esc_html(implode(' | ', $issues)) . '</span>';
        }

        return $actions;
    }

    /**
     * Show health notices on edit screen
     */
    public function show_health_notices() {
        global $pagenow, $post;

        if ($pagenow !== 'post.php' || !$post || $post->post_type !== 'post') {
            return;
        }

        $issues = array();

        $word_count = $this->get_word_count($post->ID);
        if ($word_count < 500) {
            $issues[] = sprintf(
                __('This article has only %d words. Consider adding more content for better SEO.', 'multi-author-plugin'),
                $word_count
            );
        }

        $citations = $this->get_citation_count($post->ID);
        if ($citations === 0) {
            $issues[] = __('This article has no citations. Consider adding sources to improve credibility.', 'multi-author-plugin');
        }

        $days = $this->get_days_since_update($post->ID);
        if ($days > 365) {
            $issues[] = sprintf(
                __('This article hasn\'t been updated in %d days. Consider reviewing and refreshing the content.', 'multi-author-plugin'),
                $days
            );
        }

        if (!empty($issues)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . esc_html__('Article Health Issues:', 'multi-author-plugin') . '</strong></p>';
            echo '<ul style="list-style: disc; margin-left: 20px;">';
            foreach ($issues as $issue) {
                echo '<li>' . esc_html($issue) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard() {
        // Get filter parameters
        $filter = isset($_GET['filter']) ? sanitize_text_field(wp_unslash($_GET['filter'])) : 'all';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'modified';
        $order = (isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC') ? 'DESC' : 'ASC';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;

        // Validate per_page
        $allowed_per_page = array(20, 50, 100, -1);
        if (!in_array($per_page, $allowed_per_page)) {
            $per_page = 20;
        }

        // For no_citations filter, we need a different approach since meta_query doesn't work well
        // with NOT EXISTS combined with other queries
        if ($filter === 'no_citations') {
            $query = $this->get_no_citations_query($orderby, $order, $paged, $per_page);
        } else {
            // Build query args
            $args = array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $paged,
            );

            // Apply filters
            switch ($filter) {
                case 'low_words':
                    $args['meta_query'] = array(
                        array(
                            'key' => '_map_word_count',
                            'value' => 500,
                            'compare' => '<',
                            'type' => 'NUMERIC'
                        )
                    );
                    break;

                case 'stale':
                    $one_year_ago = date('Y-m-d', strtotime('-1 year'));
                    $args['date_query'] = array(
                        array(
                            'column' => 'post_modified',
                            'before' => $one_year_ago
                        )
                    );
                    break;

                case 'needs_attention':
                    // Complex query for any issues
                    break;
            }

            // Apply ordering (EXISTS/NOT EXISTS keeps posts without cached
            // meta in the results instead of silently dropping them)
            switch ($orderby) {
                case 'word_count':
                    $args['meta_query'] = isset($args['meta_query'])
                        ? array('relation' => 'AND', $args['meta_query'], $this->sortable_meta_clauses('_map_word_count'))
                        : $this->sortable_meta_clauses('_map_word_count');
                    $args['orderby'] = 'map_metric';
                    $args['order'] = $order;
                    break;

                case 'citations':
                    $args['meta_query'] = isset($args['meta_query'])
                        ? array('relation' => 'AND', $args['meta_query'], $this->sortable_meta_clauses('_map_citation_count'))
                        : $this->sortable_meta_clauses('_map_citation_count');
                    $args['orderby'] = 'map_metric';
                    $args['order'] = $order;
                    break;

                case 'modified':
                default:
                    $args['orderby'] = 'modified';
                    $args['order'] = $order;
                    break;
            }

            $query = new WP_Query($args);
        }

        // Get summary stats
        $stats = $this->get_health_stats();

        ?>
        <div class="wrap map-article-health-dashboard">
            <h1>
                <?php _e('Article Health Dashboard', 'multi-author-plugin'); ?>
                <button type="button" class="button" id="map-recalculate-health" style="margin-left: 15px; vertical-align: middle;">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e('Recalculate All', 'multi-author-plugin'); ?>
                </button>
                <span id="map-recalculate-status" style="margin-left: 10px; display: none;"></span>
            </h1>

            <!-- Summary Cards -->
            <div class="map-health-summary">
                <div class="map-health-card map-health-card-total">
                    <span class="map-health-card-number"><?php echo esc_html($stats['total']); ?></span>
                    <span class="map-health-card-label"><?php _e('Total Articles', 'multi-author-plugin'); ?></span>
                </div>

                <div class="map-health-card map-health-card-warning">
                    <span class="map-health-card-number"><?php echo esc_html($stats['low_words']); ?></span>
                    <span class="map-health-card-label"><?php _e('Low Word Count', 'multi-author-plugin'); ?></span>
                    <span class="map-health-card-desc"><?php _e('Under 500 words', 'multi-author-plugin'); ?></span>
                </div>

                <div class="map-health-card map-health-card-warning">
                    <span class="map-health-card-number"><?php echo esc_html($stats['no_citations']); ?></span>
                    <span class="map-health-card-label"><?php _e('No Citations', 'multi-author-plugin'); ?></span>
                    <span class="map-health-card-desc"><?php _e('Missing sources', 'multi-author-plugin'); ?></span>
                </div>

                <div class="map-health-card map-health-card-critical">
                    <span class="map-health-card-number"><?php echo esc_html($stats['stale']); ?></span>
                    <span class="map-health-card-label"><?php _e('Stale Articles', 'multi-author-plugin'); ?></span>
                    <span class="map-health-card-desc"><?php _e('Not updated in 1+ year', 'multi-author-plugin'); ?></span>
                </div>
            </div>

            <!-- Filters -->
            <div class="map-health-filters">
                <a href="<?php echo esc_url(add_query_arg('filter', 'all', remove_query_arg(array('paged')))); ?>"
                   class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>">
                    <?php _e('All Articles', 'multi-author-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('filter', 'low_words', remove_query_arg(array('paged')))); ?>"
                   class="button <?php echo $filter === 'low_words' ? 'button-primary' : ''; ?>">
                    <?php _e('Low Word Count', 'multi-author-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('filter', 'no_citations', remove_query_arg(array('paged')))); ?>"
                   class="button <?php echo $filter === 'no_citations' ? 'button-primary' : ''; ?>">
                    <?php _e('No Citations', 'multi-author-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('filter' => 'stale', 'orderby' => 'modified', 'order' => 'ASC'), remove_query_arg(array('paged')))); ?>"
                   class="button <?php echo $filter === 'stale' ? 'button-primary' : ''; ?>">
                    <?php _e('Stale (Oldest First)', 'multi-author-plugin'); ?>
                </a>
            </div>

            <!-- Sort Options -->
            <div class="map-health-sort">
                <span><?php _e('Sort by:', 'multi-author-plugin'); ?></span>
                <a href="<?php echo esc_url(add_query_arg(array('orderby' => 'word_count', 'order' => 'ASC'))); ?>"
                   class="<?php echo $orderby === 'word_count' ? 'active' : ''; ?>">
                    <?php _e('Word Count', 'multi-author-plugin'); ?>
                    <?php if ($orderby === 'word_count') echo $order === 'ASC' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('orderby' => 'citations', 'order' => 'ASC'))); ?>"
                   class="<?php echo $orderby === 'citations' ? 'active' : ''; ?>">
                    <?php _e('Citations', 'multi-author-plugin'); ?>
                    <?php if ($orderby === 'citations') echo $order === 'ASC' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('orderby' => 'modified', 'order' => 'ASC'))); ?>"
                   class="<?php echo $orderby === 'modified' ? 'active' : ''; ?>">
                    <?php _e('Last Updated', 'multi-author-plugin'); ?>
                    <?php if ($orderby === 'modified') echo $order === 'ASC' ? '↑' : '↓'; ?>
                </a>

                <?php if ($orderby) : ?>
                    <a href="<?php echo esc_url(add_query_arg('order', $order === 'ASC' ? 'DESC' : 'ASC')); ?>"
                       class="button button-small">
                        <?php echo $order === 'ASC' ? __('↓ Descending', 'multi-author-plugin') : __('↑ Ascending', 'multi-author-plugin'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Articles Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-title"><?php _e('Article', 'multi-author-plugin'); ?></th>
                        <th class="column-words"><?php _e('Words', 'multi-author-plugin'); ?></th>
                        <th class="column-citations"><?php _e('Citations', 'multi-author-plugin'); ?></th>
                        <th class="column-updated"><?php _e('Last Updated', 'multi-author-plugin'); ?></th>
                        <th class="column-status"><?php _e('Health', 'multi-author-plugin'); ?></th>
                        <th class="column-actions"><?php _e('Actions', 'multi-author-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($query->have_posts()) : ?>
                        <?php while ($query->have_posts()) : $query->the_post();
                            $post_id = get_the_ID();
                            $word_count = $this->get_word_count($post_id);
                            $citations = $this->get_citation_count($post_id);
                            $days = $this->get_days_since_update($post_id);

                            $health_issues = array();
                            if ($word_count < 500) $health_issues[] = 'low-words';
                            if ($citations === 0) $health_issues[] = 'no-citations';
                            if ($days > 365) $health_issues[] = 'stale';
                        ?>
                        <tr class="<?php echo !empty($health_issues) ? 'map-needs-attention' : ''; ?>">
                            <td class="column-title">
                                <strong>
                                    <a href="<?php echo esc_url(get_edit_post_link()); ?>"><?php the_title(); ?></a>
                                </strong>
                            </td>
                            <td class="column-words <?php echo $word_count < 500 ? 'map-health-warning' : ''; ?>">
                                <?php echo esc_html($word_count); ?>
                                <?php if ($word_count < 500) : ?>
                                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-citations <?php echo $citations === 0 ? 'map-health-warning' : ''; ?>">
                                <?php echo esc_html($citations); ?>
                                <?php if ($citations === 0) : ?>
                                    <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-updated <?php echo $days > 365 ? 'map-health-critical' : ($days > 180 ? 'map-health-warning' : ''); ?>">
                                <?php echo esc_html(get_the_modified_date()); ?>
                                <br><small><?php echo esc_html(sprintf(__('%d days ago', 'multi-author-plugin'), $days)); ?></small>
                            </td>
                            <td class="column-status">
                                <?php if (empty($health_issues)) : ?>
                                    <span class="map-health-badge map-health-good">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php _e('Good', 'multi-author-plugin'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="map-health-badge map-health-needs-work">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php _e('Needs Work', 'multi-author-plugin'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="column-actions">
                                <a href="<?php echo esc_url(get_edit_post_link()); ?>" class="button button-small">
                                    <?php _e('Edit', 'multi-author-plugin'); ?>
                                </a>
                                <a href="<?php the_permalink(); ?>" class="button button-small" target="_blank">
                                    <?php _e('View', 'multi-author-plugin'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6"><?php _e('No articles found.', 'multi-author-plugin'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        $total_items = $query->found_posts;
                        printf(
                            _n('%s item', '%s items', $total_items, 'multi-author-plugin'),
                            number_format_i18n($total_items)
                        );
                        ?>
                    </span>

                    <span class="pagination-links">
                        <label for="map-per-page" class="screen-reader-text"><?php _e('Items per page', 'multi-author-plugin'); ?></label>
                        <select id="map-per-page" onchange="window.location.href=this.value;">
                            <?php
                            $per_page_options = array(20, 50, 100, -1);
                            $base_url = remove_query_arg(array('per_page', 'paged'));
                            foreach ($per_page_options as $option) :
                                $label = $option === -1 ? __('All', 'multi-author-plugin') : $option;
                                $url = add_query_arg('per_page', $option, $base_url);
                            ?>
                                <option value="<?php echo esc_url($url); ?>" <?php selected($per_page, $option); ?>>
                                    <?php echo esc_html($label); ?> <?php _e('per page', 'multi-author-plugin'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>

                    <?php if ($query->max_num_pages > 1 && $per_page !== -1) : ?>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $query->max_num_pages,
                            'current' => $paged
                        ));
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php wp_reset_postdata(); ?>
        </div>
        <?php
    }

    /**
     * Get query for articles with no citations
     * Uses a custom approach since meta_query NOT EXISTS doesn't work well
     */
    private function get_no_citations_query($orderby, $order, $paged, $per_page) {
        // Posts with a cached citation count of 0, or no cached count at all
        // (never re-saved since the plugin was activated). One SQL query
        // instead of loading every published post into PHP.
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_map_citation_count',
                    'value' => 0,
                    'compare' => '=',
                    'type' => 'NUMERIC'
                ),
                array(
                    'key' => '_map_citation_count',
                    'compare' => 'NOT EXISTS'
                )
            ),
        );

        // Apply ordering
        switch ($orderby) {
            case 'word_count':
                $args['meta_query'] = array(
                    'relation' => 'AND',
                    $args['meta_query'],
                    $this->sortable_meta_clauses('_map_word_count')
                );
                $args['orderby'] = 'map_metric';
                $args['order'] = $order;
                break;

            case 'citations':
                $args['orderby'] = 'modified';
                $args['order'] = $order;
                break;

            case 'modified':
            default:
                $args['orderby'] = 'modified';
                $args['order'] = $order;
                break;
        }

        return new WP_Query($args);
    }

    /**
     * Get health statistics
     * Calculates stats on-the-fly for accuracy
     */
    public function get_health_stats() {
        // Cached: computing these on every dashboard load was O(posts)
        // queries. Invalidated on post save and on Recalculate All.
        $stats = get_transient('map_health_stats');
        if (is_array($stats)) {
            return $stats;
        }

        global $wpdb;

        // Total published posts
        $total = wp_count_posts('post')->publish;

        // Posts with a cached word count under 500. Posts never re-saved
        // since activation have no cached count and are not included;
        // "Recalculate All" fills the cache.
        $low_words = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = '_map_word_count'
             WHERE p.post_type = 'post' AND p.post_status = 'publish'
               AND CAST(pm.meta_value AS UNSIGNED) < 500"
        );

        // Posts WITH citations (cached count > 0); everything else counts
        // as having none.
        $with_citations = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = '_map_citation_count'
             WHERE p.post_type = 'post' AND p.post_status = 'publish'
               AND CAST(pm.meta_value AS UNSIGNED) > 0"
        );
        $no_citations = max(0, intval($total) - $with_citations);

        // Posts not modified in over a year
        $one_year_ago = gmdate('Y-m-d H:i:s', strtotime('-1 year', current_time('timestamp', true)));
        $stale = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
               AND post_modified_gmt < %s",
            $one_year_ago
        ));

        $stats = array(
            'total' => intval($total),
            'low_words' => $low_words,
            'no_citations' => $no_citations,
            'stale' => $stale
        );

        set_transient('map_health_stats', $stats, 10 * MINUTE_IN_SECONDS);

        return $stats;
    }

    /**
     * AJAX handler for article health data
     */
    public function ajax_get_article_health() {
        check_ajax_referer('map_article_health_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
            return;
        }

        // Don't leak metrics of drafts/private posts the user cannot edit
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Permission denied', 403);
            return;
        }

        $data = array(
            'word_count' => $this->get_word_count($post_id),
            'citations' => $this->get_citation_count($post_id),
            'days_since_update' => $this->get_days_since_update($post_id)
        );

        wp_send_json_success($data);
    }

    /**
     * AJAX handler for recalculating all health metrics
     */
    public function ajax_recalculate_health() {
        check_ajax_referer('map_article_health_nonce', 'nonce');

        // Rewrites meta on every published post — editors and up only
        if (!current_user_can('edit_others_posts')) {
            wp_send_json_error('Permission denied', 403);
            return;
        }

        // Batched: the JS calls this repeatedly with an increasing offset so
        // large sites never hit max_execution_time in a single request
        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;
        $result = self::recalculate_batch($offset, 100);

        $done = ($offset + $result['processed']) >= $result['total'] || $result['processed'] === 0;
        if ($done) {
            delete_transient('map_health_stats');
        }

        wp_send_json_success(array(
            'processed' => $result['processed'],
            'offset' => $offset + $result['processed'],
            'total' => $result['total'],
            'done' => $done,
            'message' => sprintf(__('Recalculated metrics for %d articles.', 'multi-author-plugin'), $offset + $result['processed'])
        ));
    }

    /**
     * Recalculate one batch of posts
     *
     * @return array { processed: int, total: int }
     */
    public static function recalculate_batch($offset, $limit = 100) {
        $query = new WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ));

        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            update_post_meta($post_id, '_map_word_count', self::calculate_word_count($post->post_content));

            $sources = get_post_meta($post_id, '_article_sources', true);
            update_post_meta($post_id, '_map_citation_count', is_array($sources) ? count($sources) : 0);
        }

        return array(
            'processed' => count($query->posts),
            'total' => intval($query->found_posts),
        );
    }

    /**
     * Recalculate all article health metrics in batches.
     * Used by WP-CLI; the admin button uses the batched AJAX flow.
     */
    public static function recalculate_all_metrics() {
        $offset = 0;
        $total_processed = 0;

        do {
            $result = self::recalculate_batch($offset, 200);
            $offset += $result['processed'];
            $total_processed += $result['processed'];
        } while ($result['processed'] > 0 && $offset < $result['total']);

        delete_transient('map_health_stats');

        return $total_processed;
    }
}
