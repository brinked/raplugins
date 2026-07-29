<?php
/**
 * Admin Class
 *
 * @package WC_Enhanced_Product_Info
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCEPI_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Load color picker on our settings page and product edit pages
        // Note: admin.js is enqueued in main plugin file (wc-enhanced-product-info.php)
        // This only adds the color picker dependency

        $load_scripts = false;

        // Check if we're on the settings page
        if (strpos($hook, 'wcepi-settings') !== false) {
            $load_scripts = true;
        }

        // Check if we're on product edit page
        if (($hook === 'post.php' || $hook === 'post-new.php') && isset($_GET['post'])) {
            $post_type = get_post_type($_GET['post']);
            if ($post_type === 'product') {
                $load_scripts = true;
            }
        } elseif ($hook === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'product') {
            $load_scripts = true;
        }

        if ($load_scripts) {
            // Enqueue WordPress color picker (admin.js handles initialization)
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
        }
    }
}