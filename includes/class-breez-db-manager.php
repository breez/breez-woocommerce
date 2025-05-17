<?php
/**
 * Breez Database Manager
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez DB Manager Class
 *
 * Handles database operations for the Breez Nodeless Payments.
 */
class Breez_DB_Manager {
    /**
     * Table name
     *
     * @var string
     */
    private $table_name;
    
    /**
     * Logger instance
     *
     * @var Breez_Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wc_breez_payments';
        $this->logger = new Breez_Logger('yes' === get_option('woocommerce_breez_debug', 'no'));
    }
    
/**
 * Install database tables
 */
public function install_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $this->logger->log("Installing database tables with charset: {$charset_collate}", 'debug');
    
    $table_name = $wpdb->prefix . 'wc_breez_payments';
    $this->logger->log("Table name: {$table_name}", 'debug');
    
    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        invoice_id VARCHAR(255) NOT NULL,
        amount DECIMAL(16,8) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        status VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        metadata TEXT,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id),
        KEY invoice_id (invoice_id)
    ) $charset_collate;";
    
    $this->logger->log("SQL query: {$sql}", 'debug');
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);
    
    $this->logger->log("Database tables installation result: " . json_encode($result), 'debug');
    
    // Check if table was created successfully
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    $this->logger->log("Table exists after creation: " . ($table_exists ? 'yes' : 'no'), 'debug');
    
    if (!$table_exists) {
        $this->logger->log("Failed to create database table", 'error');
        $this->logger->log("WordPress DB error: " . $wpdb->last_error, 'error');
    } else {
        $this->logger->log("Database tables installed successfully", 'info');
    }
}
    
    /**
     * Save payment data
     *
     * @param int $order_id Order ID
     * @param string $invoice_id Invoice ID
     * @param float $amount Payment amount
     * @param string $currency Currency code
     * @param string $status Payment status
     * @param array $metadata Optional metadata
     * @return bool Success/failure
     */
    public function save_payment($order_id, $invoice_id, $amount, $currency, $status, $metadata = array()) {
        global $wpdb;
        
        $now = current_time('mysql');
        
        try {
            $result = $wpdb->insert(
                $this->table_name,
                array(
                    'order_id' => $order_id,
                    'invoice_id' => $invoice_id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'metadata' => $metadata ? json_encode($metadata) : null
                ),
                array('%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result) {
                $this->logger->log("Payment saved: order_id=$order_id, invoice_id=$invoice_id, status=$status");
                return true;
            } else {
                $this->logger->log("Error saving payment: " . $wpdb->last_error);
                return false;
            }
        } catch (Exception $e) {
            $this->logger->log("Exception saving payment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update payment status
     *
     * @param int $order_id Order ID
     * @param string $status New status
     * @return bool Success/failure
     */
    public function update_payment_status($order_id, $status) {
        global $wpdb;
        
        $now = current_time('mysql');
        
        try {
            $result = $wpdb->update(
                $this->table_name,
                array(
                    'status' => $status,
                    'updated_at' => $now
                ),
                array('order_id' => $order_id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                $this->logger->log("Payment status updated: order_id=$order_id, new_status=$status");
                return true;
            } else {
                $this->logger->log("Error updating payment status: " . $wpdb->last_error);
                return false;
            }
        } catch (Exception $e) {
            $this->logger->log("Exception updating payment status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get payment by invoice ID
     *
     * @param string $invoice_id Invoice ID
     * @return array|false Payment data or false if not found
     */
    public function get_payment_by_invoice($invoice_id) {
        global $wpdb;
        
        try {
            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE invoice_id = %s",
                $invoice_id
            );
            
            $payment = $wpdb->get_row($query, ARRAY_A);
            
            if ($payment && isset($payment['metadata']) && $payment['metadata']) {
                $payment['metadata'] = json_decode($payment['metadata'], true);
            }
            
            return $payment;
        } catch (Exception $e) {
            $this->logger->log("Exception getting payment by invoice: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get payment by order ID
     *
     * @param int $order_id Order ID
     * @return array|false Payment data or false if not found
     */
    public function get_payment_by_order($order_id) {
        global $wpdb;
        
        try {
            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE order_id = %d",
                $order_id
            );
            
            $payment = $wpdb->get_row($query, ARRAY_A);
            
            if ($payment && isset($payment['metadata']) && $payment['metadata']) {
                $payment['metadata'] = json_decode($payment['metadata'], true);
            }
            
            return $payment;
        } catch (Exception $e) {
            $this->logger->log("Exception getting payment by order: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get pending payments
     *
     * @param int $minutes_old Get payments older than this many minutes
     * @param int $max_minutes_old Get payments younger than this many minutes
     * @return array Array of payment data
     */
    public function get_pending_payments($minutes_old = 2, $max_minutes_old = 60) {
        global $wpdb;
        
        try {
            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE status = 'pending' 
                AND created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
                AND created_at > DATE_SUB(NOW(), INTERVAL %d MINUTE)",
                $minutes_old,
                $max_minutes_old
            );
            
            $payments = $wpdb->get_results($query, ARRAY_A);
            
            // Parse metadata JSON
            foreach ($payments as &$payment) {
                if (isset($payment['metadata']) && $payment['metadata']) {
                    $payment['metadata'] = json_decode($payment['metadata'], true);
                }
            }
            
            return $payments;
        } catch (Exception $e) {
            $this->logger->log("Exception getting pending payments: " . $e->getMessage());
            return array();
        }
    }
}
