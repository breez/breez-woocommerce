<?php
/**
 * Tests for Breez_API_Client class
 */

class Test_Breez_API_Client extends Breez_Unit_Test_Case {
    /**
     * Test API client initialization
     */
    public function test_api_client_initialization(): void {
        $client = new Breez_API_Client('https://api.test.breez.com', 'test_api_key');
        $this->assertInstanceOf(Breez_API_Client::class, $client);
    }

    /**
     * Test exchange rate request
     */
    public function test_get_exchange_rate(): void {
        // Mock response
        $this->mock_api_response('/exchange_rates/USD', [
            'rate' => 40000.00,
            'currency' => 'USD',
            'timestamp' => time()
        ]);

        $response = $this->api_client->request('GET', '/exchange_rates/USD');

        $this->assertIsArray($response);
        $this->assertArrayHasKey('rate', $response);
        $this->assertEquals(40000.00, $response['rate']);
    }

    /**
     * Test payment creation
     */
    public function test_create_payment(): void {
        // Mock response
        $this->mock_api_response('/receive_payment', [
            'destination' => 'test_invoice_123',
            'status' => 'pending',
            'amount' => 100000,
            'method' => 'LIGHTNING'
        ]);

        $response = $this->api_client->request('POST', '/receive_payment', [
            'amount' => 100000,
            'method' => 'LIGHTNING',
            'description' => 'Test payment'
        ]);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('destination', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('test_invoice_123', $response['destination']);
    }

    /**
     * Test payment status check
     */
    public function test_check_payment_status(): void {
        $invoice_id = 'test_invoice_123';

        // Mock response
        $this->mock_api_response("/payment_status/$invoice_id", [
            'status' => 'SUCCEEDED',
            'destination' => $invoice_id
        ]);

        $response = $this->api_client->check_payment_status($invoice_id);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('SUCCEEDED', $response['status']);
    }

    /**
     * Test API error handling
     */
    public function test_api_error_handling(): void {
        // Mock error response
        add_filter('pre_http_request', function() {
            return [
                'response' => ['code' => 400],
                'body' => json_encode([
                    'error' => 'Invalid request',
                    'message' => 'Bad request parameters'
                ])
            ];
        });

        $this->expectException(Exception::class);
        $this->api_client->request('GET', '/invalid_endpoint');
    }

    /**
     * Test network error handling
     */
    public function test_network_error_handling(): void {
        // Mock network error
        add_filter('pre_http_request', function() {
            return new WP_Error('http_request_failed', 'Connection failed');
        });

        $this->expectException(Exception::class);
        $this->api_client->request('GET', '/exchange_rates/USD');
    }

    /**
     * Test invalid API response handling
     */
    public function test_invalid_response_handling(): void {
        // Mock invalid JSON response
        add_filter('pre_http_request', function() {
            return [
                'response' => ['code' => 200],
                'body' => 'Invalid JSON'
            ];
        });

        $this->expectException(Exception::class);
        $this->api_client->request('GET', '/exchange_rates/USD');
    }

    /**
     * Test request with query parameters
     */
    public function test_request_with_query_parameters(): void {
        // Mock response
        $this->mock_api_response('/test_endpoint?param1=value1&param2=value2', [
            'success' => true
        ]);

        $response = $this->api_client->request('GET', '/test_endpoint', [
            'param1' => 'value1',
            'param2' => 'value2'
        ]);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
    }

    /**
     * Test request headers
     */
    public function test_request_headers(): void {
        // Mock response and capture request headers
        $captured_headers = null;
        add_filter('pre_http_request', function($pre, $args) use (&$captured_headers) {
            $captured_headers = $args['headers'];
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['success' => true])
            ];
        }, 10, 2);

        $this->api_client->request('GET', '/test_endpoint');

        $this->assertArrayHasKey('X-API-Key', $captured_headers);
        $this->assertEquals('test_api_key', $captured_headers['X-API-Key']);
        $this->assertArrayHasKey('Content-Type', $captured_headers);
        $this->assertEquals('application/json', $captured_headers['Content-Type']);
    }
} 