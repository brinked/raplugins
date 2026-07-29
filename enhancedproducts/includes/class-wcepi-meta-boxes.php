<?php
/**
 * Meta Boxes Class
 *
 * @package WC_Enhanced_Product_Info
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCEPI_Meta_Boxes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));

        // Warranty template AJAX handlers
        add_action('wp_ajax_wcepi_save_warranty_template', array($this, 'ajax_save_warranty_template'));
        add_action('wp_ajax_wcepi_delete_warranty_template', array($this, 'ajax_delete_warranty_template'));

        // Content template AJAX handlers (shipping / returns policies)
        add_action('wp_ajax_wcepi_save_content_template', array($this, 'ajax_save_content_template'));
        add_action('wp_ajax_wcepi_delete_content_template', array($this, 'ajax_delete_content_template'));
    }

    /**
     * Option name for a content template type
     *
     * @param string $type 'shipping' or 'returns'
     * @return string Option name, or '' for unknown types
     */
    private static function content_template_option($type) {
        $map = array(
            'shipping' => 'wcepi_shipping_templates',
            'returns' => 'wcepi_returns_templates',
        );
        return isset($map[$type]) ? $map[$type] : '';
    }

    /**
     * Get saved content templates for a type (shipping / returns)
     *
     * @param string $type
     * @return array Keyed by template id, each with 'name' and 'content'
     */
    public static function get_content_templates($type) {
        $option = self::content_template_option($type);
        if ($option === '') {
            return array();
        }
        $templates = get_option($option, array());
        return is_array($templates) ? $templates : array();
    }

    /**
     * AJAX: create or update a shipping/returns content template
     */
    public function ajax_save_content_template() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcepi_content_templates')) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page and try again.', 'wc-enhanced-product-info')));
        }

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-enhanced-product-info')));
        }

        $type = isset($_POST['template_type']) ? sanitize_key($_POST['template_type']) : '';
        $option = self::content_template_option($type);
        if ($option === '') {
            wp_send_json_error(array('message' => __('Unknown template type.', 'wc-enhanced-product-info')));
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($name === '') {
            wp_send_json_error(array('message' => __('Template name is required.', 'wc-enhanced-product-info')));
        }

        $template_id = sanitize_title($name);
        if ($template_id === '') {
            wp_send_json_error(array('message' => __('Please use a template name with letters or numbers.', 'wc-enhanced-product-info')));
        }

        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        if (trim($content) === '') {
            wp_send_json_error(array('message' => __('The editor is empty — add some content before saving it as a template.', 'wc-enhanced-product-info')));
        }

        $template = array(
            'name' => $name,
            'content' => $content,
        );

        $templates = self::get_content_templates($type);
        $is_update = isset($templates[$template_id]);
        $templates[$template_id] = $template;

        // Keep templates sorted by name for a tidy dropdown
        uasort($templates, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        update_option($option, $templates, false);

        wp_send_json_success(array(
            'id' => $template_id,
            'template' => $template,
            'updated' => $is_update,
            'message' => $is_update
                ? sprintf(__('Template "%s" updated.', 'wc-enhanced-product-info'), $name)
                : sprintf(__('Template "%s" saved.', 'wc-enhanced-product-info'), $name),
        ));
    }

    /**
     * AJAX: delete a shipping/returns content template
     */
    public function ajax_delete_content_template() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcepi_content_templates')) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page and try again.', 'wc-enhanced-product-info')));
        }

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-enhanced-product-info')));
        }

        $type = isset($_POST['template_type']) ? sanitize_key($_POST['template_type']) : '';
        $option = self::content_template_option($type);
        if ($option === '') {
            wp_send_json_error(array('message' => __('Unknown template type.', 'wc-enhanced-product-info')));
        }

        $template_id = isset($_POST['template_id']) ? sanitize_key($_POST['template_id']) : '';
        $templates = self::get_content_templates($type);

        if ($template_id === '' || !isset($templates[$template_id])) {
            wp_send_json_error(array('message' => __('Template not found.', 'wc-enhanced-product-info')));
        }

        $name = $templates[$template_id]['name'];
        unset($templates[$template_id]);
        update_option($option, $templates, false);

        wp_send_json_success(array(
            'id' => $template_id,
            'message' => sprintf(__('Template "%s" deleted.', 'wc-enhanced-product-info'), $name),
        ));
    }

    /**
     * Render a template toolbar for a rich-text policy editor (shipping / returns)
     *
     * @param string $type      Template type ('shipping' or 'returns')
     * @param string $editor_id The wp_editor id the toolbar controls
     */
    private function render_content_template_toolbar($type, $editor_id) {
        $templates = self::get_content_templates($type);
        ?>
        <div class="wcepi-template-toolbar wcepi-content-templates"
             data-template-type="<?php echo esc_attr($type); ?>"
             data-editor-id="<?php echo esc_attr($editor_id); ?>">
            <label><strong><?php _e('Template:', 'wc-enhanced-product-info'); ?></strong></label>
            <select class="wcepi-content-template-select">
                <option value=""><?php _e('&mdash; Select a template &mdash;', 'wc-enhanced-product-info'); ?></option>
                <?php foreach ($templates as $tpl_id => $tpl) : ?>
                    <option value="<?php echo esc_attr($tpl_id); ?>"><?php echo esc_html($tpl['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button wcepi-content-template-apply"><?php _e('Apply', 'wc-enhanced-product-info'); ?></button>
            <button type="button" class="button wcepi-content-template-save"><?php _e('Save Current as Template', 'wc-enhanced-product-info'); ?></button>
            <button type="button" class="button wcepi-content-template-delete"><?php _e('Delete', 'wc-enhanced-product-info'); ?></button>
            <span class="wcepi-template-status" aria-live="polite"></span>
            <p class="description" style="flex-basis: 100%; margin: 4px 0 0;">
                <?php _e('Apply fills the editor below — tweak freely, then update the product. "Save Current as Template" stores the editor content for reuse on other products.', 'wc-enhanced-product-info'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get saved warranty templates
     *
     * @return array Keyed by template id, each with name + warranty field values
     */
    public static function get_warranty_templates() {
        $templates = get_option('wcepi_warranty_templates', array());
        return is_array($templates) ? $templates : array();
    }

    /**
     * Allowed warranty units / schema types (shared by meta save and templates)
     */
    private static function allowed_warranty_units() {
        return array('years', 'months', 'days');
    }

    private static function allowed_warranty_types() {
        return array('', 'FullWarranty', 'LimitedWarranty', 'PartsWarranty', 'LaborWarranty', 'LifetimeWarranty', 'ReplacementWarranty');
    }

    /**
     * AJAX: create or update a warranty template from the product edit page
     */
    public function ajax_save_warranty_template() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcepi_warranty_templates')) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page and try again.', 'wc-enhanced-product-info')));
        }

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-enhanced-product-info')));
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($name === '') {
            wp_send_json_error(array('message' => __('Template name is required.', 'wc-enhanced-product-info')));
        }

        $template_id = sanitize_title($name);
        if ($template_id === '') {
            wp_send_json_error(array('message' => __('Please use a template name with letters or numbers.', 'wc-enhanced-product-info')));
        }

        $unit = isset($_POST['unit']) ? sanitize_text_field($_POST['unit']) : 'years';
        if (!in_array($unit, self::allowed_warranty_units(), true)) {
            $unit = 'years';
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        if (!in_array($type, self::allowed_warranty_types(), true)) {
            $type = '';
        }

        $template = array(
            'name' => $name,
            'lifetime' => (isset($_POST['lifetime']) && $_POST['lifetime'] === 'yes') ? 'yes' : 'no',
            'period' => isset($_POST['period']) ? max(0, intval($_POST['period'])) : 0,
            'unit' => $unit,
            'type' => $type,
            'display_price' => (isset($_POST['display_price']) && $_POST['display_price'] === 'yes') ? 'yes' : 'no',
            'url' => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
            'url_text' => isset($_POST['url_text']) ? sanitize_text_field(wp_unslash($_POST['url_text'])) : '',
            'file' => isset($_POST['file']) ? esc_url_raw(wp_unslash($_POST['file'])) : '',
            'file_text' => isset($_POST['file_text']) ? sanitize_text_field(wp_unslash($_POST['file_text'])) : '',
            'content' => isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '',
        );

        $templates = self::get_warranty_templates();
        $is_update = isset($templates[$template_id]);
        $templates[$template_id] = $template;

        // Keep templates sorted by name for a tidy dropdown
        uasort($templates, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        update_option('wcepi_warranty_templates', $templates, false);

        wp_send_json_success(array(
            'id' => $template_id,
            'template' => $template,
            'updated' => $is_update,
            'message' => $is_update
                ? sprintf(__('Template "%s" updated.', 'wc-enhanced-product-info'), $name)
                : sprintf(__('Template "%s" saved.', 'wc-enhanced-product-info'), $name),
        ));
    }

    /**
     * AJAX: delete a warranty template
     */
    public function ajax_delete_warranty_template() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcepi_warranty_templates')) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page and try again.', 'wc-enhanced-product-info')));
        }

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-enhanced-product-info')));
        }

        $template_id = isset($_POST['template_id']) ? sanitize_key($_POST['template_id']) : '';
        $templates = self::get_warranty_templates();

        if ($template_id === '' || !isset($templates[$template_id])) {
            wp_send_json_error(array('message' => __('Template not found.', 'wc-enhanced-product-info')));
        }

        $name = $templates[$template_id]['name'];
        unset($templates[$template_id]);
        update_option('wcepi_warranty_templates', $templates, false);

        wp_send_json_success(array(
            'id' => $template_id,
            'message' => sprintf(__('Template "%s" deleted.', 'wc-enhanced-product-info'), $name),
        ));
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wcepi_product_info',
            __('Enhanced Product Information', 'wc-enhanced-product-info'),
            array($this, 'render_meta_box'),
            'product',
            'normal',
            'high'
        );
    }
    
    /**
     * Render meta box
     */
    public function render_meta_box($post) {
        wp_nonce_field('wcepi_meta_box', 'wcepi_meta_box_nonce');
        
        $free_shipping = get_post_meta($post->ID, '_wcepi_free_shipping', true);
        $show_stock_quantity = get_post_meta($post->ID, '_wcepi_show_stock_quantity', true);
        $return_date = get_post_meta($post->ID, '_wcepi_return_date', true);
        $ships_in_days = get_post_meta($post->ID, '_wcepi_ships_in_days', true);
        $custom_dimensions = get_post_meta($post->ID, '_wcepi_custom_dimensions', true);
        $specifications = get_post_meta($post->ID, '_wcepi_specifications', true);
        $downloads = get_post_meta($post->ID, '_wcepi_downloads', true);
        $warranty_period = get_post_meta($post->ID, '_wcepi_warranty_period', true);
        $warranty_unit = get_post_meta($post->ID, '_wcepi_warranty_unit', true);
        $warranty_lifetime = get_post_meta($post->ID, '_wcepi_warranty_lifetime', true);
        $warranty_type = get_post_meta($post->ID, '_wcepi_warranty_type', true);
        $warranty_display_price = get_post_meta($post->ID, '_wcepi_warranty_display_price', true);
        $warranty_url = get_post_meta($post->ID, '_wcepi_warranty_url', true);
        $warranty_url_text = get_post_meta($post->ID, '_wcepi_warranty_url_text', true);
        $warranty_file = get_post_meta($post->ID, '_wcepi_warranty_file', true);
        $warranty_file_text = get_post_meta($post->ID, '_wcepi_warranty_file_text', true);
        $warranty_content = get_post_meta($post->ID, '_wcepi_warranty_content', true);
        $custom_shipping_returns = get_post_meta($post->ID, '_wcepi_custom_shipping_returns', true);
        $custom_returns = get_post_meta($post->ID, '_wcepi_custom_returns', true);
        $faqs = get_post_meta($post->ID, '_wcepi_faqs', true);
        
        if (!is_array($custom_dimensions)) {
            $custom_dimensions = array();
        }
        if (!is_array($specifications)) {
            $specifications = array();
        }
        if (!is_array($downloads)) {
            $downloads = array();
        }
        if (!is_array($faqs)) {
            $faqs = array();
        }
        ?>
        
        <div class="wcepi-meta-box-wrap">
            
            <!-- Free Shipping -->
            <?php if (get_option('wcepi_enable_free_shipping_badge', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Free Shipping', 'wc-enhanced-product-info'); ?></h3>
                <label>
                    <input type="checkbox" name="wcepi_free_shipping" value="yes" 
                           <?php checked($free_shipping, 'yes'); ?>>
                    <?php _e('Enable free shipping badge for this product', 'wc-enhanced-product-info'); ?>
                </label>
            </div>
            <?php endif; ?>
            
            <!-- Stock Status -->
            <?php if (get_option('wcepi_enable_stock_status', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Stock Status', 'wc-enhanced-product-info'); ?></h3>
                <label>
                    <input type="checkbox" name="wcepi_show_stock_quantity" value="yes"
                           <?php checked($show_stock_quantity, 'yes'); ?>>
                    <?php _e('Show stock quantity', 'wc-enhanced-product-info'); ?>
                </label>
                <p>
                    <label for="wcepi_ships_in_days">
                        <?php _e('Ships in (days):', 'wc-enhanced-product-info'); ?>
                    </label>
                    <input type="number" id="wcepi_ships_in_days" name="wcepi_ships_in_days"
                           value="<?php echo esc_attr($ships_in_days); ?>"
                           min="0" step="1" class="small-text" style="width: 80px;">
                    <span class="description"><?php _e('Number of days until product ships (e.g., 3 for "Ships in 3 days")', 'wc-enhanced-product-info'); ?></span>
                </p>
                <p>
                    <label for="wcepi_return_date">
                        <?php _e('Expected Return Date (for out of stock items):', 'wc-enhanced-product-info'); ?>
                    </label>
                    <input type="date" id="wcepi_return_date" name="wcepi_return_date"
                           value="<?php echo esc_attr($return_date); ?>" class="regular-text">
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Custom Dimensions -->
            <?php if (get_option('wcepi_enable_custom_dimensions', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Custom Dimensions', 'wc-enhanced-product-info'); ?></h3>
                <p class="description">
                    <?php _e('Add custom dimension fields beyond the default Width, Depth, Height', 'wc-enhanced-product-info'); ?>
                </p>
                <div id="wcepi-custom-dimensions">
                    <?php
                    if (!empty($custom_dimensions)) {
                        foreach ($custom_dimensions as $index => $dimension) {
                            ?>
                            <div class="wcepi-repeater-row">
                                <input type="text" name="wcepi_custom_dimensions[<?php echo $index; ?>][label]" 
                                       value="<?php echo esc_attr($dimension['label']); ?>" 
                                       placeholder="<?php _e('Label (e.g., Diameter)', 'wc-enhanced-product-info'); ?>" 
                                       class="regular-text">
                                <input type="text" name="wcepi_custom_dimensions[<?php echo $index; ?>][value]" 
                                       value="<?php echo esc_attr($dimension['value']); ?>" 
                                       placeholder="<?php _e('Value (e.g., 10 inches)', 'wc-enhanced-product-info'); ?>" 
                                       class="regular-text">
                                <button type="button" class="button wcepi-remove-row"><?php _e('Remove', 'wc-enhanced-product-info'); ?></button>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button wcepi-add-dimension"><?php _e('Add Dimension', 'wc-enhanced-product-info'); ?></button>
            </div>
            <?php endif; ?>
            
            <!-- Product Specifications -->
            <?php if (get_option('wcepi_enable_specifications', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Product Specifications', 'wc-enhanced-product-info'); ?></h3>
                <div id="wcepi-specifications">
                    <?php
                    if (!empty($specifications)) {
                        foreach ($specifications as $index => $spec) {
                            ?>
                            <div class="wcepi-repeater-row">
                                <span class="wcepi-drag-handle dashicons dashicons-menu" title="<?php _e('Drag to reorder', 'wc-enhanced-product-info'); ?>"></span>
                                <input type="text" name="wcepi_specifications[<?php echo $index; ?>][label]"
                                       value="<?php echo esc_attr($spec['label']); ?>"
                                       placeholder="<?php _e('Label (e.g., Material)', 'wc-enhanced-product-info'); ?>"
                                       class="regular-text">
                                <input type="text" name="wcepi_specifications[<?php echo $index; ?>][value]"
                                       value="<?php echo esc_attr($spec['value']); ?>"
                                       placeholder="<?php _e('Value (e.g., Stainless Steel)', 'wc-enhanced-product-info'); ?>"
                                       class="regular-text">
                                <input type="url" name="wcepi_specifications[<?php echo $index; ?>][url]"
                                       value="<?php echo esc_url(isset($spec['url']) ? $spec['url'] : ''); ?>"
                                       placeholder="<?php _e('Link URL (optional)', 'wc-enhanced-product-info'); ?>"
                                       class="regular-text">
                                <button type="button" class="button wcepi-remove-row"><?php _e('Remove', 'wc-enhanced-product-info'); ?></button>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button wcepi-add-specification"><?php _e('Add Specification', 'wc-enhanced-product-info'); ?></button>
            </div>
            <?php endif; ?>
            
            <!-- Downloads/Manuals -->
            <?php if (get_option('wcepi_enable_downloads', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Downloads/Manuals', 'wc-enhanced-product-info'); ?></h3>
                <div id="wcepi-downloads">
                    <?php
                    if (!empty($downloads)) {
                        foreach ($downloads as $index => $download) {
                            ?>
                            <div class="wcepi-repeater-row">
                                <input type="text" name="wcepi_downloads[<?php echo $index; ?>][title]" 
                                       value="<?php echo esc_attr($download['title']); ?>" 
                                       placeholder="<?php _e('Title (e.g., User Manual)', 'wc-enhanced-product-info'); ?>" 
                                       class="regular-text">
                                <input type="url" name="wcepi_downloads[<?php echo $index; ?>][url]" 
                                       value="<?php echo esc_url($download['url']); ?>" 
                                       placeholder="<?php _e('URL or upload PDF', 'wc-enhanced-product-info'); ?>" 
                                       class="regular-text wcepi-download-url">
                                <button type="button" class="button wcepi-upload-file" data-index="<?php echo $index; ?>">
                                    <?php _e('Upload', 'wc-enhanced-product-info'); ?>
                                </button>
                                <button type="button" class="button wcepi-remove-row"><?php _e('Remove', 'wc-enhanced-product-info'); ?></button>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button wcepi-add-download"><?php _e('Add Download', 'wc-enhanced-product-info'); ?></button>
            </div>
            <?php endif; ?>
            
            <!-- Warranty -->
            <?php if (get_option('wcepi_enable_warranty', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Warranty Information', 'wc-enhanced-product-info'); ?></h3>

                <?php $warranty_templates = self::get_warranty_templates(); ?>
                <div class="wcepi-warranty-templates">
                    <label for="wcepi-warranty-template-select"><strong><?php _e('Warranty Template:', 'wc-enhanced-product-info'); ?></strong></label>
                    <select id="wcepi-warranty-template-select">
                        <option value=""><?php _e('&mdash; Select a template &mdash;', 'wc-enhanced-product-info'); ?></option>
                        <?php foreach ($warranty_templates as $tpl_id => $tpl) : ?>
                            <option value="<?php echo esc_attr($tpl_id); ?>"><?php echo esc_html($tpl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="wcepi-warranty-template-apply"><?php _e('Apply', 'wc-enhanced-product-info'); ?></button>
                    <button type="button" class="button" id="wcepi-warranty-template-save"><?php _e('Save Current as Template', 'wc-enhanced-product-info'); ?></button>
                    <button type="button" class="button wcepi-template-delete" id="wcepi-warranty-template-delete"><?php _e('Delete', 'wc-enhanced-product-info'); ?></button>
                    <span id="wcepi-warranty-template-status" class="wcepi-template-status" aria-live="polite"></span>
                    <p class="description" style="flex-basis: 100%; margin: 4px 0 0;">
                        <?php _e('Apply fills the warranty fields below — tweak them freely, then update the product. "Save Current as Template" stores the fields below for reuse on other products (great for brands that share the same warranty).', 'wc-enhanced-product-info'); ?>
                    </p>
                </div>

                <p>
                    <label>
                        <input type="checkbox" id="wcepi_warranty_lifetime" name="wcepi_warranty_lifetime" value="yes"
                               <?php checked($warranty_lifetime, 'yes'); ?>>
                        <?php _e('Lifetime Duration', 'wc-enhanced-product-info'); ?>
                    </label>
                    <span class="description"><?php _e('Check this if the warranty lasts for the lifetime of the product', 'wc-enhanced-product-info'); ?></span>
                </p>
                
                <div id="wcepi_warranty_period_fields" style="<?php echo ($warranty_lifetime === 'yes') ? 'display:none;' : ''; ?>">
                    <p>
                        <label for="wcepi_warranty_period">
                            <?php _e('Warranty Duration:', 'wc-enhanced-product-info'); ?>
                        </label>
                        <input type="number" id="wcepi_warranty_period" name="wcepi_warranty_period"
                               value="<?php echo esc_attr($warranty_period); ?>"
                               min="0" step="1" class="small-text" style="width: 80px;">
                        <select id="wcepi_warranty_unit" name="wcepi_warranty_unit" style="width: 120px;">
                            <option value="years" <?php selected($warranty_unit, 'years'); ?>><?php _e('Years', 'wc-enhanced-product-info'); ?></option>
                            <option value="months" <?php selected($warranty_unit, 'months'); ?>><?php _e('Months', 'wc-enhanced-product-info'); ?></option>
                            <option value="days" <?php selected($warranty_unit, 'days'); ?>><?php _e('Days', 'wc-enhanced-product-info'); ?></option>
                        </select>
                    </p>
                </div>
                
                <p>
                    <label for="wcepi_warranty_type">
                        <?php _e('Warranty Type (for Schema):', 'wc-enhanced-product-info'); ?>
                    </label>
                    <select id="wcepi_warranty_type" name="wcepi_warranty_type" class="regular-text">
                        <option value=""><?php _e('Select warranty type...', 'wc-enhanced-product-info'); ?></option>
                        <option value="FullWarranty" <?php selected($warranty_type, 'FullWarranty'); ?>><?php _e('Full Warranty (Parts and Labor)', 'wc-enhanced-product-info'); ?></option>
                        <option value="LimitedWarranty" <?php selected($warranty_type, 'LimitedWarranty'); ?>><?php _e('Limited Warranty', 'wc-enhanced-product-info'); ?></option>
                        <option value="PartsWarranty" <?php selected($warranty_type, 'PartsWarranty'); ?>><?php _e('Parts Warranty', 'wc-enhanced-product-info'); ?></option>
                        <option value="LaborWarranty" <?php selected($warranty_type, 'LaborWarranty'); ?>><?php _e('Labor Warranty', 'wc-enhanced-product-info'); ?></option>
                        <option value="LifetimeWarranty" <?php selected($warranty_type, 'LifetimeWarranty'); ?>><?php _e('Lifetime Warranty', 'wc-enhanced-product-info'); ?></option>
                        <option value="ReplacementWarranty" <?php selected($warranty_type, 'ReplacementWarranty'); ?>><?php _e('Replacement Warranty', 'wc-enhanced-product-info'); ?></option>
                    </select>
                    <span class="description"><?php _e('This will be included in the product schema markup for search engines', 'wc-enhanced-product-info'); ?></span>
                </p>
                
                <p>
                    <label>
                        <input type="checkbox" id="wcepi_warranty_display_price" name="wcepi_warranty_display_price" value="yes"
                               <?php checked($warranty_display_price, 'yes'); ?>>
                        <?php _e('Display warranty info below product price', 'wc-enhanced-product-info'); ?>
                    </label>
                    <span class="description"><?php _e('Show warranty duration and type on the product page below the price', 'wc-enhanced-product-info'); ?></span>
                </p>
                
                <p>
                    <label for="wcepi_warranty_url">
                        <?php _e('Warranty Policy URL:', 'wc-enhanced-product-info'); ?>
                    </label>
                    <input type="url" id="wcepi_warranty_url" name="wcepi_warranty_url"
                           value="<?php echo esc_url($warranty_url); ?>"
                           placeholder="https://" class="regular-text">
                </p>
                
                <p>
                    <label for="wcepi_warranty_url_text">
                        <?php _e('URL Link Text:', 'wc-enhanced-product-info'); ?>
                    </label>
                    <input type="text" id="wcepi_warranty_url_text" name="wcepi_warranty_url_text"
                           value="<?php echo esc_attr($warranty_url_text); ?>"
                           placeholder="<?php _e('e.g., View Full Warranty Policy', 'wc-enhanced-product-info'); ?>"
                           class="regular-text">
                    <span class="description"><?php _e('Custom text for the warranty URL button', 'wc-enhanced-product-info'); ?></span>
                </p>
                
                <p>
                    <label for="wcepi_warranty_file">
                        <?php _e('Warranty Policy File:', 'wc-enhanced-product-info'); ?>
                    </label>
                    <input type="text" id="wcepi_warranty_file" name="wcepi_warranty_file"
                           value="<?php echo esc_url($warranty_file); ?>"
                           placeholder="<?php _e('Upload warranty document', 'wc-enhanced-product-info'); ?>"
                           class="regular-text" readonly>
                    <button type="button" class="button wcepi-upload-warranty-file">
                        <?php _e('Upload File', 'wc-enhanced-product-info'); ?>
                    </button>
                    <?php if ($warranty_file) : ?>
                        <button type="button" class="button wcepi-remove-warranty-file">
                            <?php _e('Remove', 'wc-enhanced-product-info'); ?>
                        </button>
                    <?php endif; ?>
                </p>
                
                <p>
                    <label for="wcepi_warranty_file_text">
                        <?php _e('File Link Text:', 'wc-enhanced-product-info'); ?>
                    </label>
                    <input type="text" id="wcepi_warranty_file_text" name="wcepi_warranty_file_text"
                           value="<?php echo esc_attr($warranty_file_text); ?>"
                           placeholder="<?php _e('e.g., Download Warranty Document', 'wc-enhanced-product-info'); ?>"
                           class="regular-text">
                    <span class="description"><?php _e('Custom text for the warranty file download button', 'wc-enhanced-product-info'); ?></span>
                </p>
                
                <p>
                    <label for="wcepi_warranty_content">
                        <?php _e('Warranty Content (Optional):', 'wc-enhanced-product-info'); ?>
                    </label>
                </p>
                <p class="description">
                    <?php _e('Add additional warranty information or terms. This will be displayed below the warranty period and links.', 'wc-enhanced-product-info'); ?>
                </p>
                <?php
                wp_editor($warranty_content, 'wcepi_warranty_content', array(
                    'textarea_name' => 'wcepi_warranty_content',
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                    'teeny' => true
                ));
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Custom Shipping Policy -->
            <?php if (get_option('wcepi_enable_shipping_returns', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Custom Shipping Policy', 'wc-enhanced-product-info'); ?></h3>
                <p class="description">
                    <?php _e('Leave empty to use the global shipping policy from settings', 'wc-enhanced-product-info'); ?>
                </p>
                <?php $this->render_content_template_toolbar('shipping', 'wcepi_custom_shipping_returns'); ?>
                <?php
                wp_editor($custom_shipping_returns, 'wcepi_custom_shipping_returns', array(
                    'textarea_name' => 'wcepi_custom_shipping_returns',
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                    'teeny' => true
                ));
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Custom Returns Policy -->
            <?php if (get_option('wcepi_enable_returns', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Custom Returns Policy', 'wc-enhanced-product-info'); ?></h3>
                <p class="description">
                    <?php _e('Leave empty to use the global returns policy from settings', 'wc-enhanced-product-info'); ?>
                </p>
                <?php $this->render_content_template_toolbar('returns', 'wcepi_custom_returns'); ?>
                <?php
                wp_editor($custom_returns, 'wcepi_custom_returns', array(
                    'textarea_name' => 'wcepi_custom_returns',
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                    'teeny' => true
                ));
                ?>
            </div>
            <?php endif; ?>
            
            <!-- FAQ Section -->
            <?php if (get_option('wcepi_enable_faq', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Frequently Asked Questions (FAQ)', 'wc-enhanced-product-info'); ?></h3>
                <p class="description">
                    <?php _e('Add questions and answers about this product', 'wc-enhanced-product-info'); ?>
                </p>
                <div id="wcepi-faqs">
                    <?php
                    if (!empty($faqs)) {
                        foreach ($faqs as $index => $faq) {
                            ?>
                            <div class="wcepi-faq-row">
                                <div class="wcepi-faq-question">
                                    <label><?php _e('Question:', 'wc-enhanced-product-info'); ?></label>
                                    <input type="text" name="wcepi_faqs[<?php echo $index; ?>][question]"
                                           value="<?php echo esc_attr($faq['question']); ?>"
                                           placeholder="<?php _e('Enter question', 'wc-enhanced-product-info'); ?>"
                                           class="widefat">
                                </div>
                                <div class="wcepi-faq-answer">
                                    <label><?php _e('Answer:', 'wc-enhanced-product-info'); ?></label>
                                    <textarea name="wcepi_faqs[<?php echo $index; ?>][answer]"
                                              rows="3"
                                              placeholder="<?php _e('Enter answer', 'wc-enhanced-product-info'); ?>"
                                              class="widefat"><?php echo esc_textarea($faq['answer']); ?></textarea>
                                </div>
                                <button type="button" class="button wcepi-remove-row"><?php _e('Remove FAQ', 'wc-enhanced-product-info'); ?></button>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button wcepi-add-faq"><?php _e('Add FAQ', 'wc-enhanced-product-info'); ?></button>
            </div>
            <?php endif; ?>
            
            <!-- Custom Sections -->
            <?php if (get_option('wcepi_enable_custom_sections', 'yes') === 'yes') : ?>
            <div class="wcepi-field-group">
                <h3><?php _e('Custom Sections', 'wc-enhanced-product-info'); ?></h3>
                <p class="description">
                    <?php _e('Create additional custom sections with either specification fields or rich text content', 'wc-enhanced-product-info'); ?>
                </p>
                <div id="wcepi-custom-sections">
                    <?php
                    $custom_sections = get_post_meta($post->ID, '_wcepi_custom_sections', true);
                    if (!empty($custom_sections) && is_array($custom_sections)) {
                        foreach ($custom_sections as $index => $section) {
                            $section_type = isset($section['type']) ? $section['type'] : 'fields';
                            $section_name = isset($section['name']) ? $section['name'] : '';
                            $section_fields = isset($section['fields']) ? $section['fields'] : array();
                            $section_content = isset($section['content']) ? $section['content'] : '';
                            ?>
                            <div class="wcepi-custom-section-row">
                                <div class="wcepi-custom-section-header">
                                    <input type="text" name="wcepi_custom_sections[<?php echo $index; ?>][name]"
                                           value="<?php echo esc_attr($section_name); ?>"
                                           placeholder="<?php _e('Section Name (e.g., Technical Details)', 'wc-enhanced-product-info'); ?>"
                                           class="widefat wcepi-section-name">
                                    
                                    <select name="wcepi_custom_sections[<?php echo $index; ?>][type]"
                                            class="wcepi-section-type" data-index="<?php echo $index; ?>">
                                        <option value="fields" <?php selected($section_type, 'fields'); ?>>
                                            <?php _e('Specification Fields (Label/Value)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                        <option value="textarea" <?php selected($section_type, 'textarea'); ?>>
                                            <?php _e('Rich Text Area (WYSIWYG)', 'wc-enhanced-product-info'); ?>
                                        </option>
                                    </select>
                                    
                                    <button type="button" class="button wcepi-remove-custom-section">
                                        <?php _e('Remove Section', 'wc-enhanced-product-info'); ?>
                                    </button>
                                </div>
                                
                                <!-- Fields Type Content -->
                                <div class="wcepi-section-fields-content"
                                     style="<?php echo ($section_type === 'textarea') ? 'display:none;' : ''; ?>">
                                    <div class="wcepi-section-fields-container" data-section-index="<?php echo $index; ?>">
                                        <?php
                                        if (!empty($section_fields) && is_array($section_fields)) {
                                            foreach ($section_fields as $field_index => $field) {
                                                ?>
                                                <div class="wcepi-section-field-row">
                                                    <input type="text"
                                                           name="wcepi_custom_sections[<?php echo $index; ?>][fields][<?php echo $field_index; ?>][label]"
                                                           value="<?php echo esc_attr($field['label']); ?>"
                                                           placeholder="<?php _e('Label', 'wc-enhanced-product-info'); ?>"
                                                           class="regular-text">
                                                    <input type="text"
                                                           name="wcepi_custom_sections[<?php echo $index; ?>][fields][<?php echo $field_index; ?>][value]"
                                                           value="<?php echo esc_attr($field['value']); ?>"
                                                           placeholder="<?php _e('Value', 'wc-enhanced-product-info'); ?>"
                                                           class="regular-text">
                                                    <button type="button" class="button wcepi-remove-section-field">
                                                        <?php _e('Remove', 'wc-enhanced-product-info'); ?>
                                                    </button>
                                                </div>
                                                <?php
                                            }
                                        }
                                        ?>
                                    </div>
                                    <button type="button" class="button wcepi-add-section-field" data-section-index="<?php echo $index; ?>">
                                        <?php _e('Add Field', 'wc-enhanced-product-info'); ?>
                                    </button>
                                </div>
                                
                                <!-- Textarea Type Content -->
                                <div class="wcepi-section-textarea-content"
                                     style="<?php echo ($section_type === 'fields') ? 'display:none;' : ''; ?>">
                                    <?php
                                    $editor_id = 'wcepi_custom_section_content_' . $index;
                                    wp_editor($section_content, $editor_id, array(
                                        'textarea_name' => 'wcepi_custom_sections[' . $index . '][content]',
                                        'textarea_rows' => 8,
                                        'media_buttons' => false,
                                        'teeny' => true
                                    ));
                                    ?>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button wcepi-add-custom-section">
                    <?php _e('Add Custom Section', 'wc-enhanced-product-info'); ?>
                </button>
            </div>
            <?php endif; ?>

            <!-- Section Sort Order (Per-Product) -->
            <div class="wcepi-field-group">
                <h3><?php _e('Section Sort Order', 'wc-enhanced-product-info'); ?></h3>
                <p class="description" style="margin-bottom: 10px;">
                    <?php _e('Drag and drop to customize section order for this product. Leave as-is to use the global default order.', 'wc-enhanced-product-info'); ?>
                </p>
                <label style="margin-bottom: 15px; display: block;">
                    <input type="checkbox" name="wcepi_use_custom_tab_order" value="yes"
                           <?php checked(get_post_meta($post->ID, '_wcepi_use_custom_tab_order', true), 'yes'); ?>>
                    <?php _e('Use custom sort order for this product', 'wc-enhanced-product-info'); ?>
                </label>
                <?php
                $product_tab_order = get_post_meta($post->ID, '_wcepi_tab_order', true);
                $use_custom = get_post_meta($post->ID, '_wcepi_use_custom_tab_order', true) === 'yes';

                // Get global order as fallback
                $tab_order = !empty($product_tab_order) ? $product_tab_order : WCEPI_Settings::get_tab_order();
                $tab_labels = WCEPI_Settings::get_tab_labels();

                // Sort tabs by their priority value
                asort($tab_order);
                ?>
                <ul id="wcepi-product-tab-sort-order" class="wcepi-product-sortable-list" style="<?php echo !$use_custom ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
                    <?php foreach ($tab_order as $tab_key => $priority) : ?>
                        <li class="wcepi-product-sortable-item" data-tab="<?php echo esc_attr($tab_key); ?>">
                            <span class="dashicons dashicons-menu wcepi-product-drag-handle"></span>
                            <span class="wcepi-product-tab-label"><?php echo esc_html($tab_labels[$tab_key] ?? $tab_key); ?></span>
                            <input type="hidden" name="wcepi_tab_order[<?php echo esc_attr($tab_key); ?>]" value="<?php echo esc_attr($priority); ?>" class="wcepi-product-tab-priority">
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

        </div>

        <style>
            .wcepi-meta-box-wrap {
                padding: 10px 0;
            }
            .wcepi-field-group {
                margin-bottom: 25px;
                padding-bottom: 20px;
                border-bottom: 1px solid #ddd;
            }
            .wcepi-field-group:last-child {
                border-bottom: none;
            }
            .wcepi-field-group h3 {
                margin-top: 0;
                margin-bottom: 10px;
            }
            /* Template toolbars (warranty, shipping, returns) */
            .wcepi-warranty-templates,
            .wcepi-template-toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                padding: 12px;
                margin-bottom: 15px;
                background: #f0f6fc;
                border: 1px solid #c3d5e8;
                border-radius: 4px;
            }
            .wcepi-warranty-templates select,
            .wcepi-template-toolbar select {
                min-width: 220px;
            }
            .wcepi-template-status {
                font-size: 13px;
                font-weight: 500;
            }
            .wcepi-template-status.wcepi-status-success {
                color: #007017;
            }
            .wcepi-template-status.wcepi-status-error {
                color: #d63638;
            }
            /* Product-level sortable list */
            .wcepi-product-sortable-list {
                list-style: none;
                padding: 0;
                margin: 0;
                max-width: 400px;
                transition: opacity 0.3s;
            }
            .wcepi-product-sortable-item {
                display: flex;
                align-items: center;
                padding: 8px 10px;
                margin-bottom: 3px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 3px;
                cursor: move;
            }
            .wcepi-product-sortable-item:hover {
                background: #f5f5f5;
            }
            .wcepi-product-sortable-item.ui-sortable-helper {
                box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            }
            .wcepi-product-sortable-item.ui-sortable-placeholder {
                visibility: visible !important;
                background: #e8f4fc;
                border: 2px dashed #2271b1;
            }
            .wcepi-product-drag-handle {
                color: #999;
                margin-right: 8px;
            }
            .wcepi-product-tab-label {
                flex: 1;
                font-weight: 500;
                font-size: 13px;
            }
            .wcepi-repeater-row {
                margin-bottom: 10px;
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .wcepi-repeater-row input[type="text"],
            .wcepi-repeater-row input[type="url"] {
                flex: 1;
            }
            .wcepi-drag-handle {
                cursor: move;
                color: #999;
                font-size: 18px;
                width: 20px;
                flex-shrink: 0;
            }
            .wcepi-drag-handle:hover {
                color: #666;
            }
            .wcepi-sortable-placeholder {
                border: 2px dashed #b4b9be;
                background: #f7f7f7;
                margin-bottom: 10px;
                height: 40px;
                border-radius: 4px;
            }
            .wcepi-faq-row {
                margin-bottom: 20px;
                padding: 15px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                position: relative;
            }
            .wcepi-faq-question,
            .wcepi-faq-answer {
                margin-bottom: 10px;
            }
            .wcepi-faq-question label,
            .wcepi-faq-answer label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
            }
            .wcepi-faq-row .wcepi-remove-row {
                margin-top: 10px;
            }
            .wcepi-custom-section-row {
                margin-bottom: 20px;
                padding: 15px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .wcepi-custom-section-header {
                display: flex;
                gap: 10px;
                margin-bottom: 15px;
                align-items: center;
            }
            .wcepi-section-name {
                flex: 2;
            }
            .wcepi-section-type {
                flex: 1;
            }
            .wcepi-section-fields-content,
            .wcepi-section-textarea-content {
                margin-top: 10px;
            }
            .wcepi-section-field-row {
                display: flex;
                gap: 10px;
                margin-bottom: 10px;
                align-items: center;
            }
            .wcepi-section-field-row input[type="text"] {
                flex: 1;
            }
            .wcepi-section-fields-container {
                margin-bottom: 10px;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Initialize sortable for product tab order
            if (typeof $.fn.sortable !== 'undefined') {
                $('#wcepi-product-tab-sort-order').sortable({
                    handle: '.wcepi-product-drag-handle',
                    placeholder: 'ui-sortable-placeholder',
                    update: function(event, ui) {
                        // Update priority values based on new order
                        $('#wcepi-product-tab-sort-order .wcepi-product-sortable-item').each(function(index) {
                            $(this).find('.wcepi-product-tab-priority').val((index + 1) * 5);
                        });
                    }
                });
            } else {
                console.log('WCEPI: jQuery UI Sortable not loaded for product page');
            }

            // Toggle sortable list when checkbox changes
            $('input[name="wcepi_use_custom_tab_order"]').on('change', function() {
                var $sortList = $('#wcepi-product-tab-sort-order');
                if ($(this).is(':checked')) {
                    $sortList.css({ 'opacity': '1', 'pointer-events': 'auto' });
                } else {
                    $sortList.css({ 'opacity': '0.5', 'pointer-events': 'none' });
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id) {
        // Check nonce
        if (!isset($_POST['wcepi_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['wcepi_meta_box_nonce'], 'wcepi_meta_box')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Only run for products, never for revisions
        if (get_post_type($post_id) !== 'product' || wp_is_post_revision($post_id)) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save free shipping
        $free_shipping = isset($_POST['wcepi_free_shipping']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wcepi_free_shipping', $free_shipping);
        
        // Save stock quantity display
        $show_stock_quantity = isset($_POST['wcepi_show_stock_quantity']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wcepi_show_stock_quantity', $show_stock_quantity);
        
        // Save ships in days
        if (isset($_POST['wcepi_ships_in_days'])) {
            $ships_in_days = intval($_POST['wcepi_ships_in_days']);
            if ($ships_in_days > 0) {
                update_post_meta($post_id, '_wcepi_ships_in_days', $ships_in_days);
            } else {
                delete_post_meta($post_id, '_wcepi_ships_in_days');
            }
        }
        
        // Save return date
        if (isset($_POST['wcepi_return_date'])) {
            update_post_meta($post_id, '_wcepi_return_date', sanitize_text_field($_POST['wcepi_return_date']));
        }
        
        // Save custom dimensions
        $dimensions = array();
        if (isset($_POST['wcepi_custom_dimensions'])) {
            foreach ($_POST['wcepi_custom_dimensions'] as $dimension) {
                if (!empty($dimension['label']) && !empty($dimension['value'])) {
                    $dimensions[] = array(
                        'label' => sanitize_text_field($dimension['label']),
                        'value' => sanitize_text_field($dimension['value'])
                    );
                }
            }
            if (!empty($dimensions)) {
                update_post_meta($post_id, '_wcepi_custom_dimensions', $dimensions);
            } else {
                delete_post_meta($post_id, '_wcepi_custom_dimensions');
            }
        } else {
            delete_post_meta($post_id, '_wcepi_custom_dimensions');
        }

        // Sync dimensions to WooCommerce attributes if enabled
        if (get_option('wcepi_sync_dimensions_to_attributes', 'no') === 'yes') {
            $this->cleanup_synced_attributes($post_id, 'dim', $dimensions);
            if (!empty($dimensions)) {
                $this->sync_to_wc_attributes($post_id, 'dim', $dimensions);
            }
        }

        // Save specifications
        $specifications = array();
        if (isset($_POST['wcepi_specifications'])) {
            foreach ($_POST['wcepi_specifications'] as $spec) {
                if (!empty($spec['label']) && !empty($spec['value'])) {
                    $specifications[] = array(
                        'label' => sanitize_text_field($spec['label']),
                        'value' => sanitize_text_field($spec['value']),
                        'url' => !empty($spec['url']) ? esc_url_raw($spec['url']) : ''
                    );
                }
            }
            if (!empty($specifications)) {
                update_post_meta($post_id, '_wcepi_specifications', $specifications);
            } else {
                delete_post_meta($post_id, '_wcepi_specifications');
            }
        } else {
            delete_post_meta($post_id, '_wcepi_specifications');
        }

        // Sync specifications to WooCommerce attributes if enabled
        if (get_option('wcepi_sync_specs_to_attributes', 'no') === 'yes') {
            $this->cleanup_synced_attributes($post_id, 'spec', $specifications);
            if (!empty($specifications)) {
                $this->sync_to_wc_attributes($post_id, 'spec', $specifications);
            }
        }
        
        // Save downloads
        if (isset($_POST['wcepi_downloads'])) {
            $downloads = array();
            foreach ($_POST['wcepi_downloads'] as $download) {
                if (!empty($download['title']) && !empty($download['url'])) {
                    $downloads[] = array(
                        'title' => sanitize_text_field($download['title']),
                        'url' => esc_url_raw($download['url'])
                    );
                }
            }
            if (!empty($downloads)) {
                update_post_meta($post_id, '_wcepi_downloads', $downloads);
            } else {
                delete_post_meta($post_id, '_wcepi_downloads');
            }
        } else {
            delete_post_meta($post_id, '_wcepi_downloads');
        }
        
        // Save warranty
        $warranty_lifetime = isset($_POST['wcepi_warranty_lifetime']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wcepi_warranty_lifetime', $warranty_lifetime);
        
        if (isset($_POST['wcepi_warranty_period'])) {
            update_post_meta($post_id, '_wcepi_warranty_period', intval($_POST['wcepi_warranty_period']));
        }
        if (isset($_POST['wcepi_warranty_unit'])) {
            update_post_meta($post_id, '_wcepi_warranty_unit', sanitize_text_field($_POST['wcepi_warranty_unit']));
        }
        if (isset($_POST['wcepi_warranty_type'])) {
            update_post_meta($post_id, '_wcepi_warranty_type', sanitize_text_field($_POST['wcepi_warranty_type']));
        }
        
        // Save warranty display below price checkbox
        $warranty_display_price = isset($_POST['wcepi_warranty_display_price']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wcepi_warranty_display_price', $warranty_display_price);
        if (isset($_POST['wcepi_warranty_url'])) {
            update_post_meta($post_id, '_wcepi_warranty_url', esc_url_raw($_POST['wcepi_warranty_url']));
        }
        if (isset($_POST['wcepi_warranty_url_text'])) {
            update_post_meta($post_id, '_wcepi_warranty_url_text', sanitize_text_field($_POST['wcepi_warranty_url_text']));
        }
        if (isset($_POST['wcepi_warranty_file'])) {
            update_post_meta($post_id, '_wcepi_warranty_file', esc_url_raw($_POST['wcepi_warranty_file']));
        }
        if (isset($_POST['wcepi_warranty_file_text'])) {
            update_post_meta($post_id, '_wcepi_warranty_file_text', sanitize_text_field($_POST['wcepi_warranty_file_text']));
        }
        if (isset($_POST['wcepi_warranty_content'])) {
            $content = wp_kses_post($_POST['wcepi_warranty_content']);
            $content = trim($content);
            if (!empty($content)) {
                update_post_meta($post_id, '_wcepi_warranty_content', $content);
            } else {
                delete_post_meta($post_id, '_wcepi_warranty_content');
            }
        }
        
        // Save custom shipping policy
        if (isset($_POST['wcepi_custom_shipping_returns'])) {
            $content = wp_kses_post($_POST['wcepi_custom_shipping_returns']);
            $content = trim($content);
            if (!empty($content)) {
                update_post_meta($post_id, '_wcepi_custom_shipping_returns', $content);
            } else {
                delete_post_meta($post_id, '_wcepi_custom_shipping_returns');
            }
        }
        
        // Save custom returns policy
        if (isset($_POST['wcepi_custom_returns'])) {
            $content = wp_kses_post($_POST['wcepi_custom_returns']);
            $content = trim($content);
            if (!empty($content)) {
                update_post_meta($post_id, '_wcepi_custom_returns', $content);
            } else {
                delete_post_meta($post_id, '_wcepi_custom_returns');
            }
        }
        
        // Save FAQs
        if (isset($_POST['wcepi_faqs'])) {
            $faqs = array();
            foreach ($_POST['wcepi_faqs'] as $faq) {
                if (!empty($faq['question']) && !empty($faq['answer'])) {
                    $faqs[] = array(
                        'question' => sanitize_text_field($faq['question']),
                        'answer' => sanitize_textarea_field($faq['answer'])
                    );
                }
            }
            if (!empty($faqs)) {
                update_post_meta($post_id, '_wcepi_faqs', $faqs);
            } else {
                delete_post_meta($post_id, '_wcepi_faqs');
            }
        } else {
            delete_post_meta($post_id, '_wcepi_faqs');
        }
        
        // Save custom sections
        if (isset($_POST['wcepi_custom_sections'])) {
            $custom_sections = array();
            foreach ($_POST['wcepi_custom_sections'] as $section) {
                if (!empty($section['name'])) {
                    $section_data = array(
                        'name' => sanitize_text_field($section['name']),
                        'type' => sanitize_text_field($section['type'])
                    );
                    
                    if ($section['type'] === 'fields' && !empty($section['fields'])) {
                        $fields = array();
                        foreach ($section['fields'] as $field) {
                            if (!empty($field['label']) && !empty($field['value'])) {
                                $fields[] = array(
                                    'label' => sanitize_text_field($field['label']),
                                    'value' => sanitize_text_field($field['value'])
                                );
                            }
                        }
                        $section_data['fields'] = $fields;
                    } elseif ($section['type'] === 'textarea' && !empty($section['content'])) {
                        $section_data['content'] = wp_kses_post($section['content']);
                    }
                    
                    $custom_sections[] = $section_data;
                }
            }
            if (!empty($custom_sections)) {
                update_post_meta($post_id, '_wcepi_custom_sections', $custom_sections);
            } else {
                delete_post_meta($post_id, '_wcepi_custom_sections');
            }
        } else {
            delete_post_meta($post_id, '_wcepi_custom_sections');
        }

        // Save custom tab order checkbox
        $use_custom_tab_order = isset($_POST['wcepi_use_custom_tab_order']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wcepi_use_custom_tab_order', $use_custom_tab_order);

        // Save tab sort order
        if (isset($_POST['wcepi_tab_order']) && is_array($_POST['wcepi_tab_order'])) {
            $tab_order = array();
            foreach ($_POST['wcepi_tab_order'] as $tab_key => $priority) {
                $tab_order[sanitize_key($tab_key)] = absint($priority);
            }
            update_post_meta($post_id, '_wcepi_tab_order', $tab_order);
        }
    }

    /**
     * Public wrapper for sync_to_wc_attributes (used by bulk sync)
     *
     * @param int    $product_id The product ID
     * @param string $data_type  Either 'spec' or 'dim' to prefix attribute names
     * @param array  $items      Array of items with 'label' and 'value' keys
     */
    public function sync_to_wc_attributes_public($product_id, $data_type, $items) {
        $this->sync_to_wc_attributes($product_id, $data_type, $items);
    }

    /**
     * Sync specifications or dimensions to WooCommerce product attributes
     *
     * @param int    $product_id The product ID
     * @param string $data_type  Either 'spec' or 'dim' to prefix attribute names
     * @param array  $items      Array of items with 'label' and 'value' keys
     */
    private function sync_to_wc_attributes($product_id, $data_type, $items) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $existing_attributes = $product->get_attributes();
        $prefix = 'wcepi_' . $data_type . '_';

        // Track which attributes we're adding/updating
        $synced_taxonomies = array();

        foreach ($items as $item) {
            $label = $item['label'];
            $value = $item['value'];

            if (empty($label) || empty($value)) {
                continue;
            }

            // Create sanitized attribute slug with prefix to avoid conflicts
            $attr_slug = $prefix . sanitize_title($label);

            // WordPress taxonomy names are limited to 32 chars, pa_ prefix takes 3
            if (strlen($attr_slug) > 28) {
                $attr_slug = substr($attr_slug, 0, 28);
            }

            $taxonomy = 'pa_' . $attr_slug;
            $synced_taxonomies[] = $taxonomy;

            // Create global attribute if it doesn't exist
            $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy);

            if (!$attribute_id) {
                $attribute_id = wc_create_attribute(array(
                    'name' => $label,
                    'slug' => $attr_slug,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));

                if (is_wp_error($attribute_id)) {
                    continue;
                }

                // Register taxonomy for current request
                register_taxonomy(
                    $taxonomy,
                    'product',
                    array(
                        'labels' => array('name' => $label),
                        'hierarchical' => false,
                        'show_ui' => true,
                        'query_var' => true,
                        'rewrite' => array('slug' => $attr_slug)
                    )
                );
            }

            // Create or get term for the value
            $term = term_exists($value, $taxonomy);
            if (!$term) {
                $term = wp_insert_term($value, $taxonomy);
            }

            if (is_wp_error($term)) {
                continue;
            }

            $term_id = is_array($term) ? $term['term_id'] : $term;

            // Set the term on the product
            wp_set_object_terms($product_id, array((int)$term_id), $taxonomy);

            // Add attribute to product
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options(array((int)$term_id));
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $existing_attributes[$taxonomy] = $attribute;
        }

        $product->set_attributes($existing_attributes);
        $product->save();

        // Store which taxonomies we've synced for this product (for cleanup)
        update_post_meta($product_id, '_wcepi_synced_' . $data_type . '_attrs', $synced_taxonomies);
    }

    /**
     * Clean up synced attributes when specs/dimensions are removed
     *
     * @param int    $product_id The product ID
     * @param string $data_type  Either 'spec' or 'dim'
     * @param array  $current_items Current items being saved
     */
    private function cleanup_synced_attributes($product_id, $data_type, $current_items) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $prefix = 'wcepi_' . $data_type . '_';
        $previous_taxonomies = get_post_meta($product_id, '_wcepi_synced_' . $data_type . '_attrs', true);

        if (!is_array($previous_taxonomies)) {
            $previous_taxonomies = array();
        }

        // Build list of current taxonomies
        $current_taxonomies = array();
        foreach ($current_items as $item) {
            if (!empty($item['label'])) {
                $attr_slug = $prefix . sanitize_title($item['label']);
                if (strlen($attr_slug) > 28) {
                    $attr_slug = substr($attr_slug, 0, 28);
                }
                $current_taxonomies[] = 'pa_' . $attr_slug;
            }
        }

        // Find taxonomies to remove
        $taxonomies_to_remove = array_diff($previous_taxonomies, $current_taxonomies);

        if (empty($taxonomies_to_remove)) {
            return;
        }

        $existing_attributes = $product->get_attributes();

        foreach ($taxonomies_to_remove as $taxonomy) {
            // Remove terms from product
            wp_set_object_terms($product_id, array(), $taxonomy);

            // Remove from product attributes
            if (isset($existing_attributes[$taxonomy])) {
                unset($existing_attributes[$taxonomy]);
            }
        }

        $product->set_attributes($existing_attributes);
        $product->save();
    }
}