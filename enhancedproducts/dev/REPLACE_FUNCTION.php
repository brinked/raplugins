<?php
/**
 * COMPLETE REPLACEMENT for display_payment_methods_badge() function
 * 
 * In enhancedproducts/includes/class-wcepi-frontend.php
 * Replace lines 235-279 with this entire function:
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
        'apple_pay' => 'applepay.png',
        'google_pay' => 'googlepay.png',
        'venmo' => 'venmo.png',
        'afterpay' => 'afterpay.png',
        'klarna' => 'klarna.png',
        'stripe' => 'stripe.png',
        'cash' => 'cash.png',
        'check' => 'check.png',
        'bank_transfer' => 'bank.png',
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
            echo 'width="20" height="20" ';
            echo 'class="wcepi-payment-icon" />';
        }
    }
    
    echo '</div>';
    echo '</div>';
}