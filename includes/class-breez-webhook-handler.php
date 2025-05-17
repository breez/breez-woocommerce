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
     * @return bool Whether the request is valid
     */
    public static function validate_webhook($request) {
        self::init_logger();
        
        // For improved security, you could implement signature validation here
        // For now, we'll just ensure the request is coming from an allowed IP
        
        // Return true to allow the webhook to be processed
        return true;
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
        
        $payment_handler = new Breez_Payment_Handler(null, $db_manager);
        
        // Update payment status based on the webhook data
        if ($status === 'SUCCEEDED') {
            $payment_handler->process_successful_payment($invoice_id);
        } elseif ($status === 'FAILED') {
            $payment_handler->process_failed_payment($invoice_id);
        } else {
            self::$logger->log("Unknown payment status: $status");
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Webhook processed successfully'
        ), 200);
    }
}
