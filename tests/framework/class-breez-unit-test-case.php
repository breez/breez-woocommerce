<?php
/**
 * Base test case for Breez WooCommerce tests
 */

class Breez_Unit_Test_Case extends WP_UnitTestCase {
    /**
     * @var WC_Gateway_Breez
     */
    protected $gateway;

    /**
     * @var Breez_API_Client
     */
    protected $api_client;

    /**
     * @var Breez_DB_Manager
     */
    protected $db_manager;

    /**
     * @var Breez_Logger
     */
    protected $logger;

    /**
     * Set up test environment
     */
    public function setUp(): void {
        parent::setUp();

        // Initialize WooCommerce settings
        update_option('woocommerce_currency', 'USD');
        
        // Initialize gateway settings
        update_option('woocommerce_breez_settings', [
            'enabled' => 'yes',
            'title' => 'Breez Nodeless Payments',
            'description' => 'Pay with Lightning',
            'api_url' => 'https://api.test.breez.com',
            'api_key' => 'test_api_key',
            'webhook_secret' => 'test_webhook_secret',
            'payment_methods' => 'lightning,onchain',
            'expiry_minutes' => '30',
            'debug' => 'yes'
        ]);

        // Initialize test instances
        $this->gateway = new WC_Gateway_Breez();
        $this->api_client = new Breez_API_Client('https://api.test.breez.com', 'test_api_key');
        $this->db_manager = new Breez_DB_Manager();
        $this->logger = new Breez_Logger(true);
    }

    /**
     * Tear down test environment
     */
    public function tearDown(): void {
        parent::tearDown();
        
        // Clean up test data
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order')");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_order'");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT id FROM {$wpdb->posts})");
    }

    /**
     * Create a test order
     *
     * @param float $total Order total
     * @return WC_Order
     */
    protected function create_test_order($total = 100.00): WC_Order {
        $order = wc_create_order();
        $order->set_payment_method($this->gateway);
        $order->set_total($total);
        $order->save();
        return $order;
    }

    /**
     * Mock API response
     *
     * @param string $endpoint API endpoint
     * @param array $response Response data
     * @return void
     */
    protected function mock_api_response(string $endpoint, array $response): void {
        add_filter('pre_http_request', function($pre, $args, $url) use ($endpoint, $response) {
            if (strpos($url, $endpoint) !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode($response)
                ];
            }
            return $pre;
        }, 10, 3);
    }

    /**
     * Assert order has meta
     *
     * @param WC_Order $order
     * @param string $meta_key
     * @param mixed $expected_value
     */
    protected function assert_order_has_meta(WC_Order $order, string $meta_key, $expected_value): void {
        $actual_value = $order->get_meta($meta_key);
        $this->assertEquals($expected_value, $actual_value, "Order meta '$meta_key' does not match expected value");
    }
} 