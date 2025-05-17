<?php
/**
 * Admin settings template
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// This file is a placeholder for any additional admin settings template content
// The main settings are handled by WooCommerce's settings API in class-wc-gateway-breez.php
?>

<div class="breez-admin-settings-info">
    <h3><?php _e('Additional Information', 'breez-woocommerce'); ?></h3>
    
    <p><?php _e('Breez Nodeless Payments for WooCommerce allows your customers to pay with Bitcoin via Lightning Network or on-chain transactions.', 'breez-woocommerce'); ?></p>
    
    <h4><?php _e('Requirements', 'breez-woocommerce'); ?></h4>
    <ul>
        <li><?php _e('Breez Nodeless API set up and running', 'breez-woocommerce'); ?></li>
        <li><?php _e('API key for authentication', 'breez-woocommerce'); ?></li>
        <li><?php _e('Properly configured webhook endpoint', 'breez-woocommerce'); ?></li>
    </ul>
    
    <h4><?php _e('Testing', 'breez-woocommerce'); ?></h4>
    <p><?php _e('To test your setup, enable "Test Mode" and use a testnet Lightning wallet to make test payments.', 'breez-woocommerce'); ?></p>
</div>

<style>
    .breez-admin-settings-info {
        max-width: 800px;
        margin-top: 20px;
        padding: 20px;
        background: #fff;
        border-left: 4px solid #0073aa;
        box-shadow: 0 1px 1px rgba(0,0,0,0.1);
    }
    
    .breez-admin-settings-info h3 {
        margin-top: 0;
    }
    
    .breez-admin-settings-info ul {
        list-style-type: disc;
        margin-left: 20px;
    }
</style>
