<?php
/**
 * Breez Payment Gateway
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez Payment Gateway
 *
 * Provides a Bitcoin & Lightning Payment Gateway for WooCommerce.
 *
 * @class    WC_Gateway_Breez
 * @extends  WC_Payment_Gateway
 * @version  1.0.0
 * @package  Breez_WooCommerce
 */
class WC_Gateway_Breez extends WC_Payment_Gateway {
    
    /**
     * API Client instance
     *
     * @var Breez_API_Client
     */
    protected $client;
    
    /**
     * DB Manager instance
     *
     * @var Breez_DB_Manager
     */
    protected $db_manager;
    
    /**
     * Payment Handler instance
     *
     * @var Breez_Payment_Handler
     */
    protected $payment_handler;
    
    /**
     * Logger instance
     *
     * @var Breez_Logger
     */
    protected $logger;
    
    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'breez';
        $this->icon               = apply_filters('woocommerce_breez_icon', '');
        $this->has_fields         = false;
        $this->method_title       = __('Pay with Lightning', 'breez-woocommerce');
        $this->method_description = __('', 'breez-woocommerce');
        $this->supports           = array(
            'products',
            'refunds'
        );
        
        // Initialize plugin settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define user set variables
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->enabled      = $this->get_option('enabled');
        $this->testmode     = 'yes' === $this->get_option('testmode');
        $this->debug        = 'yes' === $this->get_option('debug');
        $this->api_url      = $this->get_option('api_url');
        $this->api_key      = $this->get_option('api_key');
        $this->expiry_minutes = (int) $this->get_option('expiry_minutes', 30);
        $this->payment_methods = $this->get_option('payment_methods', 'lightning,onchain');
        // Convert payment_methods to array if it's a string
        if (is_string($this->payment_methods)) {
            $this->payment_methods = array_filter(array_map('trim', explode(',', $this->payment_methods)));
        }
        
        // Initialize logger first for comprehensive logging
        $this->logger = new Breez_Logger($this->debug);
        $this->logger->log('Initializing Breez Nodeless Payments gateway', 'debug');
        $this->logger->log('Current settings: ' . print_r($this->settings, true), 'debug');
        
        // Validate API credentials
        if ($this->enabled === 'yes' && (!$this->api_url || !$this->api_key)) {
            $this->enabled = 'no';
            $this->logger->log('Gateway disabled - missing API credentials', 'error');
            $this->logger->log('API URL: ' . ($this->api_url ? $this->api_url : 'MISSING'), 'error');
            $this->logger->log('API Key: ' . ($this->api_key ? 'SET' : 'MISSING'), 'error');
            add_action('admin_notices', array($this, 'admin_api_notice'));
        }
    

        
        // Initialize client, DB manager, payment handler
        try {
            $this->client = new Breez_API_Client(
                $this->api_url,
                $this->api_key
            );
            
            $this->db_manager = new Breez_DB_Manager();
            $this->payment_handler = new Breez_Payment_Handler($this->client, $this->db_manager);
            
            // Validate payment methods
            if ($this->enabled === 'yes') {
                if (empty($this->payment_methods)) {
                    $this->enabled = 'no';
                    $this->logger->log('Gateway disabled - no payment methods selected', 'error');
                    add_action('admin_notices', array($this, 'admin_payment_methods_notice'));
                } else {
                    // Register webhook endpoint
                    add_action('woocommerce_api_wc_gateway_breez', array($this, 'webhook_handler'));
                    
                    // Initialize webhook handler
                    $this->webhook_handler = new Breez_Webhook_Handler($this->client, $this->db_manager);
                }
            }
        } catch (Exception $e) {
            $this->enabled = 'no';
            $this->logger->log('Gateway initialization failed: ' . $e->getMessage(), 'error');
            add_action('admin_notices', array($this, 'admin_api_error_notice'));
        }
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        
        // Register webhook URL with Breez API when settings are saved
        add_action('woocommerce_settings_saved', array($this, 'register_webhook_url'));
        
        // Schedule payment status checks
        if (!wp_next_scheduled('breez_check_pending_payments')) {
            wp_schedule_event(time(), 'five_minutes', 'breez_check_pending_payments');
        }
        
        // WooCommerce Blocks integration is now registered in the main plugin file
        // for better control over load order
    }
    
    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = require BREEZ_WC_PLUGIN_DIR . 'includes/admin/breez-settings.php';
    }
    
    /**
     * Display admin notice for missing API credentials
     */
    public function admin_api_notice() {
        echo '<div class="error"><p>' .
             __('Breez Payment Gateway requires API URL and API Key to be configured. Please configure these in the gateway settings.', 'breez-woocommerce') .
             '</p></div>';
    }
    
    /**
     * Display admin notice for missing payment methods
     */
    public function admin_payment_methods_notice() {
        echo '<div class="error"><p>' .
             __('Breez Payment Gateway requires at least one payment method to be selected. Please configure payment methods in the gateway settings.', 'breez-woocommerce') .
             '</p></div>';
    }
    
    /**
     * Display admin notice for API initialization error
     */
    public function admin_api_error_notice() {
        echo '<div class="error"><p>' .
             __('Breez Payment Gateway encountered an error during initialization. Please check the logs for more details.', 'breez-woocommerce') .
             '</p></div>';
    }
    
    /**
     * Register webhook URL with Breez API
     */
    public function register_webhook_url() {
        if ('yes' !== $this->get_option('enabled')) {
            return;
        }

        try {
            $webhook_url = get_rest_url(null, 'breez-wc/v1/webhook');
            $this->logger->log("Attempting to register webhook URL: $webhook_url", 'debug');
            
            // Skip webhook registration in test mode
            if ($this->testmode) {
                $this->logger->log('Webhook registration skipped - test mode enabled', 'info');
                return;
            }
            
            $result = $this->client->register_webhook($webhook_url);
            if ($result) {
                $this->logger->log("Successfully registered webhook URL", 'info');
            } else {
                $this->logger->log("Failed to register webhook URL - webhooks may not be supported", 'warning');
            }
        } catch (Exception $e) {
            // Log error but don't block gateway activation
            $this->logger->log("Webhook registration failed: " . $e->getMessage(), 'error');
            
            // Only show admin notice if not a 404 (which likely means webhooks aren't supported)
            if (strpos($e->getMessage(), '404') === false) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible"><p>' .
                         __('Breez webhook registration failed. Real-time payment notifications may not work.', 'breez-woocommerce') .
                         ' ' . __('The system will fall back to periodic payment status checks.', 'breez-woocommerce') .
                         '</p></div>';
                });
            }
        }
    }

    /**
     * Handle webhook callback
     */
    public function webhook_handler() {
        if (!$this->webhook_handler) {
            $this->logger->log('Webhook handler not initialized', 'error');
            wp_send_json_error('Webhook handler not initialized');
            return;
        }
        
        $this->webhook_handler->process_webhook();
    }
    
    /**
     * Get available payment methods as array
     *
     * @return array
     */
    protected function get_available_payment_methods() {
        if (empty($this->payment_methods)) {
            return ['LIGHTNING']; // Default to LIGHTNING if nothing is set
        }
        
        if (is_array($this->payment_methods)) {
            return array_map(function($method) {
                return strtoupper(trim($method));
            }, $this->payment_methods);
        }
        
        return array_map(function($method) {
            return strtoupper(trim($method));
        }, explode(',', $this->payment_methods));
    }
    
    /**
     * Process the payment
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'breez-woocommerce'));
            }
            
            $this->logger->log("Processing payment for order #$order_id", 'debug');
            
            // Get payment method from request
            $payment_method = $this->get_payment_method_from_request();
            
            // Get order details
            $order_total = $order->get_total();
            $currency = $order->get_currency();
            
            // Get exchange rate
            $exchange_rate_response = $this->client->request('GET', "/exchange_rates/{$currency}");
            $this->logger->log("Exchange rate response: " . print_r($exchange_rate_response, true), 'debug');
            
            if (!$exchange_rate_response || !isset($exchange_rate_response['rate'])) {
                throw new Exception(__('Failed to get exchange rate', 'breez-woocommerce'));
            }
            
            // Calculate amount in satoshis
            // The exchange rate is in fiat/BTC, so we need to:
            // 1. Convert order total to BTC by dividing by the rate
            // 2. Convert BTC to satoshis
            $btc_amount = $order_total / $exchange_rate_response['rate'];
            $amount_sat = (int)round($btc_amount * 100000000);
            
            // Validate amount is within reasonable range (1000 to 100000000 sats)
            if ($amount_sat < 1000 || $amount_sat > 100000000) {
                throw new Exception(sprintf(
                    __('Invalid payment amount calculated: %d sats. Please check exchange rate calculation.', 'breez-woocommerce'),
                    $amount_sat
                ));
            }
            
            $this->logger->log("Exchange rate calculation:", 'debug');
            $this->logger->log("Order total ({$currency}): {$order_total}", 'debug');
            $this->logger->log("Exchange rate ({$currency}/BTC): {$exchange_rate_response['rate']}", 'debug');
            $this->logger->log("BTC amount: {$btc_amount}", 'debug');
            $this->logger->log("Final amount (sats): {$amount_sat}", 'debug');
            
            // Prepare payment data
            $payment_data = array(
                'amount' => $amount_sat,
                'method' => $payment_method,
                'description' => sprintf(__('Order #%s', 'breez-woocommerce'), $order->get_order_number()),
                'source' => 'woocommerce'
            );
            
            $this->logger->log("Creating payment with data: " . print_r($payment_data, true), 'debug');
            
            // Create the payment
            try {
                $response = $this->client->request('POST', '/receive_payment', $payment_data);
                $this->logger->log("Payment creation response: " . print_r($response, true), 'debug');
                
                if (!$response || !isset($response['destination'])) {
                    throw new Exception(__('Failed to create payment', 'breez-woocommerce'));
                }
                
                // Save payment in database
                $metadata = array(
                    'payment_method' => $payment_method,
                    'exchange_rate' => $exchange_rate_response['rate'],
                    'amount_sat' => $amount_sat,
                    'expires_at' => time() + ($this->expiry_minutes * 60)
                );
                
                $saved = $this->db_manager->save_payment(
                    $order_id,
                    $response['destination'],
                    $order_total,
                    $currency,
                    'pending',
                    $metadata
                );
                
                if (!$saved) {
                    throw new Exception(__('Failed to save payment in database', 'breez-woocommerce'));
                }
                
                // Set order status to pending
                $order->update_status('pending', __('Awaiting Bitcoin/Lightning payment', 'breez-woocommerce'));
                
                // Add order note
                $order->add_order_note(
                    sprintf(__('Breez payment created. Method: %s, ID: %s, Amount: %s sats', 'breez-woocommerce'),
                        $payment_method,
                        $response['destination'],
                        $amount_sat
                    )
                );
                
                // Save the order
                $order->save();
                $this->logger->log("Payment saved for order #$order_id", 'debug');
                
                // Reduce stock levels
                wc_reduce_stock_levels($order_id);
                
                // Empty cart
                WC()->cart->empty_cart();
                
                // Store payment URL in order meta
                $order->update_meta_data('_breez_payment_url', $response['destination']);
                $order->save();
                
                // Return success and redirect to order received page
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_order_received_url()
                );
                
            } catch (Exception $e) {
                $this->logger->log('Payment creation error: ' . $e->getMessage(), 'error');
                throw new Exception(__('Payment creation failed: ', 'breez-woocommerce') . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->logger->log('Payment processing error: ' . $e->getMessage(), 'error');
            $order->update_status('failed', __('Payment error: ', 'breez-woocommerce') . $e->getMessage());
            return $this->get_error_response($e->getMessage());
        }
    }
    
    /**
     * Get payment method from request
     * 
     * @return string
     */
    public function get_payment_method_from_request() {
        // Priority 1: Direct POST parameter for breez_payment_method (from blocks)
        if (isset($_POST['breez_payment_method'])) {
            $method = strtoupper(sanitize_text_field($_POST['breez_payment_method']));
            $this->logger->log("Payment method found in direct POST: $method", 'debug');
            return $method;
        }
        
        // Priority 2: Payment data from gateway form
        if (isset($_POST['payment_method']) && $_POST['payment_method'] === 'breez') {
            $this->logger->log('Processing checkout payment via standard form', 'debug');
            
            // Check for payment data
            if (isset($_POST['payment_data'])) {
                $raw_data = wp_unslash($_POST['payment_data']);
                $this->logger->log('Raw payment data: ' . print_r($raw_data, true), 'debug');
                
                // Handle nested payment data structure
                if (is_string($raw_data)) {
                    $decoded_data = json_decode($raw_data, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $raw_data = $decoded_data;
                    }
                }
                
                // Extract payment method
                if (is_array($raw_data) && isset($raw_data['breez_payment_method'])) {
                    $method = strtoupper(sanitize_text_field($raw_data['breez_payment_method']));
                    $this->logger->log("Payment method found in payment_data: $method", 'debug');
                    return $method;
                }
            }
        }
        
        // Priority 3: From WC Blocks checkout
        if (isset($_POST['wc-breez-payment-data'])) {
            $blocks_data = json_decode(wp_unslash($_POST['wc-breez-payment-data']), true);
            if (is_array($blocks_data) && isset($blocks_data['breez_payment_method'])) {
                $method = strtoupper(sanitize_text_field($blocks_data['breez_payment_method']));
                $this->logger->log("Payment method found in blocks data: $method", 'debug');
                return $method;
            }
        }
        
        // Default fallback
        $available_methods = $this->get_available_payment_methods();
        $default_method = strtoupper(reset($available_methods)); // Get first available method
        $this->logger->log("Using default payment method: $default_method", 'debug');
        return $default_method;
    }
    
    /**
     * Thank you page content
     *
     * @param int $order_id
     */
    public function thankyou_page($order_id) {
        $this->logger->log("Entering thankyou_page for order #$order_id", 'debug');
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->logger->log("Order #$order_id not found", 'error');
            return;
        }
        
        // Only display for our payment method
        if ($order->get_payment_method() !== $this->id) {
            $this->logger->log("Order #$order_id payment method is not breez: " . $order->get_payment_method(), 'debug');
            return;
        }
        
        // Get invoice ID
        $invoice_id = $order->get_meta('_breez_invoice_id');
        if (!$invoice_id) {
            $this->logger->log("No invoice ID found for order #$order_id", 'error');
            return;
        }
        
        $payment_method = $order->get_meta('_breez_payment_method');
        $expiry = $order->get_meta('_breez_payment_expires');
        $amount_sat = $order->get_meta('_breez_payment_amount_sat');
        $current_time = time();
        
        $this->logger->log("Payment details for order #$order_id:", 'debug');
        $this->logger->log("- Invoice ID: $invoice_id", 'debug');
        $this->logger->log("- Payment Method: $payment_method", 'debug');
        $this->logger->log("- Expiry: $expiry", 'debug');
        $this->logger->log("- Amount (sats): $amount_sat", 'debug');
        
        // Check if payment is expired
        if ($expiry && $current_time > $expiry) {
            $this->logger->log("Payment expired for order #$order_id", 'debug');
            $order->update_status('failed', __('Lightning payment expired', 'breez-woocommerce'));
            return;
        }
        
        // Check current payment status (API status is in UPPERCASE)
        $api_payment_status = 'PENDING';
        try {
            $status_response = $this->client->check_payment_status($invoice_id);
            $api_payment_status = $status_response['status'];
            $this->logger->log("Current API payment status for order #$order_id: $api_payment_status", 'debug');
        } catch (Exception $e) {
            $this->logger->log("Error checking payment status: " . $e->getMessage(), 'error');
        }
        
        // Update order status if needed (WooCommerce status is in lowercase)
        if (($api_payment_status === 'SUCCEEDED' || $api_payment_status === 'WAITING_CONFIRMATION') && $order->get_status() === 'pending') {
            $order->payment_complete();
            $order->add_order_note(__('Payment confirmed via Breez API.', 'breez-woocommerce'));
            $this->logger->log("Payment for order #$order_id confirmed via API check", 'debug');
        }
        
        // Only show payment instructions when order is still pending
        // and payment status is not SUCCEEDED or WAITING_CONFIRMATION
        if ($order->get_status() === 'pending' && 
            $api_payment_status !== 'SUCCEEDED' && 
            $api_payment_status !== 'WAITING_CONFIRMATION') {
            
            // Load the payment instructions template
            $template_path = BREEZ_WC_PLUGIN_DIR . 'templates/payment-instructions.php';
            $this->logger->log("Loading payment instructions template from: $template_path", 'debug');
            
            if (!file_exists($template_path)) {
                $this->logger->log("Template file not found!", 'error');
                return;
            }
            
            // Load the payment instructions template
            wc_get_template(
                'payment-instructions.php',
                array(
                    'order' => $order,
                    'invoice_id' => $invoice_id,
                    'payment_method' => $payment_method,
                    'expiry' => $expiry,
                    'current_time' => $current_time,
                    'payment_status' => 'PENDING' // Force pending status to show payment instructions
                ),
                '',
                BREEZ_WC_PLUGIN_DIR . 'templates/'
            );
        } else {
            // Payment is already complete, show success message
            wc_get_template(
                'payment-instructions.php',
                array(
                    'order' => $order,
                    'invoice_id' => $invoice_id,
                    'payment_method' => $payment_method,
                    'expiry' => $expiry,
                    'current_time' => $current_time,
                    'payment_status' => 'SUCCEEDED' // Force succeeded status to show success message
                ),
                '',
                BREEZ_WC_PLUGIN_DIR . 'templates/'
            );
        }
        
        $this->logger->log("Template loaded successfully for order #$order_id", 'debug');
    }
    
    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($sent_to_admin || $order->get_payment_method() !== $this->id || $order->has_status('processing')) {
            return;
        }
        
        $invoice_id = $order->get_meta('_breez_invoice_id');
        if (!$invoice_id) {
            return;
        }
        
        if ($plain_text) {
            echo "\n\n" . $this->instructions . "\n\n";
            echo __('Invoice/Address: ', 'breez-woocommerce') . $invoice_id . "\n";
        } else {
            echo '<h2>' . __('Payment Information', 'breez-woocommerce') . '</h2>';
            echo '<p>' . $this->instructions . '</p>';
            echo '<p><strong>' . __('Invoice/Address: ', 'breez-woocommerce') . '</strong> ' . $invoice_id . '</p>';
        }
    }
    
    /**
     * Check if this gateway is available
     *
     * @return bool
     */
    public function is_available() {
        // Get fresh settings from database
        $this->init_settings();
        $api_url = $this->get_option('api_url');
        $api_key = $this->get_option('api_key');
        $payment_methods = $this->get_option('payment_methods');
        
        $this->logger->log("Gateway availability check", 'debug');
        $this->logger->log("Settings from DB: api_url=" . ($api_url ? $api_url : 'MISSING') . 
                         ", api_key=" . ($api_key ? 'SET' : 'MISSING') . 
                         ", payment_methods=" . print_r($payment_methods, true), 'debug');
        
        if ($this->enabled === 'no') {
            $this->logger->log("Gateway disabled in settings", 'debug');
            return false;
        }
        
        // Check API credentials
        if (empty($api_url) || empty($api_key)) {
            $this->logger->log("API credentials missing - URL: " . (empty($api_url) ? 'No' : 'Yes') . 
                              ", Key: " . (empty($api_key) ? 'No' : 'Yes'), 'debug');
            return false;
        }
        
        // Update class variables with fresh settings
        $this->api_url = $api_url;
        $this->api_key = $api_key;
        $this->payment_methods = $payment_methods;
        
        // Check payment methods
        if (empty($this->payment_methods)) {
            $this->logger->log("No payment methods selected", 'debug');
            return false;
        }
        
        // Check payment handler
        if (!isset($this->payment_handler) || !$this->payment_handler) {
            $this->logger->log("Payment handler not initialized", 'debug');
            
            // Try to initialize payment handler on-demand
            try {
                if (!isset($this->client)) {
                    $this->client = new Breez_API_Client($this->api_url, $this->api_key);
                }
                if (!isset($this->db_manager)) {
                    $this->db_manager = new Breez_DB_Manager();
                }
                $this->payment_handler = new Breez_Payment_Handler($this->client, $this->db_manager);
            } catch (Exception $e) {
                $this->logger->log("Failed to initialize payment handler: " . $e->getMessage(), 'error');
                return false;
            }
        }
        
        // Check exchange rate
        try {
            $rate_response = $this->client->request('GET', '/exchange_rates/USD');
            if (!$rate_response || !isset($rate_response['rate'])) {
                $this->logger->log("Unable to get exchange rate", 'error');
                return false;
            }
            $this->logger->log("Exchange rate: " . $rate_response['rate'], 'debug');
        } catch (Exception $e) {
            $this->logger->log("Exchange rate error: " . $e->getMessage(), 'error');
            return false;
        }
        
        $this->logger->log("Gateway available", 'debug');
        return true;
    }
    

    /**
     * Process a refund if supported
     *
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     * @return bool
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->logger->log("Error: Order #$order_id not found for refund");
            return false;
        }
        
        $this->logger->log("Manual refund requested for order #$order_id, amount: $amount, reason: $reason");
        
        // For now, just log the request and notify that manual refund is required
        $order->add_order_note(
            sprintf(
                __('Refund of %s requested. Bitcoin/Lightning payments require manual refund processing. Reason: %s', 'breez-woocommerce'),
                wc_price($amount),
                $reason
            )
        );
        
        return true;
    }

    /**
     * Reduce stock levels
     *
     * @param int $order_id
     */
    public function reduce_stock_levels($order_id) {
        $order = wc_get_order($order_id);
        
        if ($order) {
            $this->logger->log("Reducing stock levels for order #{$order_id}", 'debug');
            wc_reduce_stock_levels($order_id);
            $this->logger->log("Stock levels reduced for order #{$order_id}", 'info');
        } else {
            $this->logger->log("Failed to reduce stock: Order #{$order_id} not found", 'error');
        }
    }

    /**
     * Get a standardized error response
     *
     * @param string $message Error message
     * @return array
     */
    protected function get_error_response($message = '') {
        $this->logger->log('Payment error: ' . $message, 'error');
        
        return array(
            'result' => 'failure',
            'redirect' => '',
            'message' => $message ? $message : __('An error occurred during the payment process.', 'breez-woocommerce'),
        );
    }

    /**
     * Display payment instructions on the payment page
     */
    public function payment_page($order_id) {
        $this->logger->log("Displaying payment page for order #$order_id", 'debug');
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->logger->log("Order not found: #$order_id", 'error');
            return;
        }
        
        // Get payment details from database
        $payment = $this->db_manager->get_payment_by_order($order_id);
        if (!$payment) {
            $this->logger->log("Payment not found for order #$order_id", 'error');
            return;
        }
        
        // Load the payment instructions template
        wc_get_template(
            'payment-instructions.php',
            array(
                'order' => $order,
                'invoice_id' => $payment['invoice_id'],
                'payment_method' => $payment['metadata']['payment_method'],
                'expiry' => $payment['metadata']['expires_at'],
                'current_time' => time(),
                'payment_status' => $payment['status']
            ),
            '',
            BREEZ_WC_PLUGIN_DIR . 'templates/'
        );
    }
}
