<?php
/**
 * Tests for Breez_Webhook_Handler class
 */

class Test_Breez_Webhook_Handler extends Breez_Unit_Test_Case {
    /**
     * @var Breez_Webhook_Handler
     */
    private $webhook_handler;

    /**
     * Set up test environment
     */
    public function setUp(): void {
        parent::setUp();
        $this->webhook_handler = new Breez_Webhook_Handler($this->api_client, $this->db_manager);
    }

    /**
     * Test successful payment webhook
     */
    public function test_process_successful_payment_webhook(): void {
        // Create test order with pending payment
        $order = $this->create_test_order(100.00);
        $order->update_meta_data('_breez_payment_id', 'test_invoice_123');
        $order->update_meta_data('_breez_payment_status', 'pending');
        $order->save();

        // Mock webhook data
        $_POST = [
            'id' => 'test_invoice_123',
            'status' => 'SUCCEEDED',
            'amount' => 100000,
            'method' => 'LIGHTNING'
        ];

        // Set webhook secret
        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';

        // Process webhook
        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        // Refresh order
        $order = wc_get_order($order->get_id());

        // Assert webhook response
        $this->assertIsArray($response);
        $this->assertTrue($response['success']);

        // Assert order status
        $this->assertEquals('processing', $order->get_status());
        $this->assert_order_has_meta($order, '_breez_payment_status', 'SUCCEEDED');
    }

    /**
     * Test failed payment webhook
     */
    public function test_process_failed_payment_webhook(): void {
        // Create test order
        $order = $this->create_test_order(100.00);
        $order->update_meta_data('_breez_payment_id', 'test_invoice_123');
        $order->update_meta_data('_breez_payment_status', 'pending');
        $order->save();

        // Mock webhook data
        $_POST = [
            'id' => 'test_invoice_123',
            'status' => 'FAILED',
            'error' => 'Payment expired'
        ];

        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';

        // Process webhook
        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        // Refresh order
        $order = wc_get_order($order->get_id());

        // Assert webhook response
        $this->assertIsArray($response);
        $this->assertTrue($response['success']);

        // Assert order status
        $this->assertEquals('failed', $order->get_status());
        $this->assert_order_has_meta($order, '_breez_payment_status', 'FAILED');
    }

    /**
     * Test webhook with invalid secret
     */
    public function test_process_webhook_invalid_secret(): void {
        $_SERVER['HTTP_X_API_KEY'] = 'invalid_secret';

        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Invalid webhook secret', $response['message']);
    }

    /**
     * Test webhook with missing payment ID
     */
    public function test_process_webhook_missing_payment_id(): void {
        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';
        $_POST = ['status' => 'SUCCEEDED'];

        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Missing payment ID', $response['message']);
    }

    /**
     * Test webhook with invalid payment status
     */
    public function test_process_webhook_invalid_status(): void {
        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';
        $_POST = [
            'id' => 'test_invoice_123',
            'status' => 'INVALID_STATUS'
        ];

        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
    }

    /**
     * Test webhook with non-existent order
     */
    public function test_process_webhook_nonexistent_order(): void {
        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';
        $_POST = [
            'id' => 'nonexistent_invoice',
            'status' => 'SUCCEEDED'
        ];

        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('No order found', $response['message']);
    }

    /**
     * Test webhook idempotency
     */
    public function test_webhook_idempotency(): void {
        // Create test order
        $order = $this->create_test_order(100.00);
        $order->update_meta_data('_breez_payment_id', 'test_invoice_123');
        $order->update_meta_data('_breez_payment_status', 'SUCCEEDED');
        $order->set_status('processing');
        $order->save();

        // Try to process the same successful payment again
        $_SERVER['HTTP_X_API_KEY'] = 'test_webhook_secret';
        $_POST = [
            'id' => 'test_invoice_123',
            'status' => 'SUCCEEDED'
        ];

        ob_start();
        $this->webhook_handler->process_webhook();
        $response = json_decode(ob_get_clean(), true);

        // Refresh order
        $order = wc_get_order($order->get_id());

        // Assert nothing changed
        $this->assertTrue($response['success']);
        $this->assertEquals('processing', $order->get_status());
        $this->assert_order_has_meta($order, '_breez_payment_status', 'SUCCEEDED');
    }
} 