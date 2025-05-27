<?php
/**
 * Breez Nodeless Payments Settings
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

return array(
    'enabled' => array(
        'title'   => __('Enable/Disable', 'breez-woocommerce'),
        'type'    => 'checkbox',
        'label'   => __('Enable Breez Nodeless Payments', 'breez-woocommerce'),
        'default' => 'no'
    ),
    'title' => array(
        'title'       => __('Title', 'breez-woocommerce'),
        'type'        => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'breez-woocommerce'),
        'default'     => __('Pay with Lightning', 'breez-woocommerce'),
        'desc_tip'    => true,
    ),
    'description' => array(
        'title'       => __('Description', 'breez-woocommerce'),
        'type'        => 'textarea',
        'description' => __('This controls the description which the user sees during checkout.', 'breez-woocommerce'),
        'default'     => __('', 'breez-woocommerce'),
        'desc_tip'    => true,
    ),
    'instructions' => array(
        'title'       => __('Instructions', 'breez-woocommerce'),
        'type'        => 'textarea',
        'description' => __('Instructions that will be added to the thank you page and emails.', 'breez-woocommerce'),
        'default'     => __('Pay this invoice with a Lightning wallet to complete your payment.', 'breez-woocommerce'),
        'desc_tip'    => true,
    ),
    'api_settings' => array(
        'title'       => __('API Settings', 'breez-woocommerce'),
        'type'        => 'title',
        'description' => __('Enter your Breez API credentials below. The API key will be used for both API authentication and webhook validation.', 'breez-woocommerce'),
    ),
    'api_url' => array(
        'title'       => __('API URL', 'breez-woocommerce'),
        'type'        => 'text',
        'description' => __('Enter your Breez API URL.', 'breez-woocommerce'),
        'default'     => 'http://localhost:8000',
        'desc_tip'    => true,
    ),
    'api_key' => array(
        'title'       => __('API Key', 'breez-woocommerce'),
        'type'        => 'password',
        'description' => __('Enter your Breez API key. This key will be used for both API authentication and webhook validation.', 'breez-woocommerce'),
        'default'     => '',
        'desc_tip'    => true,
    ),
    'payment_options' => array(
        'title'       => __('Payment Options', 'breez-woocommerce'),
        'type'        => 'title',
        'description' => __('Configure payment behavior.', 'breez-woocommerce'),
    ),
    'payment_methods' => array(
        'title'       => __('Payment Methods', 'breez-woocommerce'),
        'type'        => 'multiselect',
        'class'       => 'wc-enhanced-select',
        'description' => __('Select the payment methods you want to accept.', 'breez-woocommerce'),
        'default'     => array('lightning', 'onchain'),
        'options'     => array(
            'lightning' => __('Lightning Network', 'breez-woocommerce'),
            'onchain'   => __('On-chain Bitcoin', 'breez-woocommerce'),
        ),
        'desc_tip'    => true,
    ),
    'expiry_minutes' => array(
        'title'       => __('Invoice Expiry Time', 'breez-woocommerce'),
        'type'        => 'number',
        'description' => __('Number of minutes after which unpaid invoices expire.', 'breez-woocommerce'),
        'default'     => 30,
        'desc_tip'    => true,
        'custom_attributes' => array(
            'min'  => 5,
            'max'  => 1440,
            'step' => 1,
        ),
    ),
    'advanced_options' => array(
        'title'       => __('Advanced Options', 'breez-woocommerce'),
        'type'        => 'title',
        'description' => __('Additional settings for advanced users.', 'breez-woocommerce'),
    ),
    'debug' => array(
        'title'       => __('Debug Log', 'breez-woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable logging', 'breez-woocommerce'),
        'default'     => 'no',
        'description' => __('Log Breez payment events, such as API requests, inside the WooCommerce logs directory.', 'breez-woocommerce'),
    ),
    'testmode' => array(
        'title'       => __('Test Mode', 'breez-woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable Test Mode', 'breez-woocommerce'),
        'default'     => 'no',
        'description' => __('Place the payment gateway in test mode.', 'breez-woocommerce'),
    ),
); 