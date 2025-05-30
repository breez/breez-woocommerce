<?php
/**
 * Plugin Name: Lightning Payments for WooCommerce
 * Plugin URI: https://github.com/breez-woocommerce
 * Description: Accept Bitcoin via the Lightning Network using the Breez SDK.
 * Version: 1.0.0
 * Author: Breez
 * Author URI: https://breez.technology
 * Text Domain: breez-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.0
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('BREEZ_WC_VERSION', '1.0.0');
define('BREEZ_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BREEZ_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Register custom cron schedule for every 5 minutes
 */
add_filter('cron_schedules', function(array $schedules): array {
    $schedules['five_minutes'] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display'  => __('Every 5 Minutes'),
    ];
    return $schedules;
});

/**
 * Display notice if WooCommerce is not installed
 */
function breez_wc_woocommerce_missing_notice() {
    $install_url = wp_nonce_url(
        add_query_arg(
            [
                'action' => 'install-plugin',
                'plugin' => 'woocommerce',
            ],
            admin_url('update.php')
        ),
        'install-plugin_woocommerce'
    );

    printf(
        '<div class="error"><p>%s</p></div>',
        wp_kses_post(
            sprintf(
                '%1$sBreez Nodeless Payments%2$s requires the %3$sWooCommerce plugin%4$s to be active. Please %5$sinstall & activate WooCommerce &raquo;%6$s',
                '<strong>',
                '</strong>',
                '<a href="https://wordpress.org/plugins/woocommerce/">',
                '</a>',
                '<a href="' . esc_url($install_url) . '">',
                '</a>'
            )
        )
    );
}

/**
 * Check if WooCommerce is active
 */
function breez_wc_is_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());
    
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}

/**
 * Initialize the plugin
 */
function breez_wc_init() {
    // Check if WooCommerce is fully loaded
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    try {
        // Initialize logger first
        require_once BREEZ_WC_PLUGIN_DIR . 'includes/class-breez-logger.php';
        $logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
        $logger->log('Initializing Breez WooCommerce plugin', 'debug');
        
        // Load plugin textdomain
        load_plugin_textdomain('breez-woocommerce', false, basename(dirname(__FILE__)) . '/languages');
        
        // Include required files in specific order
        $required_files = array(
            'includes/class-breez-api-client.php',
            'includes/class-breez-db-manager.php',
            'includes/class-breez-logger.php',
            'includes/class-breez-webhook-handler.php',
            'includes/class-breez-payment-handler.php',
            'class-wc-gateway-breez.php'
        );
        
        foreach ($required_files as $file) {
            $full_path = BREEZ_WC_PLUGIN_DIR . $file;
            if (!file_exists($full_path)) {
                throw new Exception("Required file not found: $file");
            }
            require_once $full_path;
        }
        
        // Add hooks for payment instructions on order received page
        add_action('woocommerce_thankyou_breez', 'breez_wc_thankyou_payment_instructions', 10);
        add_action('woocommerce_order_details_after_order_table', 'breez_wc_order_received_payment_instructions', 10);
        
        // Verify WooCommerce settings
        if (!get_option('woocommerce_currency')) {
            throw new Exception('WooCommerce currency not configured');
        }
        
        // Register payment gateway
        add_filter('woocommerce_payment_gateways', 'breez_wc_add_gateway');
        
        // Add settings link on plugin page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'breez_wc_settings_link');
        
        // Register webhook handler
        add_action('rest_api_init', 'breez_wc_register_webhook_endpoint');
        
        // Schedule payment status check
        if (!wp_next_scheduled('breez_wc_check_pending_payments')) {
            wp_schedule_event(time(), 'five_minutes', 'breez_wc_check_pending_payments');
            $logger->log('Scheduled payment status check', 'debug');
        }
        
        // Check API credentials
        $breez_settings = get_option('woocommerce_breez_settings', array());
        $api_url = isset($breez_settings['api_url']) ? $breez_settings['api_url'] : '';
        $api_key = isset($breez_settings['api_key']) ? $breez_settings['api_key'] : '';
        
        if (!$api_url || !$api_key) {
            $logger->log('API credentials not configured', 'warning');
            $logger->log('Settings: ' . print_r($breez_settings, true), 'debug');
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible"><p>' .
                     __('Breez Nodeless Payments requires API credentials to be configured.', 'breez-woocommerce') .
                     ' <a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=breez') . '">' .
                     __('Configure Now', 'breez-woocommerce') . '</a></p></div>';
            });
        } else {
            // Test API connection
            try {
                $client = new Breez_API_Client($api_url, $api_key);
                if (!$client->check_health()) {
                    throw new Exception('API health check failed');
                }
                $logger->log('API connection verified', 'debug');
            } catch (Exception $e) {
                $logger->log('API connection test failed: ' . $e->getMessage(), 'error');
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>' .
                         __('Breez Nodeless Payments could not connect to the API. Please check your settings.', 'breez-woocommerce') .
                         '</p></div>';
                });
            }
        }
        
        $logger->log('Plugin initialization completed', 'debug');
        
    } catch (Exception $e) {
        if (isset($logger)) {
            $logger->log('Plugin initialization failed: ' . $e->getMessage(), 'error');
        }
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error is-dismissible"><p>' .
                 __('Breez Nodeless Payments initialization failed: ', 'breez-woocommerce') .
                 esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

/**
 * Add the Breez gateway to WooCommerce
 */
function breez_wc_add_gateway($gateways) {
    if (class_exists('Breez_Logger')) {
        $logger = new Breez_Logger(true);
        $logger->log("Adding Breez gateway to WooCommerce", 'debug');
    }
    
    if (class_exists('WC_Gateway_Breez')) {
        $gateways[] = 'WC_Gateway_Breez';
        if (class_exists('Breez_Logger')) {
            $logger->log("Breez gateway class added successfully", 'debug');
        }
        return $gateways;
    }
    
    if (class_exists('Breez_Logger')) {
        $logger->log("WC_Gateway_Breez class not found", 'error');
    }
    return $gateways;
}

/**
 * Add settings link on plugin page
 */
function breez_wc_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=breez">' . __('Settings', 'breez-woocommerce') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Register webhook endpoint
 */
function breez_wc_register_webhook_endpoint() {
    register_rest_route('breez-wc/v1', '/webhook', array(
        'methods' => 'POST',
        'callback' => array('Breez_Webhook_Handler', 'process_webhook'),
        'permission_callback' => array('Breez_Webhook_Handler', 'validate_webhook'),
    ));

    // Register payment status check endpoint
    register_rest_route('breez-wc/v1', '/check-payment-status', array(
        'methods' => ['GET', 'POST'],
        'callback' => 'breez_wc_check_payment_status_endpoint',
        'permission_callback' => '__return_true',
        'args' => array(
            'order_id' => array(
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
    
    // Register direct payment status check endpoint with invoice ID
    register_rest_route('breez-wc/v1', '/check-payment-status/(?P<invoice_id>[\w-]+)', array(
        'methods' => 'GET',
        'callback' => 'breez_wc_check_payment_status_by_invoice_endpoint',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Direct payment status check endpoint handler (with invoice ID in URL)
 */
function breez_wc_check_payment_status_by_invoice_endpoint($request) {
    try {
        // Initialize logger
        $logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
        $logger->log('Direct payment status check endpoint called', 'debug');

        $invoice_id = $request->get_param('invoice_id');
        if (empty($invoice_id)) {
            $logger->log('No invoice ID provided', 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invoice ID is required'
            ), 400);
        }

        $logger->log("Checking payment status for invoice ID: $invoice_id", 'debug');

        // Initialize DB manager to get payment details
        $db_manager = new Breez_DB_Manager();
        $payment = $db_manager->get_payment_by_invoice($invoice_id);
        
        if (!$payment) {
            $logger->log("No payment found for invoice ID: $invoice_id", 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payment not found',
                'status' => 'UNKNOWN'
            ), 404);
        }

        // Initialize API client - directly fetch gateway settings
        $gateway_settings = get_option('woocommerce_breez_settings');
        $api_url = isset($gateway_settings['api_url']) ? $gateway_settings['api_url'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        $logger->log("API settings: URL=" . (!empty($api_url) ? 'Set' : 'Missing') . 
                   ", Key=" . (!empty($api_key) ? 'Set' : 'Missing'), 'debug');
        
        if (empty($api_url) || empty($api_key)) {
            $logger->log("API credentials not configured", 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payment gateway not properly configured'
            ), 500);
        }

        $client = new Breez_API_Client($api_url, $api_key);
        $payment_status = $client->check_payment_status($invoice_id);
        $logger->log("Payment status for invoice ID $invoice_id: " . print_r($payment_status, true), 'debug');

        // Update payment status in database if it has changed
        if ($payment_status['status'] !== $payment['status']) {
            $db_manager->update_payment_status($payment['order_id'], $payment_status['status']);
            
            // Get the order
            $order = wc_get_order($payment['order_id']);
            if ($order) {
                // Update order status if needed
                if ($payment_status['status'] === 'SUCCEEDED' && $order->get_status() === 'pending') {
                    $order->payment_complete($invoice_id);
                    $order->add_order_note(__('Payment confirmed via Breez API.', 'breez-woocommerce'));
                    $order->save();
                    $logger->log("Order #{$payment['order_id']} marked as complete", 'info');
                } else if ($payment_status['status'] === 'FAILED' && $order->get_status() === 'pending') {
                    $order->update_status('failed', __('Payment failed or expired.', 'breez-woocommerce'));
                    $logger->log("Order #{$payment['order_id']} marked as failed", 'info');
                }
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'status' => $payment_status['status'],
            'data' => array_merge($payment_status, array(
                'order_id' => $payment['order_id'],
                'payment_method' => $payment['metadata']['payment_method'],
                'amount_sat' => $payment['metadata']['amount_sat'],
                'expires_at' => $payment['metadata']['expires_at']
            ))
        ), 200);

    } catch (Exception $e) {
        if (isset($logger)) {
            $logger->log('Payment status check failed: ' . $e->getMessage(), 'error');
            $logger->log('Stack trace: ' . $e->getTraceAsString(), 'debug');
        }
        
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Internal server error: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * Payment status check endpoint handler
 */
function breez_wc_check_payment_status_endpoint($request) {
    try {
        // Initialize logger
        $logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
        $logger->log('Payment status check endpoint called', 'debug');

        $order_id = $request->get_param('order_id');
        $logger->log("Checking payment status for order #$order_id", 'debug');

        // Initialize DB manager
        $db_manager = new Breez_DB_Manager();
        $payment = $db_manager->get_payment_by_order($order_id);
        
        if (!$payment) {
            $logger->log("No payment found for order #$order_id", 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payment not found'
            ), 404);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->log("Order #$order_id not found", 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Order not found'
            ), 404);
        }

        // Only allow checking status of Breez payments
        if ($order->get_payment_method() !== 'breez') {
            $logger->log("Invalid payment method for order #$order_id: " . $order->get_payment_method(), 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid payment method'
            ), 400);
        }

        // Initialize API client
        $gateway_settings = get_option('woocommerce_breez_settings');
        $api_url = isset($gateway_settings['api_url']) ? $gateway_settings['api_url'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        if (empty($api_url) || empty($api_key)) {
            $logger->log("API credentials not configured", 'error');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payment gateway not properly configured'
            ), 500);
        }

        $client = new Breez_API_Client($api_url, $api_key);
        
        // Check payment status from API
        $api_status = $client->check_payment_status($payment['invoice_id']);
        $logger->log("API payment status for invoice {$payment['invoice_id']}: " . print_r($api_status, true), 'debug');
        
        // Update payment status in database if it has changed
        if ($api_status['status'] !== $payment['status']) {
            $db_manager->update_payment_status($order_id, $api_status['status']);
            
            // Update order status if needed
            if ($api_status['status'] === 'SUCCEEDED' && $order->get_status() === 'pending') {
                $order->payment_complete($payment['invoice_id']);
                $order->add_order_note(sprintf(
                    __('Payment confirmed. Amount: %d sats, Hash: %s', 'breez-woocommerce'),
                    $payment['metadata']['amount_sat'],
                    $payment['invoice_id']
                ));
                $order->save();
                $logger->log("Order #$order_id marked as complete", 'info');
            } else if ($api_status['status'] === 'FAILED' && $order->get_status() === 'pending') {
                $order->update_status('failed', __('Payment failed or expired.', 'breez-woocommerce'));
                $logger->log("Order #$order_id marked as failed", 'info');
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'status' => $api_status['status'],
            'data' => array_merge($api_status, array(
                'order_status' => $order->get_status(),
                'payment_method' => $payment['metadata']['payment_method'],
                'amount_sat' => $payment['metadata']['amount_sat'],
                'expires_at' => $payment['metadata']['expires_at']
            ))
        ), 200);

    } catch (Exception $e) {
        if (isset($logger)) {
            $logger->log('Payment status check failed: ' . $e->getMessage(), 'error');
            $logger->log('Stack trace: ' . $e->getTraceAsString(), 'debug');
        }
        
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Internal server error: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * Check pending payments (runs via cron)
 */
function breez_wc_check_pending_payments() {
    $payment_handler = new Breez_Payment_Handler();
    $payment_handler->check_pending_payments();
}

/**
 * Plugin activation hook
 */
function breez_wc_activate($network_wide = false) {
    // Include all required files before using them
    $required_files = array(
        'includes/class-breez-logger.php',
        'includes/class-breez-api-client.php',
        'includes/class-breez-db-manager.php',
        'includes/class-breez-payment-handler.php',
        'includes/class-breez-webhook-handler.php'
    );
    
    foreach ($required_files as $file) {
        $full_path = BREEZ_WC_PLUGIN_DIR . $file;
        if (file_exists($full_path)) {
            require_once $full_path;
        }
    }
    
    // Initialize logger first
    $logger = new Breez_Logger(true);
    $logger->log('Plugin activation started', 'info');
    
    // Make sure the DB Manager class exists before using it
    if (class_exists('Breez_DB_Manager')) {
        // Initialize DB manager
        $db_manager = new Breez_DB_Manager();
        
        // Force drop and recreate the payments table with new schema
        $logger->log('Installing database tables', 'info');
        $db_manager->install_tables();
        
        // Create required directories
        $upload_dir = wp_upload_dir();
        $breez_dir = $upload_dir['basedir'] . '/breez-wc';
        if (!file_exists($breez_dir)) {
            wp_mkdir_p($breez_dir);
        }
        
        $logger->log('Plugin activation completed', 'info');
    } else {
        $logger->log('ERROR: Unable to initialize DB manager - class not found', 'error');
    }
}
register_activation_hook(__FILE__, 'breez_wc_activate');

/**
 * Plugin deactivation hook
 */
function breez_wc_deactivate() {
    // Unschedule payment status check
    $timestamp = wp_next_scheduled('breez_wc_check_pending_payments');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'breez_wc_check_pending_payments');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Check if WooCommerce is active before initializing
if (breez_wc_is_woocommerce_active()) {
    // Initialize after WooCommerce is fully loaded
    add_action('woocommerce_init', 'breez_wc_init');
    
    // Register activation/deactivation hooks
    register_activation_hook(__FILE__, 'breez_wc_activate');
    register_deactivation_hook(__FILE__, 'breez_wc_deactivate');
    
    // Add gateway after WooCommerce payment gateways are registered
    add_action('woocommerce_payment_gateways_init', function() {
        add_filter('woocommerce_payment_gateways', 'breez_wc_add_gateway');
    });
    
    // Register the payment gateway with WooCommerce Blocks
    add_action('woocommerce_blocks_loaded', function() {
        // Re-enabled with support for WooCommerce 9.8.3
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            require_once BREEZ_WC_PLUGIN_DIR . 'includes/class-breez-blocks-support.php';
            
            // Register scripts for blocks
            add_action('wp_enqueue_scripts', 'breez_register_blocks_scripts');
            
            add_action('woocommerce_blocks_payment_method_type_registration', function($payment_method_registry) {
                $payment_method_registry->register(new Breez_Blocks_Support());
                
                if (class_exists('Breez_Logger')) {
                    $logger = new Breez_Logger(true);
                    $logger->log('Registered Breez with WooCommerce Blocks', 'debug');
                }
            });
        }
        
        // Log blocks integration
        if (class_exists('Breez_Logger')) {
            $logger = new Breez_Logger(true);
            $logger->log('WooCommerce Blocks integration enabled for 9.8.3', 'debug');
        }
    });
} else {
    // Display admin notice if WooCommerce is not active
    add_action('admin_notices', 'breez_wc_woocommerce_missing_notice');
}

/**
 * Register scripts for WooCommerce Blocks
 */
function breez_register_blocks_scripts() {
    if (!is_checkout() && !is_cart()) {
        return;
    }
    
    $settings = get_option('woocommerce_breez_settings', []);
    
    // Only load if WooCommerce Blocks is active
    if (!class_exists('Automattic\WooCommerce\Blocks\Package')) {
        return;
    }
    
    // Register the blocks script
    wp_register_script(
        'breez-blocks',
        BREEZ_WC_PLUGIN_URL . 'includes/block/breez-payment.js',
        [
            'wp-element',
            'wp-components',
            'wc-blocks-registry',
            'wp-blocks',
            'wp-i18n',
            'wp-html-entities',
            'wc-blocks-components',
            'wc-settings'
        ],
        BREEZ_WC_VERSION,
        true
    );
    
    // Add settings for the blocks script
    wp_localize_script(
        'breez-blocks',
        'wcSettings',
        [
            'breez' => [
                'title' => !empty($settings['title']) ? $settings['title'] : 'Pay with Lightning',
                'description' => !empty($settings['description']) ? $settings['description'] : '',
                'supports' => ['products'],
                'showSavedCards' => false,
                'canMakePayment' => true,
                'paymentMethodId' => 'breez',
                'orderButtonText' => __('Proceed to Payment', 'breez-woocommerce'),
                'defaultPaymentMethod' => 'lightning',
                'paymentMethodData' => [
                    'breez_payment_method' => 'lightning'
                ],
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('breez-payment')
            ]
        ]
    );
    
    // Enqueue the script
    wp_enqueue_script('breez-blocks');
    
    if (class_exists('Breez_Logger')) {
        $logger = new Breez_Logger(true);
        $logger->log('Breez blocks script registered and localized', 'debug');
        $logger->log('Script dependencies: ' . print_r(wp_scripts()->registered['breez-blocks']->deps, true), 'debug');
    }
}

add_action('plugins_loaded', function() {
    if (class_exists('Breez_Logger')) {
        $logger = new Breez_Logger(true);
        $logger->log('Breez plugin diagnostics running', 'debug');
        
        // Check database tables
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_breez_payments';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        $logger->log("Database table check: " . ($table_exists ? 'exists' : 'missing'), 'debug');
        
        // Check API settings
        $api_url = get_option('woocommerce_breez_api_url');
        $api_key = get_option('woocommerce_breez_api_key');
        $payment_methods = get_option('woocommerce_breez_payment_methods');
        $logger->log("Configuration: API URL: " . (empty($api_url) ? 'missing' : 'set') . 
                   ", API Key: " . (empty($api_key) ? 'missing' : 'set') . 
                   ", Payment Methods: " . print_r($payment_methods, true), 'debug');
    }
});

// Add WooCommerce Blocks compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Handle AJAX request for payment URL
 */
function breez_handle_payment_url() {
    check_ajax_referer('breez-payment', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }
    
    $payment_url = $order->get_meta('_breez_payment_url');
    if (!$payment_url) {
        wp_send_json_error(['message' => 'Payment URL not found']);
        return;
    }
    
    wp_send_json_success(['payment_url' => $payment_url]);
}
add_action('wp_ajax_breez_get_payment_url', 'breez_handle_payment_url');
add_action('wp_ajax_nopriv_breez_get_payment_url', 'breez_handle_payment_url');

/**
 * Set CORS headers for API endpoints
 */
function breez_wc_set_cors_headers() {
    // Only set CORS headers for our endpoints
    if (isset($_SERVER['REQUEST_URI']) && 
        (strpos($_SERVER['REQUEST_URI'], '/breez-wc/v1/') !== false || 
         strpos($_SERVER['REQUEST_URI'], '/wc-api/wc_gateway_breez') !== false)) {
        
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
        
        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }
}
add_action('init', 'breez_wc_set_cors_headers', 1);

/**
 * Register blocks checkout data filter
 */
function breez_wc_register_blocks_checkout_filters() {
    add_filter('woocommerce_store_api_checkout_update_order_from_request', 'breez_wc_filter_blocks_checkout_order_data', 10, 2);
}
add_action('init', 'breez_wc_register_blocks_checkout_filters');

/**
 * Filter checkout API data to handle Breez payment data
 * 
 * @param WC_Order $order
 * @param WP_REST_Request $request
 * @return WC_Order
 */
function breez_wc_filter_blocks_checkout_order_data($order, $request) {
    if (class_exists('Breez_Logger')) {
        $logger = new Breez_Logger(true);
        $logger->log('Filtering checkout order data for blocks', 'debug');
        $logger->log('Request data: ' . print_r($request->get_params(), true), 'debug');
    }
    
    $params = $request->get_params();
    
    // Check if Breez payment method is active
    if (isset($params['payment_method']) && $params['payment_method'] === 'breez') {
        if (isset($params['payment_data'])) {
            // Get payment data
            $payment_data = $params['payment_data'];
            
            // Store it as order meta for processing
            $order->update_meta_data('_breez_blocks_payment_data', $payment_data);
            $order->save();
            
            if (class_exists('Breez_Logger')) {
                $logger = new Breez_Logger(true);
                $logger->log('Stored Breez payment data in order meta', 'debug');
            }
        }
    }
    
    return $order;
}

/**
 * Display payment instructions on the thank you page
 */
function breez_wc_thankyou_payment_instructions($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order || $order->get_payment_method() !== 'breez' || $order->has_status('processing')) {
        return;
    }
    
    // Get payment details from database
    $db_manager = new Breez_DB_Manager();
    $payment = $db_manager->get_payment_by_order($order_id);
    
    if (!$payment) {
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

/**
 * Display payment instructions on the order received page
 */
function breez_wc_order_received_payment_instructions($order) {
    if (!$order || $order->get_payment_method() !== 'breez' || $order->has_status('processing')) {
        return;
    }
    
    // Get payment details from database
    $db_manager = new Breez_DB_Manager();
    $payment = $db_manager->get_payment_by_order($order->get_id());
    
    if (!$payment) {
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
