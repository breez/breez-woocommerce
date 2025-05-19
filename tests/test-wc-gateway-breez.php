<?php
/**
 * Tests for WC_Gateway_Breez class
 */

class Test_WC_Gateway_Breez extends Breez_Unit_Test_Case {
    /**
     * Test gateway initialization
     */
    public function test_gateway_initialization(): void {
        $this->assertEquals('breez', $this->gateway->id);
        $this->assertEquals('Breez NodeLess Payments', $this->gateway->method_title);
        $this->assertEquals(['products', 'refunds'], $this->gateway->supports);
        $this->assertTrue($this->gateway->is_available());
    }

    /**
     * Test gateway settings
     */
    public function test_gateway_settings(): void {
        $settings = $this->gateway->get_form_fields();
        
        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('title', $settings);
        $this->assertArrayHasKey('description', $settings);
        $this->assertArrayHasKey('api_url', $settings);
        $this->assertArrayHasKey('api_key', $settings);
        $this->assertArrayHasKey('webhook_secret', $settings);
        $this->assertArrayHasKey('payment_methods', $settings);
    }

    /**
     * Test payment processing with Lightning
     */
    public function test_process_payment_lightning(): void {
        // Mock exchange rate response
        $this->mock_api_response('/exchange_rates/USD', [
            'rate' => 40000.00 // 1 BTC = $40,000 USD
        ]);

        // Mock payment creation response
        $this->mock_api_response('/receive_payment', [
            'destination' => 'test_invoice_123',
            'status' => 'pending'
        ]);

        // Create test order
        $order = $this->create_test_order(100.00); // $100 USD
        
        // Set payment method
        $_POST['breez_payment_method'] = 'lightning';

        // Process payment
        $result = $this->gateway->process_payment($order->get_id());

        // Assert response format
        $this->assertIsArray($result);
        $this->assertEquals('success', $result['result']);
        $this->assertNotEmpty($result['redirect']);

        // Assert order meta
        $this->assert_order_has_meta($order, '_breez_payment_method', 'lightning');
        $this->assert_order_has_meta($order, '_breez_payment_id', 'test_invoice_123');
        $this->assert_order_has_meta($order, '_breez_payment_amount', '100.00');
        $this->assert_order_has_meta($order, '_breez_payment_amount_sat', 250000); // $100 at $40k/BTC
        $this->assert_order_has_meta($order, '_breez_payment_status', 'pending');

        // Assert order status
        $this->assertEquals('pending', $order->get_status());
    }

    /**
     * Test payment processing with on-chain
     */
    public function test_process_payment_onchain(): void {
        // Mock exchange rate response
        $this->mock_api_response('/exchange_rates/USD', [
            'rate' => 40000.00
        ]);

        // Mock payment creation response
        $this->mock_api_response('/receive_payment', [
            'destination' => 'bc1qtest...',
            'status' => 'pending'
        ]);

        // Create test order
        $order = $this->create_test_order(100.00);
        
        // Set payment method
        $_POST['breez_payment_method'] = 'onchain';

        // Process payment
        $result = $this->gateway->process_payment($order->get_id());

        // Assert response
        $this->assertEquals('success', $result['result']);
        $this->assertNotEmpty($result['redirect']);

        // Assert order meta
        $this->assert_order_has_meta($order, '_breez_payment_method', 'onchain');
        $this->assert_order_has_meta($order, '_breez_payment_id', 'bc1qtest...');
        $this->assertEquals('pending', $order->get_status());
    }

    /**
     * Test payment processing with invalid amount
     */
    public function test_process_payment_invalid_amount(): void {
        $order = $this->create_test_order(0.00);
        $_POST['breez_payment_method'] = 'lightning';

        $result = $this->gateway->process_payment($order->get_id());

        $this->assertArrayHasKey('result', $result);
        $this->assertEquals('failed', $order->get_status());
    }

    /**
     * Test payment processing with API error
     */
    public function test_process_payment_api_error(): void {
        // Mock failed API response
        add_filter('pre_http_request', function() {
            return new WP_Error('http_request_failed', 'API request failed');
        });

        $order = $this->create_test_order(100.00);
        $_POST['breez_payment_method'] = 'lightning';

        $result = $this->gateway->process_payment($order->get_id());

        $this->assertEquals('failed', $order->get_status());
        $this->assertStringContainsString('error', $order->get_status_message());
    }

    /**
     * Test webhook handling
     */
    public function test_webhook_handler(): void {
        // Create test order with pending payment
        $order = $this->create_test_order(100.00);
        $order->update_meta_data('_breez_payment_id', 'test_invoice_123');
        $order->update_meta_data('_breez_payment_status', 'pending');
        $order->save();

        // Mock webhook data
        $_POST = [
            'id' => 'test_invoice_123',
            'status' => 'SUCCEEDED'
        ];

        // Set webhook secret in header
        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';

        // Call webhook handler
        $this->gateway->webhook_handler();

        // Refresh order
        $order = wc_get_order($order->get_id());

        // Assert order status updated
        $this->assertEquals('processing', $order->get_status());
        $this->assert_order_has_meta($order, '_breez_payment_status', 'SUCCEEDED');
    }

    /**
     * Test invalid webhook secret
     */
    public function test_webhook_handler_invalid_secret(): void {
        $_SERVER['HTTP_X_API_KEY'] = 'invalid_secret';
        
        // Capture response
        ob_start();
        $this->gateway->webhook_handler();
        $response = json_decode(ob_get_clean(), true);

        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);
    }

    /**
     * Test payment method availability
     */
    public function test_payment_method_availability(): void {
        // Test with both methods enabled
        $this->assertEquals(['lightning', 'onchain'], $this->gateway->get_available_payment_methods());

        // Test with only lightning
        update_option('woocommerce_breez_settings', array_merge(
            get_option('woocommerce_breez_settings', []),
            ['payment_methods' => 'lightning']
        ));
        $this->gateway = new WC_Gateway_Breez();
        $this->assertEquals(['lightning'], $this->gateway->get_available_payment_methods());

        // Test with no methods (should default to lightning)
        update_option('woocommerce_breez_settings', array_merge(
            get_option('woocommerce_breez_settings', []),
            ['payment_methods' => '']
        ));
        $this->gateway = new WC_Gateway_Breez();
        $this->assertEquals(['lightning'], $this->gateway->get_available_payment_methods());
    }
} 