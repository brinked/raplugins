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
        
        // Styling Section
        add_settings_section(
            'map_styling',
            __('Styling Options', 'multi-author-plugin'),
            array($this, 'render_styling_section'),
            'multi-author-settings'
        );
        
        add_settings_field(
            'avatar_size',
            __('Avatar/Thumbnail Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'avatar_size', 'default' => 60, 'min' => 30, 'max' => 150)
        );
        
        add_settings_field(
            'name_font_size',
            __('Name Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'name_font_size', 'default' => 16, 'min' => 10, 'max' => 30)
        );
        
        add_settings_field(
            'title_font_size',
            __('Job Title Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'title_font_size', 'default' => 14, 'min' => 10, 'max' => 24)
        );
        
        add_settings_field(
            'role_label_font_size',
            __('Role Label Font Size (px)', 'multi-author-plugin'),
            array($this, 'render_number_field'),
            'multi-author-settings',
            'map_styling',
            array('field' => 'role_label_font_size', 'default' => 12, 'min' => 10, 'max' => 20)
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
    }
    
    /**
     * Render role labels section description
     */
    public function render_role_labels_section() {
        echo '<p>' . __('Customize the text labels for different contributor roles.', 'multi-author-plugin') . '</p>';
    }
    
    /**
     * Render styling section description
     */
    public function render_styling_section() {
        echo '<p>' . __('Adjust the visual appearance of contributor badges on the frontend.', 'multi-author-plugin') . '</p>';
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
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Sanitize text fields
        $text_fields = array('label_written_by', 'label_coauthor', 'label_reviewed_by', 'label_fact_checked_by');
        foreach ($text_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_text_field($input[$field]);
            }
        }
        
        // Sanitize number fields
        $number_fields = array('avatar_size', 'name_font_size', 'title_font_size', 'role_label_font_size');
        foreach ($number_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = absint($input[$field]);
            }
        }
        
        // Sanitize color fields
        $color_fields = array('name_color', 'title_color', 'role_label_color');
        foreach ($color_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_hex_color($input[$field]);
            }
        }
        
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
            
            <div class="map-settings-preview">
                <h2><?php _e('Preview', 'multi-author-plugin'); ?></h2>
                <p><?php _e('Save your settings to see the changes on the frontend.', 'multi-author-plugin'); ?></p>
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
        if (!is_singular('post')) {
            return;
        }
        
        $settings = $this->get_settings();
        
        ?>
        <style type="text/css">
            .map-contributor-avatar {
                width: <?php echo intval($settings['avatar_size']); ?>px !important;
                height: <?php echo intval($settings['avatar_size']); ?>px !important;
            }
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
        </style>
        <?php
    }
    
    /**
     * Get settings with defaults
     */
    public function get_settings() {
        $defaults = array(
            'label_written_by' => 'Written by',
            'label_coauthor' => 'Co-Author',
            'label_reviewed_by' => 'Reviewed by',
            'label_fact_checked_by' => 'Fact-Checked by',
            'avatar_size' => 60,
            'name_font_size' => 16,
            'title_font_size' => 14,
            'role_label_font_size' => 12,
            'name_color' => '#000000',
            'title_color' => '#666666',
            'role_label_color' => '#999999'
        );
        
        $settings = get_option($this->option_name, array());
        
        return wp_parse_args($settings, $defaults);
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