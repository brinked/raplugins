<?php
/**
 * Bulk Edit & List Table Class
 * Contributors column in post list tables and bulk contributor assignment
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_Bulk_Edit {

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
        foreach (MAP_Settings::get_enabled_post_types() as $post_type) {
            add_filter('manage_' . $post_type . '_posts_columns', array($this, 'add_contributors_column'));
            add_action('manage_' . $post_type . '_posts_custom_column', array($this, 'render_contributors_column'), 10, 2);
        }

        // Bulk edit fields (rendered once, attached to our custom column)
        add_action('bulk_edit_custom_box', array($this, 'render_bulk_edit_fields'), 10, 2);

        // Bulk edit save (WP 6.3+; on older versions the fields simply do nothing)
        add_action('bulk_edit_posts', array($this, 'save_bulk_edit'), 10, 2);
    }

    /**
     * Add contributors column after the author column
     */
    public function add_contributors_column($columns) {
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'author') {
                $new_columns['map_contributors'] = __('Contributors', 'multi-author-plugin');
            }
        }

        // Fallback if the author column is not present
        if (!isset($new_columns['map_contributors'])) {
            $new_columns['map_contributors'] = __('Contributors', 'multi-author-plugin');
        }

        return $new_columns;
    }

    /**
     * Render mini avatars per role in the contributors column
     */
    public function render_contributors_column($column, $post_id) {
        if ($column !== 'map_contributors') {
            return;
        }

        $contributors = get_post_meta($post_id, '_article_contributors', true);
        if (!is_array($contributors)) {
            echo '<span aria-hidden="true">&#8212;</span>';
            return;
        }

        $role_labels = array(
            'authors' => __('Co-Author', 'multi-author-plugin'),
            'reviewers' => __('Reviewer', 'multi-author-plugin'),
            'fact_checkers' => __('Fact Checker', 'multi-author-plugin')
        );

        $shown = 0;
        $output = '';

        foreach ($role_labels as $role => $label) {
            if (empty($contributors[$role]) || !is_array($contributors[$role])) {
                continue;
            }
            foreach ($contributors[$role] as $user_id) {
                if ($shown >= 6) {
                    break 2;
                }
                $user = get_userdata($user_id);
                if (!$user) {
                    continue;
                }
                $output .= sprintf(
                    '<img src="%s" alt="%s" title="%s" width="24" height="24" style="border-radius: 50%%; margin-right: 2px; vertical-align: middle;" />',
                    esc_url(get_avatar_url($user_id, array('size' => 48))),
                    esc_attr($user->display_name),
                    esc_attr($user->display_name . ' — ' . $label)
                );
                $shown++;
            }
        }

        echo $output !== '' ? $output : '<span aria-hidden="true">&#8212;</span>';
    }

    /**
     * Render bulk edit fields (only for our column)
     */
    public function render_bulk_edit_fields($column_name, $post_type) {
        if ($column_name !== 'map_contributors') {
            return;
        }

        if (!in_array($post_type, MAP_Settings::get_enabled_post_types(), true)) {
            return;
        }

        $fields = array(
            'map_bulk_add_author' => __('Add Co-Author', 'multi-author-plugin'),
            'map_bulk_add_reviewer' => __('Add Reviewer', 'multi-author-plugin'),
            'map_bulk_add_fact_checker' => __('Add Fact Checker', 'multi-author-plugin')
        );

        wp_nonce_field('map_bulk_edit', 'map_bulk_edit_nonce');
        ?>
        <fieldset class="inline-edit-col-right map-bulk-edit">
            <div class="inline-edit-col">
                <h4><?php _e('Contributors', 'multi-author-plugin'); ?></h4>
                <?php foreach ($fields as $name => $label) : ?>
                    <label class="inline-edit-group">
                        <span class="title"><?php echo esc_html($label); ?></span>
                        <?php
                        wp_dropdown_users(array(
                            'name' => $name,
                            'show_option_none' => __('&mdash; No Change &mdash;', 'multi-author-plugin'),
                            'option_none_value' => 0,
                            'capability' => 'edit_posts',
                        ));
                        ?>
                    </label>
                <?php endforeach; ?>
                <p class="description"><?php _e('Selected contributors are added to all chosen posts (existing contributors are kept).', 'multi-author-plugin'); ?></p>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Apply bulk contributor additions (bulk_edit_posts action, WP 6.3+)
     */
    public function save_bulk_edit($updated_post_ids, $shared_post_data) {
        if (!isset($shared_post_data['map_bulk_edit_nonce']) ||
            !wp_verify_nonce($shared_post_data['map_bulk_edit_nonce'], 'map_bulk_edit')) {
            return;
        }

        $additions = array(
            'authors' => isset($shared_post_data['map_bulk_add_author']) ? intval($shared_post_data['map_bulk_add_author']) : 0,
            'reviewers' => isset($shared_post_data['map_bulk_add_reviewer']) ? intval($shared_post_data['map_bulk_add_reviewer']) : 0,
            'fact_checkers' => isset($shared_post_data['map_bulk_add_fact_checker']) ? intval($shared_post_data['map_bulk_add_fact_checker']) : 0
        );

        // Nothing selected
        $additions = array_filter($additions);
        if (empty($additions)) {
            return;
        }

        // Only real users
        foreach ($additions as $role => $user_id) {
            if (!get_userdata($user_id)) {
                unset($additions[$role]);
            }
        }
        if (empty($additions)) {
            return;
        }

        $enabled_types = MAP_Settings::get_enabled_post_types();

        foreach ($updated_post_ids as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, $enabled_types, true)) {
                continue;
            }

            $contributors = get_post_meta($post_id, '_article_contributors', true);
            if (!is_array($contributors)) {
                $contributors = array();
            }
            $contributors = wp_parse_args($contributors, array(
                'authors' => array(),
                'reviewers' => array(),
                'fact_checkers' => array()
            ));

            $changed = false;
            foreach ($additions as $role => $user_id) {
                $existing = array_map('intval', (array) $contributors[$role]);
                if (!in_array($user_id, $existing, true)) {
                    $existing[] = $user_id;
                    $contributors[$role] = $existing;
                    $changed = true;
                }
            }

            if ($changed) {
                update_post_meta($post_id, '_article_contributors', $contributors);
            }
        }
    }
}
