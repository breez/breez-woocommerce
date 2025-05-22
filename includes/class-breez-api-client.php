<?php
/**
 * Breez API Client
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez API Client Class
 *
 * Handles communication with the Breez API.
 */
class Breez_API_Client {
    /**
     * API URL
     *
     * @var string
     */
    private $api_url;
    
    /**
     * API Key
     *
     * @var string
     */
    private $api_key;
    
    /**
     * Logger instance
     *
     * @var Breez_Logger
     */
    private $logger;
    
    /**
     * Constructor
     *
     * @param string $api_url API URL
     * @param string $api_key API Key
     */
    public function __construct($api_url, $api_key) {
        $this->api_url = rtrim($api_url, '/');
        $this->api_key = $api_key;
        $this->logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
        
        if (!$this->api_url || !$this->api_key) {
            $this->logger->log('API client initialized without credentials', 'error');
            throw new Exception('API credentials are required');
        }
        
        // Validate API URL format
        if (!filter_var($this->api_url, FILTER_VALIDATE_URL)) {
            $this->logger->log('Invalid API URL format: ' . $this->api_url, 'error');
            throw new Exception('API URL must be a valid URL');
        }
        
        $this->logger->log('API client initialized', 'debug', array(
            'api_url' => $this->api_url,
            'api_key_set' => !empty($this->api_key)
        ));
    }
    
    /**
     * Check API connectivity
     *
     * @return bool True if API is accessible, false otherwise
     */
    public function check_health() {
        // In test mode, bypass the health check
        if ('yes' === get_option('woocommerce_breez_testmode', 'no')) {
            $this->logger->log('API health check bypassed in test mode', 'debug');
            return true;
        }
        try {
            $this->logger->log('Starting API health check', 'debug');
            $response = $this->request('GET', '/health');
            $this->logger->log('API health check response: ' . json_encode($response), 'debug');
            return isset($response['status']) && $response['status'] === 'ok';
        } catch (Exception $e) {
            $this->logger->log('API health check failed: ' . $e->getMessage(), 'error');
            $this->logger->log('Stack trace: ' . $e->getTraceAsString(), 'debug');
            return false;
        }
    }
    
    /**
     * Generate a payment request
     *
     * @param int $amount Amount in satoshis
     * @param string $method Payment method (LIGHTNING, BITCOIN_ADDRESS, LIQUID_ADDRESS)
     * @param string $description Payment description
     * @return array|false Payment details on success, false on failure
     */
    public function receive_payment($amount, $method = 'LIGHTNING', $description = '') {
        $data = array(
            'amount' => $amount,
            'method' => $method,
            'description' => $description
        );
        
        return $this->request('POST', '/receive_payment', $data);
    }
    
    /**
     * Check payment status using API endpoint
     *
     * @param string $invoice_id Invoice ID or payment identifier
     * @return array Payment status response
     */
    public function check_payment_status($invoice_id) {
        try {
            $response = $this->request('GET', "/check_payment_status/{$invoice_id}");
            
            if (!$response) {
                throw new Exception('Failed to check payment status');
            }

            // Log the raw API response for debugging
            $this->logger->log('Payment status check response', 'debug', array(
                'invoice_id' => $invoice_id,
                'response' => $response
            ));

            // If the payment is not found, return pending instead of throwing an error
            if ($response['status'] === 'UNKNOWN') {
                return array(
                    'status' => 'PENDING',
                    'destination' => $invoice_id,
                    'sdk_status' => 'UNKNOWN'
                );
            }

            // Build response with all available details - keep original SDK status
            $result = array(
                'status' => $response['status'], // Keep original SDK status (SUCCEEDED, WAITING_CONFIRMATION, etc)
                'sdk_status' => $response['status'],
                'destination' => $invoice_id,
                'amount_sat' => $response['amount_sat'] ?? null,
                'fees_sat' => $response['fees_sat'] ?? null,
                'timestamp' => $response['timestamp'] ?? null,
                'error' => $response['error'] ?? null
            );

            // Include payment details if available
            if (isset($response['payment_details'])) {
                $result['payment_details'] = $response['payment_details'];
            }

            // Add human-readable status description
            $result['status_description'] = $this->get_status_description($response['status']);

            return $result;

        } catch (Exception $e) {
            $this->logger->log('Payment status check error: ' . $e->getMessage(), 'error');
            // Return pending status instead of throwing an error
            return array(
                'status' => 'PENDING',
                'sdk_status' => 'UNKNOWN',
                'destination' => $invoice_id,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Map SDK payment states to WooCommerce payment states
     *
     * @param string $sdk_status The status from the SDK
     * @return string WooCommerce payment status
     */
    private function map_payment_status($sdk_status) {
        switch ($sdk_status) {
            case 'SUCCEEDED':
            case 'WAITING_CONFIRMATION': // Consider payment complete when claim tx is broadcast
                return 'completed';
                
            case 'PENDING': // Lockup transaction broadcast
                return 'pending';
                
            case 'WAITING_FEE_ACCEPTANCE': // Needs fee approval
                return 'pending';
                
            case 'FAILED': // Swap failed (expired or lockup tx failed)
                return 'failed';
                
            case 'UNKNOWN': // Payment not found or error
            default:
                return 'pending';
        }
    }
    
    /**
     * Get human-readable status description
     *
     * @param string $sdk_status The SDK status
     * @return string Human-readable description
     */
    private function get_status_description($sdk_status) {
        switch ($sdk_status) {
            case 'SUCCEEDED':
                return __('Payment confirmed and completed.', 'breez-woocommerce');
            case 'WAITING_CONFIRMATION':
                return __('Payment received and being confirmed.', 'breez-woocommerce');
            case 'PENDING':
                return __('Payment initiated, waiting for completion.', 'breez-woocommerce');
            case 'WAITING_FEE_ACCEPTANCE':
                return __('Waiting for fee approval.', 'breez-woocommerce');
            case 'FAILED':
                return __('Payment failed or expired.', 'breez-woocommerce');
            case 'UNKNOWN':
            default:
                return __('Payment status unknown.', 'breez-woocommerce');
        }
    }
    
    /**
     * Register a webhook URL
     *
     * @param string $webhook_url Webhook URL
     * @return array|false Response on success, false on failure
     */
    /**
     * Check if webhooks are supported by the API
     *
     * @return bool True if webhooks are supported
     */
    public function check_webhook_support() {
        try {
            // Try to get API endpoints/capabilities
            $response = $this->request('GET', '/capabilities', array(), 1);
            
            // If capabilities endpoint exists, check webhook support
            if ($response && isset($response['features'])) {
                return in_array('webhooks', $response['features']);
            }
            
            // If capabilities endpoint doesn't exist, try webhook endpoint directly
            $this->request('GET', '/register_webhook', array(), 1);
            return true;
            
        } catch (Exception $e) {
            // If we get a 404, webhooks aren't supported
            if (strpos($e->getMessage(), '404') !== false) {
                $this->logger->log('Webhooks not supported by API', 'debug');
                return false;
            }
            
            // For other errors, assume webhooks might be supported
            $this->logger->log('Webhook support check failed: ' . $e->getMessage(), 'warning');
            return true;
        }
    }
    
    /**
     * Register a webhook URL
     *
     * @param string $webhook_url Webhook URL
     * @return bool True if registration successful
     * @throws Exception if registration fails
     */
    public function register_webhook($webhook_url) {
        if (!$webhook_url) {
            throw new Exception('Webhook URL is required');
        }
        
        $this->logger->log('Registering webhook', 'debug', array(
            'url' => $webhook_url
        ));
        
        try {
            $data = array(
                'webhook_url' => $webhook_url
            );
            
            $response = $this->request('POST', '/register_webhook', $data, 1);
            
            if ($response && isset($response['success']) && $response['success']) {
                $this->logger->log('Webhook registration successful', 'info');
                return true;
            }
            
            $this->logger->log('Webhook registration failed - invalid response', 'error');
            return false;
            
        } catch (Exception $e) {
            // If we get a 404, webhooks aren't supported
            if (strpos($e->getMessage(), '404') !== false) {
                $this->logger->log('Webhook registration failed - endpoint not found', 'debug');
                return false;
            }
            
            // Re-throw other errors
            throw $e;
        }
    }
    
    /**
     * Get payment by ID
     *
     * @param string $payment_hash Payment hash
     * @return array|false Payment details on success, false on failure
     */
    public function get_payment($payment_hash) {
        return $this->request('GET', "/payment/{$payment_hash}");
    }
    
    /**
     * List all payments
     *
     * @param array $params Optional query parameters
     * @return array|false List of payments on success, false on failure
     */
    public function list_payments($params = array()) {
        $query_string = '';
        if (!empty($params)) {
            $query_string = '?' . http_build_query($params);
        }
        
        return $this->request('GET', "/list_payments{$query_string}");
    }
    
    /**
     * Make API request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param int $max_retries Maximum number of retries
     * @return array|false Response data on success, false on failure
     */
    public function request($method, $endpoint, $data = array(), $max_retries = 2) {
        // Normalize the endpoint to ensure it starts with a slash
        $endpoint = ltrim($endpoint, '/');
        
        // Full API URL
        $url = $this->api_url . '/' . $endpoint;
        
        $this->logger->log('Making API request', 'debug', array(
            'method' => $method,
            'url' => $url
        ));
        
        $args = array(
            'method'    => $method,
            'timeout'   => 30,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'x-api-key'     => $this->api_key,
                'Accept'        => 'application/json'
            )
        );
        
        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        } elseif (!empty($data) && $method === 'GET') {
            // For GET requests, append query string
            $url = add_query_arg($data, $url);
        }
        
        $retries = 0;
        $response_data = false;
        
        while ($retries <= $max_retries) {
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->log('API request error', 'error', array(
                    'message' => $error_message,
                    'attempt' => $retries + 1
                ));
                
                $retries++;
                if ($retries <= $max_retries) {
                    $this->logger->log('Retrying request', 'debug', array(
                        'attempt' => $retries
                    ));
                    // Exponential backoff
                    sleep(pow(2, $retries - 1));
                    continue;
                }
                
                throw new Exception('API request failed: ' . $error_message);
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            $this->logger->log('API response received', 'debug', array(
                'http_code' => $http_code,
                'body_length' => strlen($body)
            ));
            
            if ($http_code >= 200 && $http_code < 300) {
                if (!empty($body)) {
                    $response_data = json_decode($body, true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->log('JSON decode error', 'error', array(
                            'error' => json_last_error_msg(),
                            'body_excerpt' => substr($body, 0, 100) . (strlen($body) > 100 ? '...' : '')
                        ));
                        
                        // If we can't decode JSON, try to return the raw body
                        $response_data = array(
                            'raw_response' => $body
                        );
                    }
                } else {
                    // Empty but successful response
                    $response_data = array(
                        'success' => true
                    );
                }
                
                // Success - break out of retry loop
                break;
            } else {
                // Handle error
                $message = $body;
                
                if (!empty($body)) {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded) && isset($decoded['message'])) {
                        $message = $decoded['message'];
                    } elseif (is_array($decoded) && isset($decoded['error'])) {
                        $message = $decoded['error'];
                    }
                }
                
                // 404 might be normal in some cases (checking if endpoint exists)
                $error_level = ($http_code == 404) ? 'debug' : 'error';
                $this->logger->log('API error response', $error_level, array(
                    'http_code' => $http_code,
                    'message' => $message,
                    'endpoint' => $endpoint,
                    'attempt' => $retries + 1
                ));
                
                if ($http_code == 404 || ($http_code >= 500 && $retries < $max_retries)) {
                    $retries++;
                    if ($retries <= $max_retries) {
                        $this->logger->log('Retrying request', 'debug', array(
                            'attempt' => $retries
                        ));
                        // Exponential backoff
                        sleep(pow(2, $retries - 1));
                        continue;
                    }
                }
                
                throw new Exception("API error ($http_code): $message");
            }
        }
        
        return $response_data;
    }

    /**
     * Create a new payment
     *
     * @param array $payment_data Payment data including amount, currency, description, etc.
     * @return array Payment details
     * @throws Exception if payment creation fails
     */
    public function create_payment($payment_data) {
        $this->logger->log('Creating payment with data: ' . print_r($payment_data, true), 'debug');
        
        try {
            // Prepare the API request data according to ReceivePaymentBody schema
            $api_data = array(
                'amount' => $payment_data['amount_sat'], // Amount must be in satoshis
                'method' => strtoupper($payment_data['payment_method']), // LIGHTNING or BITCOIN_ADDRESS
                'description' => $payment_data['description'] ?? '',
            );
            
            // Make the API request to create payment
            $response = $this->request('POST', '/receive_payment', $api_data);
            
            if (!$response || !isset($response['destination'])) {
                throw new Exception('Invalid API response: Missing payment destination');
            }
            
            // Format the response to match what the gateway expects
            return array(
                'id' => $response['destination'], // Use destination as ID
                'invoice_id' => $response['destination'],
                'payment_url' => $response['destination'], // For QR code generation
                'payment_request' => $response['destination'],
                'status' => 'PENDING',
                'amount' => $payment_data['amount'],
                'amount_sat' => $payment_data['amount_sat'],
                'currency' => $payment_data['currency'],
                'created_at' => time(),
                'expires_at' => time() + ($payment_data['expires_in'] ?? 1800),
                'fees_sat' => $response['fees_sat'] ?? 0
            );
            
        } catch (Exception $e) {
            $this->logger->log('Payment creation failed: ' . $e->getMessage(), 'error');
            throw new Exception('Failed to create payment: ' . $e->getMessage());
        }
    }
    
    /**
     * Convert fiat amount to satoshis
     *
     * @param float $amount Amount in fiat
     * @param string $currency Currency code
     * @return int Amount in satoshis
     * @throws Exception if conversion fails
     */
    private function convert_to_sats($amount, $currency) {
        try {
            // Try to get the exchange rate from the API
            $response = $this->request('GET', '/exchange_rates/' . strtoupper($currency));
            
            if (!$response || !isset($response['rate'])) {
                throw new Exception('Invalid exchange rate response');
            }
            
            // Calculate satoshis
            $btc_amount = $amount / $response['rate'];
            return (int)($btc_amount * 100000000); // Convert BTC to sats
            
        } catch (Exception $e) {
            $this->logger->log('Currency conversion failed: ' . $e->getMessage(), 'error');
            throw new Exception('Failed to convert currency: ' . $e->getMessage());
        }
    }
}
