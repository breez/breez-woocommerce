<?php
/**
 * Breez Logger
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Breez Logger Class
 *
 * Handles logging for the Breez Nodeless Payments.
 */
class Breez_Logger {
    /**
     * Whether debugging is enabled
     *
     * @var bool
     */
    private $debug;
    
    /**
     * Start time for performance tracking
     *
     * @var float
     */
    private $start_time;
    
    /**
     * Constructor
     *
     * @param bool $debug Whether debugging is enabled
     */
    public function __construct($debug = false) {
        $this->debug = $debug;
        $this->start_time = microtime(true);
        
        if ($this->debug) {
            $this->log('Logger initialized', 'debug');
        }
    }
    
    /**
     * Get elapsed time since logger initialization
     *
     * @return float Elapsed time in seconds
     */
    private function get_elapsed_time() {
        return microtime(true) - $this->start_time;
    }
    
    /**
     * Format context data for logging
     *
     * @param array $context Additional context data
     * @return string Formatted context string
     */
    private function format_context($context = array()) {
        $default_context = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'elapsed' => sprintf('%.4f', $this->get_elapsed_time()),
            'memory' => sprintf('%.2fMB', memory_get_usage() / 1024 / 1024)
        );
        
        $context = array_merge($default_context, $context);
        return json_encode($context);
    }
    
    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level (debug, info, warning, error)
     */
    /**
     * Log a message with context
     *
     * @param string $message Message to log
     * @param string $level Log level (debug, info, warning, error)
     * @param array $context Additional context data
     */
    public function log($message, $level = 'info', $context = array()) {
        if (!$this->debug && $level !== 'error') {
            return;
        }
        
        try {
            // Add trace for errors
            if ($level === 'error') {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                $caller = isset($trace[1]) ? $trace[1] : $trace[0];
                $context['file'] = isset($caller['file']) ? basename($caller['file']) : '';
                $context['line'] = isset($caller['line']) ? $caller['line'] : '';
                $context['function'] = isset($caller['function']) ? $caller['function'] : '';
            }
            
            $formatted_context = $this->format_context($context);
            $log_message = sprintf('[%s] %s | %s', strtoupper($level), $message, $formatted_context);
            
            if (function_exists('wc_get_logger')) {
                // Use WC_Logger if available
                $logger = wc_get_logger();
                $logger_context = array_merge(array('source' => 'breez'), $context);
                
                switch ($level) {
                    case 'debug':
                        $logger->debug($log_message, $logger_context);
                        break;
                    case 'warning':
                        $logger->warning($log_message, $logger_context);
                        break;
                    case 'error':
                        $logger->error($log_message, $logger_context);
                        break;
                    case 'info':
                    default:
                        $logger->info($log_message, $logger_context);
                        break;
                }
            } else {
                // Fallback to WP debug log if enabled
                if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log(sprintf(
                        '[Breez %s] %s',
                        strtoupper($level),
                        $message
                    ));
                }
            }
        } catch (Exception $e) {
            // Last resort fallback - only if WP_DEBUG is enabled
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Breez Logger Error] Failed to log message: %s. Original message: %s',
                    $e->getMessage(),
                    $message
                ));
            }
        }
    }
    
    /**
     * Log API request
     *
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     */
    public function log_request($endpoint, $params = array()) {
        $this->log(
            "API Request to $endpoint",
            'debug',
            array(
                'endpoint' => $endpoint,
                'params' => $params
            )
        );
    }
    
    /**
     * Log API response
     *
     * @param string $endpoint API endpoint
     * @param mixed $response Response data
     * @param int $status_code HTTP status code
     */
    public function log_response($endpoint, $response, $status_code = null) {
        $context = array(
            'endpoint' => $endpoint,
            'status_code' => $status_code,
            'response' => is_array($response) ? $response : array('raw' => substr((string)$response, 0, 500))
        );
        
        $level = ($status_code && $status_code >= 400) ? 'error' : 'debug';
        $this->log(
            "API Response from $endpoint",
            $level,
            $context
        );
    }
}
