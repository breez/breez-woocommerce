<?php
/**
 * Tests for Breez_Blocks_Support class
 */

class Test_Breez_Blocks_Support extends Breez_Unit_Test_Case {
    /**
     * @var Breez_Blocks_Support
     */
    private $blocks_support;

    /**
     * Set up test environment
     */
    public function setUp(): void {
        parent::setUp();
        $this->blocks_support = new Breez_Blocks_Support();
        $this->blocks_support->initialize();
    }

    /**
     * Test blocks integration initialization
     */
    public function test_blocks_initialization(): void {
        $this->assertEquals('breez', $this->blocks_support->get_name());
        $this->assertTrue($this->blocks_support->is_active());
    }

    /**
     * Test payment data from blocks
     */
    public function test_get_payment_method_data(): void {
        $data = $this->blocks_support->get_payment_method_data();
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('supports', $data);
        $this->assertEquals('breez', $data['name']);
    }

    /**
     * Test successful payment processing from blocks
     */
    public function test_process_payment_from_blocks(): void {
        // Create test order
        $order = $this->create_test_order(100.00);

        // Mock exchange rate response
        $this->mock_api_response('/exchange_rates/USD', [
            'rate' => 40000.00
        ]);

        // Mock payment creation response
        $this->mock_api_response('/receive_payment', [
            'destination' => 'test_invoice_123',
            'status' => 'pending'
        ]);

        // Process payment through blocks
        $payment_data = [
            'paymentMethodData' => [
                'data' => [
                    'breez_payment_method' => 'lightning'
                ]
            ]
        ];

        $result = $this->blocks_support->process_payment($order, $payment_data);

        // Assert response format
        $this->assertIsArray($result);
        $this->assertArrayHasKey('payment_details', $result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);

        // Assert order meta
        $this->assert_order_has_meta($order, '_breez_payment_method', 'lightning');
        $this->assert_order_has_meta($order, '_breez_payment_id', 'test_invoice_123');
        $this->assertEquals('pending', $order->get_status());
    }

    /**
     * Test payment processing with missing payment method
     */
    public function test_process_payment_missing_method(): void {
        $order = $this->create_test_order(100.00);
        $payment_data = ['paymentMethodData' => ['data' => []]];

        $result = $this->blocks_support->process_payment($order, $payment_data);

        // Should default to lightning
        $this->assertTrue($result['success']);
        $this->assert_order_has_meta($order, '_breez_payment_method', 'lightning');
    }

    /**
     * Test payment processing with invalid payment method
     */
    public function test_process_payment_invalid_method(): void {
        $order = $this->create_test_order(100.00);
        $payment_data = [
            'paymentMethodData' => [
                'data' => [
                    'breez_payment_method' => 'invalid_method'
                ]
            ]
        ];

        $this->expectException(Exception::class);
        $this->blocks_support->process_payment($order, $payment_data);
    }

    /**
     * Test script registration
     */
    public function test_script_registration(): void {
        // Set up environment
        global $wp_scripts;
        if (!isset($wp_scripts)) {
            $wp_scripts = new WP_Scripts();
        }

        // Call the registration function
        breez_register_blocks_scripts();

        // Assert script is registered
        $this->assertTrue(wp_script_is('breez-blocks', 'registered'));

        // Check script dependencies
        $script = $wp_scripts->registered['breez-blocks'];
        $this->assertContains('wp-element', $script->deps);
        $this->assertContains('wc-blocks-registry', $script->deps);
    }

    /**
     * Test localized script data
     */
    public function test_localized_script_data(): void {
        // Set up environment
        global $wp_scripts;
        if (!isset($wp_scripts)) {
            $wp_scripts = new WP_Scripts();
        }

        // Register scripts
        breez_register_blocks_scripts();

        // Get localized data
        $data = $wp_scripts->get_data('breez-blocks', 'data');
        
        // Convert the inline script to data
        $json_start = strpos($data, '{');
        $json_end = strrpos($data, '}') + 1;
        $json = substr($data, $json_start, $json_end - $json_start);
        $settings = json_decode($json, true);

        // Assert localized data structure
        $this->assertArrayHasKey('breez', $settings);
        $this->assertArrayHasKey('title', $settings['breez']);
        $this->assertArrayHasKey('description', $settings['breez']);
        $this->assertArrayHasKey('paymentMethodId', $settings['breez']);
        $this->assertEquals('breez', $settings['breez']['paymentMethodId']);
    }
} 