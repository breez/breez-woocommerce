<?php
/**
 * Breez Webhook Handler
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez Webhook Handler Class
 *
 * Handles incoming webhook requests from the Breez API.
 */
class Breez_Webhook_Handler {
    /**
     * Logger instance
     *
     * @var Breez_Logger
     */
    private static $logger;
    
    /**
     * Initialize logger
     */
    private static function init_logger() {
        if (!self::$logger) {
            self::$logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
        }
    }
    
    /**
     * Validate webhook request
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error Whether the request is valid
     */
    public static function validate_webhook($request) {
        self::init_logger();
        
        try {
            // Get the webhook secret from settings
            $settings = get_option('woocommerce_breez_settings', array());
            $webhook_secret = isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '';
            
            if (empty($webhook_secret)) {
                self::$logger->log('Webhook validation failed: No webhook secret configured', 'error');
                return new WP_Error('invalid_webhook', 'No webhook secret configured', array('status' => 401));
            }

            // Get headers
            $signature = $request->get_header('X-Breez-Signature');
            $timestamp = $request->get_header('X-Breez-Timestamp');
            $nonce = $request->get_header('X-Breez-Nonce');

            // Validate required headers
            if (empty($signature) || empty($timestamp) || empty($nonce)) {
                self::$logger->log('Webhook validation failed: Missing required headers', 'error');
                return new WP_Error('invalid_webhook', 'Missing required headers', array('status' => 401));
            }

            // Validate timestamp (within 5 minutes)
            $timestamp_int = (int) $timestamp;
            $current_time = time();
            if (abs($current_time - $timestamp_int) > 300) {
                self::$logger->log('Webhook validation failed: Timestamp expired', 'error');
                return new WP_Error('invalid_webhook', 'Timestamp expired', array('status' => 401));
            }

            // Get request body
            $body = $request->get_body();
            if (empty($body)) {
                self::$logger->log('Webhook validation failed: Empty request body', 'error');
                return new WP_Error('invalid_webhook', 'Empty request body', array('status' => 400));
            }

            // Prevent replay attacks by checking nonce
            $used_nonces = get_transient('breez_used_webhook_nonces') ?: array();
            if (in_array($nonce, $used_nonces)) {
                self::$logger->log('Webhook validation failed: Nonce already used', 'error');
                return new WP_Error('invalid_webhook', 'Nonce already used', array('status' => 401));
            }

            // Calculate expected signature
            $payload = $timestamp . $nonce . $body;
            $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

            // Verify signature
            if (!hash_equals($expected_signature, $signature)) {
                self::$logger->log('Webhook validation failed: Invalid signature', 'error');
                return new WP_Error('invalid_webhook', 'Invalid signature', array('status' => 401));
            }

            // Store nonce to prevent replay attacks (expire after 24 hours)
            $used_nonces[] = $nonce;
            set_transient('breez_used_webhook_nonces', array_slice($used_nonces, -1000), DAY_IN_SECONDS);

            self::$logger->log('Webhook validation successful', 'debug');
            return true;

        } catch (Exception $e) {
            self::$logger->log('Webhook validation error: ' . $e->getMessage(), 'error');
            return new WP_Error('webhook_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * Process webhook request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public static function process_webhook($request) {
        self::init_logger();
        
        self::$logger->log("Received webhook request");
        
        // Get request data
        $data = $request->get_json_params();
        
        if (!$data) {
            self::$logger->log("Invalid webhook data: empty or not JSON");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid request data'
            ), 400);
        }
        
        self::$logger->log("Webhook data: " . json_encode($data));
        
        // Check for required fields
        if (!isset($data['invoice_id']) || !isset($data['status'])) {
            self::$logger->log("Missing required fields in webhook data");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required fields'
            ), 400);
        }
        
        $invoice_id = $data['invoice_id'];
        $status = $data['status'];
        
        // Process the payment
        $db_manager = new Breez_DB_Manager();
        $payment = $db_manager->get_payment_by_invoice($invoice_id);
        
        if (!$payment) {
            self::$logger->log("No payment found for invoice $invoice_id");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payment not found'
            ), 404);
        }
        
        $order_id = $payment['order_id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            self::$logger->log("No order found for order ID $order_id");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Order not found'
            ), 404);
        }
        
        // Update payment status in database
        $db_manager->update_payment_status($order_id, $status);
        
        // Update order status based on payment status
        if ($status === 'SUCCEEDED') {
            if ($order->get_status() === 'pending') {
                // Complete the order
                $order->payment_complete($invoice_id);
                $order->add_order_note(sprintf(
                    __('Payment confirmed. Amount: %d sats, Hash: %s', 'breez-woocommerce'),
                    $payment['metadata']['amount_sat'],
                    $invoice_id
                ));
                $order->save();
                
                self::$logger->log("Order #$order_id marked as complete", 'info');
            }
        } else if ($status === 'FAILED') {
            if ($order->get_status() === 'pending') {
                $order->update_status('failed', __('Payment failed or expired.', 'breez-woocommerce'));
                self::$logger->log("Order #$order_id marked as failed", 'info');
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'status' => $status
        ), 200);
    }
}
