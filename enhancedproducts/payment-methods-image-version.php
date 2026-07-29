<?php
/**
 * REPLACEMENT CODE for display_payment_methods_badge() function
 * 
 * Replace the entire display_payment_methods_badge() function in:
 * enhancedproducts/includes/class-wcepi-frontend.php
 * 
 * Starting at line 235, replace everything until the closing brace of the function
 */

public function display_payment_methods_badge() {
    // Check if feature is enabled
    if (get_option('wcepi_enable_payment_methods', 'no') !== 'yes') {
        return;
    }
    
    // Get selected payment methods
    $payment_methods = get_option('wcepi_payment_methods', array());
    
    if (empty($payment_methods) || !is_array($payment_methods)) {
        return;
    }
    
    // Map payment method keys to image filenames
    $payment_images = array(
        'visa' => 'visa.png',
        'mastercard' => 'mastercard.png',
        'amex' => 'americanexpress.png',
        'discover' => 'discover.png',
        'paypal' => 'paypal.png',
        'apple_pay' => 'applepay.png',  // Add if you have this file
        'google_pay' => 'googlepay.png', // Add if you have this file
        'venmo' => 'venmo.png',          // Add if you have this file
        'afterpay' => 'afterpay.png',    // Add if you have this file
        'klarna' => 'klarna.png',        // Add if you have this file
        'stripe' => 'stripe.png',        // Add if you have this file
        'cash' => 'cash.png',            // Add if you have this file
        'check' => 'check.png',          // Add if you have this file
        'bank_transfer' => 'bank.png',   // Add if you have this file
        'cirrus' => 'cirrus.png',
        'maestro' => 'maestro.png',
        'worldpay' => 'worldpay.png'
    );
    
    // Get plugin URL for images
    $images_url = plugin_dir_url(dirname(__FILE__)) . 'assets/paymenticons/';
    
    echo '<div class="wcepi-payment-methods-badge">';
    echo '<div class="wcepi-payment-methods-icons">';
    
    foreach ($payment_methods as $method) {
        if (isset($payment_images[$method])) {
            $image_file = $payment_images[$method];
            $image_url = $images_url . $image_file;
            $method_name = ucwords(str_replace('_', ' ', $method));
            
            echo '<img src="' . esc_url($image_url) . '" ';
            echo 'alt="' . esc_attr($method_name) . '" ';
            echo 'title="' . esc_attr($method_name) . '" ';
            echo 'class="wcepi-payment-icon" />';
        }
    }
    
    echo '</div>';
    echo '</div>';
}