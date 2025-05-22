<?php
/**
 * Breez Payment Handler
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez Payment Handler Class
 *
 * Handles payment processing and status checks.
 */
class Breez_Payment_Handler {
    /**
     * API Client instance
     *
     * @var Breez_API_Client
     */
    private $client;
    
    /**
     * DB Manager instance
     *
     * @var Breez_DB_Manager
     */
    private $db_manager;
    
    /**
     * Logger instance
     *
     * @var Breez_Logger
     */
    private $logger;
    
    /**
     * Constructor
     *
     * @param Breez_API_Client $client API Client instance
     * @param Breez_DB_Manager $db_manager DB Manager instance
     */
    public function __construct($client = null, $db_manager = null) {
        $this->logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
        $this->logger->log('Initializing payment handler', 'debug');
        
        try {
            // If no client is provided, create one
            if (!$client) {
                $api_url = get_option('woocommerce_breez_api_url');
                $api_key = get_option('woocommerce_breez_api_key');
                
                if (!$api_url || !$api_key) {
                    throw new Exception('API credentials not configured');
                }
                
                $this->client = new Breez_API_Client($api_url, $api_key);
            } else {
                $this->client = $client;
            }
            
            // Verify API connectivity
            if (!$this->client->check_health()) {
                throw new Exception('API health check failed');
            }
            
            // If no db_manager is provided, create one
            if (!$db_manager) {
                $this->db_manager = new Breez_DB_Manager();
            } else {
                $this->db_manager = $db_manager;
            }
            
            $this->logger->log('Payment handler initialized successfully', 'debug');
            
        } catch (Exception $e) {
            $this->logger->log('Payment handler initialization failed: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Convert fiat amount to satoshis
     *
     * @param float $amount Fiat amount
     * @param string $currency Currency code (default: store currency)
     * @return int Amount in satoshis
     */
    public function convert_to_satoshis($amount, $currency = '') {
        try {
            if (!$currency) {
                $currency = get_woocommerce_currency();
            }
            
            if ($amount <= 0) {
                throw new Exception('Invalid amount: must be greater than 0');
            }
            
            $start_time = microtime(true);
            
            // Get exchange rate from BTC to currency
            $btc_rate = $this->get_btc_rate($currency);
            
            if ($btc_rate <= 0) {
                throw new Exception("Invalid exchange rate for $currency");
            }
            
            // Convert amount to BTC
            $btc_amount = $amount / $btc_rate;
            
            // Convert BTC to satoshis (1 BTC = 100,000,000 satoshis)
            $satoshis = round($btc_amount * 100000000);
            
            $duration = round(microtime(true) - $start_time, 3);
            
            $this->logger->log('Currency conversion completed', 'debug', array(
                'amount' => $amount,
                'currency' => $currency,
                'btc_rate' => $btc_rate,
                'satoshis' => $satoshis,
                'duration' => $duration
            ));
            
            return $satoshis;
            
        } catch (Exception $e) {
            $this->logger->log('Currency conversion failed: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Get Bitcoin exchange rate for currency
     *
     * In a real implementation, this would call an exchange rate API
     *
     * @param string $currency Currency code
     * @return float Exchange rate (1 BTC = X currency)
     */
    public function get_btc_rate($currency = '') {
        if (!$currency) {
            $currency = get_woocommerce_currency();
        }
        
        // Try to get from transient cache first (valid for 10 minutes)
        $cached_rate = get_transient('breez_btc_' . strtolower($currency) . '_rate');
        if ($cached_rate !== false) {
            $this->logger->log('Using cached exchange rate', 'debug', array(
                'currency' => $currency,
                'rate' => $cached_rate
            ));
            return $cached_rate;
        }
        
        try {
            // Get rate from CoinGecko API
            $response = wp_remote_get(
                'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=' . strtolower($currency)
            );
            
            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch exchange rate: ' . $response->get_error_message());
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!isset($body['bitcoin'][strtolower($currency)])) {
                throw new Exception('Invalid response from exchange rate API');
            }
            
            $rate = floatval($body['bitcoin'][strtolower($currency)]);
            
            // Add exchange rate buffer if configured
            $buffer_percent = floatval(get_option('woocommerce_breez_exchange_rate_buffer', 1.0));
            if ($buffer_percent > 0) {
                $rate = $rate * (1 + ($buffer_percent / 100));
            }
            
            // Cache for 10 minutes
            set_transient('breez_btc_' . strtolower($currency) . '_rate', $rate, 10 * MINUTE_IN_SECONDS);
            
            $this->logger->log('Fetched new exchange rate', 'debug', array(
                'currency' => $currency,
                'rate' => $rate,
                'buffer' => $buffer_percent . '%'
            ));
            
            return $rate;
            
        } catch (Exception $e) {
            $this->logger->log('Exchange rate fetch failed: ' . $e->getMessage(), 'error');
            
            // Fallback rates if API fails
            $default_rates = array(
                'USD' => 50000.00,
                'EUR' => 45000.00,
                'GBP' => 40000.00,
            );
            
            $rate = isset($default_rates[$currency]) ? $default_rates[$currency] : $default_rates['USD'];
            
            $this->logger->log('Using fallback exchange rate', 'warning', array(
                'currency' => $currency,
                'rate' => $rate
            ));
            
            return $rate;
        }
    }
    
    /**
     * Check payment status
     * 
     * Payment states from SDK are mapped to WooCommerce states as follows:
     * - SUCCEEDED -> completed (claim tx confirmed)
     * - WAITING_CONFIRMATION -> completed (claim tx broadcast but not confirmed)
     * - PENDING -> pending (lockup tx broadcast)
     * - WAITING_FEE_ACCEPTANCE -> pending (needs fee approval)
     * - FAILED -> failed (expired or lockup tx failed)
     * - UNKNOWN -> pending (not found or error)
     *
     * Note: WAITING_CONFIRMATION is considered completed because the claim transaction
     * has been broadcast or a direct Liquid transaction has been seen, making the
     * payment effectively irreversible at this point.
     *
     * @param string $invoice_id Invoice ID
     * @return string Payment status (pending, completed, failed)
     */
    public function check_payment_status($invoice_id) {
        try {
            $start_time = microtime(true);
            $this->logger->log("Checking payment status", 'debug', array('invoice_id' => $invoice_id));
            
            if (!$invoice_id) {
                throw new Exception('Invalid invoice ID');
            }
            
            // First check the local database
            $payment = $this->db_manager->get_payment_by_invoice($invoice_id);
            
            if ($payment && $payment['status'] === 'completed') {
                $this->logger->log('Using cached completed payment status', 'debug', array(
                    'invoice_id' => $invoice_id,
                    'status' => $payment['status']
                ));
                return $payment['status']; // Only return cached status if completed
            }
            
            // Check with API
            $response = $this->client->check_payment_status($invoice_id);
            $status = $response['status'];
            
            // Log detailed payment state information
            $this->logger->log('Payment status details', 'debug', array(
                'invoice_id' => $invoice_id,
                'status' => $status,
                'sdk_status' => $response['payment_details']['status'] ?? 'unknown',
                'amount_sat' => $response['amount_sat'],
                'timestamp' => $response['timestamp'],
                'error' => $response['error']
            ));

            // Update database if status has changed and we have payment data
            if ($payment && $status !== $payment['status']) {
                $this->db_manager->update_payment_status($payment['order_id'], $status);
                
                $this->logger->log('Payment status updated', 'info', array(
                    'invoice_id' => $invoice_id,
                    'old_status' => $payment['status'],
                    'new_status' => $status,
                    'order_id' => $payment['order_id']
                ));

                // Process status changes
                if ($status === 'completed') {
                    $this->process_successful_payment($invoice_id);
                } else if ($status === 'failed') {
                    $this->process_failed_payment($invoice_id);
                }
            }
            
            $duration = round(microtime(true) - $start_time, 3);
            $this->logger->log('Payment status check completed', 'debug', array(
                'invoice_id' => $invoice_id,
                'status' => $status,
                'duration' => $duration
            ));
            
            return $status;
            
        } catch (Exception $e) {
            $this->logger->log('Payment status check failed: ' . $e->getMessage(), 'error', array(
                'invoice_id' => $invoice_id
            ));
            throw $e;
        }
    }
    
    /**
     * Process successful payment
     *
     * @param string $invoice_id Invoice ID
     * @return bool Success/failure
     */
    public function process_successful_payment($invoice_id) {
        $payment = $this->db_manager->get_payment_by_invoice($invoice_id);
        
        if (!$payment) {
            $this->logger->log("No payment found for invoice $invoice_id");
            return false;
        }
        
        $order_id = $payment['order_id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->logger->log("No order found for order ID $order_id");
            return false;
        }
        
        // Update payment status in database
        $this->db_manager->update_payment_status($order_id, 'completed');
        
        // Update order status if not already completed
        if (!$order->has_status(array('processing', 'completed'))) {
            $order->payment_complete($invoice_id);
            $order->add_order_note(__('Payment completed via Breez.', 'breez-woocommerce'));
            $this->logger->log("Payment completed for order #$order_id (invoice: $invoice_id)");
        }
        
        return true;
    }
    
    /**
     * Process failed payment
     *
     * @param string $invoice_id Invoice ID
     * @return bool Success/failure
     */
    public function process_failed_payment($invoice_id) {
        $payment = $this->db_manager->get_payment_by_invoice($invoice_id);
        
        if (!$payment) {
            $this->logger->log("No payment found for invoice $invoice_id");
            return false;
        }
        
        $order_id = $payment['order_id'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->logger->log("No order found for order ID $order_id");
            return false;
        }
        
        // Update payment status in database
        $this->db_manager->update_payment_status($order_id, 'failed');
        
        // Update order status if still pending
        if ($order->has_status('pending')) {
            $order->update_status('failed', __('Payment failed or expired.', 'breez-woocommerce'));
            $this->logger->log("Payment failed for order #$order_id (invoice: $invoice_id)");
        }
        
        return true;
    }
    
    /**
     * Check pending payments (called by cron job)
     */
    public function check_pending_payments() {
        $this->logger->log("Checking pending payments...");
        
        // Get pending payments that are at least 2 minutes old (to avoid race conditions)
        // but less than the expiry time (default: 60 minutes)
        $pending_payments = $this->db_manager->get_pending_payments(2, 60);
        
        if (empty($pending_payments)) {
            $this->logger->log("No pending payments to check");
            return;
        }
        
        $this->logger->log("Found " . count($pending_payments) . " pending payments to check");
        
        foreach ($pending_payments as $payment) {
            $invoice_id = $payment['invoice_id'];
            $order_id = $payment['order_id'];
            
            $this->logger->log("Checking payment status for invoice $invoice_id (order #$order_id)");
            
            // Check payment status with API
            $status = $this->client->check_payment_status($invoice_id);
            
            if ($status === 'completed') {
                $this->process_successful_payment($invoice_id);
            } elseif ($status === 'failed') {
                $this->process_failed_payment($invoice_id);
            } else {
                // Check if payment is expired
                $order = wc_get_order($order_id);
                
                if ($order) {
                    $expiry = $order->get_meta('_breez_payment_expiry');
                    $current_time = time();
                    
                    if ($expiry && $current_time > $expiry) {
                        $this->logger->log("Payment expired for order #$order_id (invoice: $invoice_id)");
                        $this->process_failed_payment($invoice_id);
                    }
                }
            }
        }
        
        $this->logger->log("Finished checking pending payments");
    }
}
