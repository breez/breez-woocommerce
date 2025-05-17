<?php
/**
 * WooCommerce Blocks Integration for Breez Nodeless Payments
 * 
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez Blocks Support
 */
class Breez_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    /**
     * Name of the payment method
     *
     * @var string
     */
    protected $name = 'breez';

    /**
     * Gateway instance
     *
     * @var WC_Gateway_Breez
     */
    private $gateway;

    /**
     * Logger instance
     *
     * @var Breez_Logger
     */
    private $logger;

    /**
     * Setup Blocks integration
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_breez_settings', []);
        
        // Initialize logger
        $this->logger = new Breez_Logger(true);
        $this->logger->log('Initializing Breez Blocks Support', 'debug');
        
        // Create payment gateway instance if not exists
        if (!isset($this->gateway) && class_exists('WC_Gateway_Breez')) {
            $this->gateway = new WC_Gateway_Breez();
        }
        
        // Log initialization for debugging
        if (isset($this->gateway)) {
            $this->logger->log('Gateway instance created successfully', 'debug');
            $this->logger->log('Current settings: ' . print_r($this->settings, true), 'debug');
        } else {
            $this->logger->log('Failed to create gateway instance', 'error');
        }
    }

    /**
     * Check if this payment method is active
     *
     * @return bool
     */
    public function is_active() {
        $is_active = false;
        
        if (isset($this->gateway)) {
            $is_active = $this->gateway->is_available();
            $this->logger->log('Gateway availability check: ' . ($is_active ? 'yes' : 'no'), 'debug');
            
            if (!$is_active) {
                $this->logger->log('Gateway not available. Settings: ' . print_r($this->settings, true), 'debug');
            }
        } else {
            $is_active = !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
            $this->logger->log('Fallback availability check: ' . ($is_active ? 'yes' : 'no'), 'debug');
        }
        
        return $is_active;
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $this->logger->log('Registering payment method script handles', 'debug');
        
        $handles = [];

        // Register and enqueue the widget script
        $widget_handle = 'breez-widget';
        $widget_asset_file = plugin_dir_path(__FILE__) . 'block/breez-widget.asset.php';
        $widget_asset_data = file_exists($widget_asset_file) ? require($widget_asset_file) : array('dependencies' => array('wp-polyfill', 'jquery'), 'version' => '1.0.0');

        wp_register_script(
            $widget_handle,
            plugins_url('block/breez-widget.js', __FILE__),
            $widget_asset_data['dependencies'],
            $widget_asset_data['version'],
            true
        );

        // Register and enqueue the payment script
        $payment_handle = 'breez-blocks';
        $payment_asset_file = plugin_dir_path(__FILE__) . 'block/breez-payment.asset.php';
        $payment_asset_data = file_exists($payment_asset_file) ? require($payment_asset_file) : array('dependencies' => array('wp-blocks', 'wp-element', 'wp-components', 'wc-blocks-registry'), 'version' => '1.0.0');

        wp_register_script(
            $payment_handle,
            plugins_url('block/breez-payment.js', __FILE__),
            $payment_asset_data['dependencies'],
            $payment_asset_data['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($widget_handle, 'breez-woocommerce');
            wp_set_script_translations($payment_handle, 'breez-woocommerce');
        }

        $handles[] = $widget_handle;
        $handles[] = $payment_handle;

        return $handles;
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods
     * script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $data = [
            'title' => !empty($this->settings['title']) ? $this->settings['title'] : 'Breez Nodeless Payments',
            'description' => !empty($this->settings['description']) ? $this->settings['description'] : 'Pay with Bitcoin via Lightning Network or on-chain transaction.',
            'supports' => ['products'],
            'showSavedCards' => false,
            'canMakePayment' => true,
            'paymentMethodId' => 'breez',
            'orderButtonText' => __('Proceed to Payment', 'breez-woocommerce'),
            'defaultPaymentMethod' => 'lightning'
        ];
        
        $this->logger->log('Payment method data: ' . print_r($data, true), 'debug');
        return $data;
    }

    /**
     * Process the payment
     *
     * @param \WC_Order $order
     * @param array $payment_data
     * @return array
     */
    public function process_payment($order, $payment_data) {
        try {
            $this->logger->log('Processing payment in Blocks Support', 'debug');
            $this->logger->log('Order ID: ' . $order->get_id(), 'debug');
            $this->logger->log('Payment Data: ' . print_r($payment_data, true), 'debug');
            
            if (!isset($this->gateway)) {
                $this->gateway = new WC_Gateway_Breez();
            }
            
            if (!$this->gateway) {
                throw new Exception(__('Payment gateway not initialized', 'breez-woocommerce'));
            }
            
            // Get payment method from blocks data
            $payment_method = 'lightning'; // Default to lightning
            if (isset($payment_data['paymentMethodData']['data']['breez_payment_method'])) {
                $payment_method = sanitize_text_field($payment_data['paymentMethodData']['data']['breez_payment_method']);
            }
            
            // Store payment method in POST for gateway processing
            $_POST['breez_payment_method'] = $payment_method;
            
            // Store any additional payment data
            $order->update_meta_data('_breez_blocks_payment_data', wp_json_encode([
                'payment_method' => $payment_method,
                'timestamp' => time()
            ]));
            $order->save();
            
            $this->logger->log('Selected payment method: ' . $payment_method, 'debug');
            
            // Process the payment
            $result = $this->gateway->process_payment($order->get_id());
            
            if (!is_array($result)) {
                throw new Exception(__('Invalid payment gateway response', 'breez-woocommerce'));
            }
            
            $this->logger->log('Payment processing result: ' . print_r($result, true), 'debug');
            
            // Ensure we have a proper response format
            if (!isset($result['result'])) {
                $result['result'] = 'success';
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->log('Payment processing error: ' . $e->getMessage(), 'error');
            $this->logger->log('Error trace: ' . $e->getTraceAsString(), 'debug');
            
            // Return a properly formatted error response
            return [
                'result' => 'failure',
                'messages' => [
                    'error' => [
                        'message' => $e->getMessage()
                    ]
                ],
                'redirect' => wc_get_cart_url()
            ];
        }
    }
}