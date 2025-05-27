<?php
/**
 * Payment instructions template
 *
 * @package Breez_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Prevent duplicate display
global $breez_payment_instructions_displayed;
if ($breez_payment_instructions_displayed === true) {
    return;
}
$breez_payment_instructions_displayed = true;

// Variables:
// $order - WC_Order object
// $invoice_id - The payment invoice/address
// $payment_method - The payment method (LIGHTNING, BITCOIN_ADDRESS, etc)
// $expiry - The payment expiry timestamp
// $current_time - The current timestamp
// $payment_status - The current payment status

$time_left = $expiry - $current_time;
$minutes_left = floor($time_left / 60);
$seconds_left = $time_left % 60;

// Format the data for QR code
$qr_data = $invoice_id;
if ($payment_method === 'LIGHTNING' && strpos($qr_data, 'lightning:') !== 0 && strpos($qr_data, 'lnbc') === 0) {
    $qr_data = 'lightning:' . $qr_data;
} elseif ($payment_method === 'BITCOIN_ADDRESS' && strpos($qr_data, 'bitcoin:') !== 0) {
    $qr_data = 'bitcoin:' . $qr_data;
}

?>

<div class="breez-payment-box">
    <?php if ($payment_status === 'SUCCEEDED' || $payment_status === 'WAITING_CONFIRMATION'): ?>
        <div class="breez-payment-status breez-payment-completed">
            <p><?php _e('Payment received! Thank you for your payment.', 'breez-woocommerce'); ?></p>
            <p><?php _e('Your order is now being processed.', 'breez-woocommerce'); ?></p>
        </div>
    <?php elseif ($payment_status === 'FAILED'): ?>
        <div class="breez-payment-status breez-payment-failed">
            <p><?php _e('Payment failed or expired.', 'breez-woocommerce'); ?></p>
            <p><?php _e('Please contact us for assistance.', 'breez-woocommerce'); ?></p>
        </div>
    <?php else: ?>
        <div class="breez-payment-instructions">
            <h3><?php _e('Complete Your Payment', 'breez-woocommerce'); ?></h3>
            <p><?php echo esc_html(get_option('woocommerce_breez_instructions')); ?></p>
            
            <?php if ($time_left > 0): ?>
                <div class="breez-payment-countdown" data-expiry="<?php echo esc_attr($expiry); ?>">
                    <p><?php _e('Time remaining: ', 'breez-woocommerce'); ?><span class="breez-countdown"><?php printf('%02d:%02d', $minutes_left, $seconds_left); ?></span></p>
                </div>
                
                <div class="breez-payment-qr" id="breez-qr-container" data-qr-data="<?php echo esc_attr($qr_data); ?>">
                    <div class="breez-qr-loading">
                        <div class="breez-spinner"></div>
                        <p><?php _e('Generating QR Code...', 'breez-woocommerce'); ?></p>
                    </div>
                </div>
                
                <div class="breez-payment-details">
                    <p><strong><?php _e('Invoice/Address:', 'breez-woocommerce'); ?></strong></p>
                    <div class="breez-invoice-container">
                        <textarea readonly class="breez-invoice-text" rows="4"><?php echo esc_html($invoice_id); ?></textarea>
                        <button class="breez-copy-button" data-clipboard-text="<?php echo esc_attr($invoice_id); ?>">
                            <?php _e('Copy', 'breez-woocommerce'); ?>
                        </button>
                    </div>
                </div>
                
                <div class="breez-payment-status" id="breez-payment-status">
                    <div class="breez-payment-pending">
                        <p><?php _e('Waiting for payment...', 'breez-woocommerce'); ?></p>
                        <div class="breez-spinner"></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="breez-payment-expired">
                    <p><?php _e('Payment time expired. Please contact support or place a new order.', 'breez-woocommerce'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// QR Code generation
var qrContainer = document.getElementById('breez-qr-container');
if (qrContainer) {
    var qrData = qrContainer.getAttribute('data-qr-data');
    if (qrData) {
        var qrScript = document.createElement('script');
        qrScript.src = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js';
        qrScript.onload = function() {
            var qr = qrcode(0, 'M');
            qr.addData(qrData);
            qr.make();
            qrContainer.innerHTML = qr.createImgTag(5);
        };
        document.head.appendChild(qrScript);
    }
}

// Countdown timer
var countdownEl = document.querySelector('.breez-countdown');
var expiryTime = document.querySelector('.breez-payment-countdown')?.getAttribute('data-expiry');

if (countdownEl && expiryTime) {
    var countdownInterval = setInterval(function() {
        var now = Math.floor(Date.now() / 1000);
        var timeLeft = expiryTime - now;
        
        if (timeLeft <= 0) {
            clearInterval(countdownInterval);
            clearInterval(statusCheckInterval);
            document.querySelector('.breez-payment-countdown').innerHTML = '<p><?php _e('Payment time expired.', 'breez-woocommerce'); ?></p>';
            document.querySelector('.breez-payment-qr').style.opacity = '0.3';
            
            // Show expired message
            var paymentBox = document.querySelector('.breez-payment-box');
            if (paymentBox) {
                paymentBox.innerHTML = `
                    <div class="breez-payment-expired">
                        <p><?php _e('Payment time expired. Please contact support or place a new order.', 'breez-woocommerce'); ?></p>
                    </div>
                `;
            }
            return;
        }
        
        var minutes = Math.floor(timeLeft / 60);
        var seconds = timeLeft % 60;
        countdownEl.textContent = (minutes < 10 ? '0' : '') + minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    }, 1000);
}

// Payment status check
var invoiceId = '<?php echo esc_js($invoice_id); ?>';
var orderId = '<?php echo esc_js($order->get_id()); ?>';

function checkPaymentStatus() {
    console.log('Checking payment status for invoice:', invoiceId);
    fetch('/wp-json/breez-wc/v1/check-payment-status/' + invoiceId, {
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Payment status data:', data);
        var paymentData = data.data || data;
        console.log('Processed payment data:', paymentData);
        var statusContainer = document.getElementById('breez-payment-status');
        if (!statusContainer) {
            console.log('Status container not found');
            return;
        }
        if (paymentData.status === 'SUCCEEDED' || paymentData.status === 'WAITING_CONFIRMATION') {
            console.log('Payment successful, updating UI');
            clearInterval(statusCheckInterval);
            statusContainer.innerHTML = `
                <div class="breez-payment-completed">
                    <p><?php _e('Payment received! Thank you for your payment.', 'breez-woocommerce'); ?></p>
                    <p><?php _e('Your order is now being processed.', 'breez-woocommerce'); ?></p>
                </div>
            `;
            // Reload page after successful payment
            console.log('Scheduling page reload');
            setTimeout(() => {
                console.log('Reloading page');
                window.location.reload();
            }, 2000);
        } else if (paymentData.status === 'FAILED') {
            console.log('Payment failed, updating UI');
            clearInterval(statusCheckInterval);
            statusContainer.innerHTML = `
                <div class="breez-payment-failed">
                    <p><?php _e('Payment failed or expired.', 'breez-woocommerce'); ?></p>
                    <p><?php _e('Please try again or contact support if the problem persists.', 'breez-woocommerce'); ?></p>
                </div>
            `;
        } else {
            console.log('Payment pending, updating UI');
            statusContainer.innerHTML = `
                <div class="breez-payment-pending">
                    <p><?php _e('Waiting for payment...', 'breez-woocommerce'); ?></p>
                    <div class="breez-spinner"></div>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error checking payment status:', error);
        // Try to get more detailed error information
        if (error.response) {
            console.error('Error response:', error.response);
        }
    });
}

// Initial check
console.log('Starting payment status checks');
checkPaymentStatus();

// Check payment status every 5 seconds
var statusCheckInterval = setInterval(checkPaymentStatus, 5000);

// Copy invoice functionality
var copyButton = document.querySelector('.breez-copy-button');
if (copyButton) {
    copyButton.addEventListener('click', function() {
        var textToCopy = this.dataset.clipboardText;
        var textarea = document.createElement('textarea');
        textarea.value = textToCopy;
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            document.execCommand('copy');
            this.textContent = '<?php _e('Copied!', 'breez-woocommerce'); ?>';
            setTimeout(() => {
                this.textContent = '<?php _e('Copy', 'breez-woocommerce'); ?>';
            }, 2000);
        } catch (err) {
            console.error('Failed to copy:', err);
        }
        
        document.body.removeChild(textarea);
    });
}
</script>

<style>
.breez-payment-box {
    max-width: 600px;
    margin: 2em auto;
    padding: 20px;
    background: #f8f8f8;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.breez-payment-instructions {
    text-align: center;
}

.breez-payment-instructions h3 {
    margin-bottom: 1em;
    color: #333;
}

.breez-payment-status {
    padding: 15px;
    margin: 10px 0;
    border-radius: 4px;
    text-align: center;
}

.breez-payment-completed {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.breez-payment-failed {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.breez-payment-countdown {
    font-size: 18px;
    margin: 1em 0;
    color: #666;
}

.breez-countdown {
    font-weight: bold;
    color: #333;
}

.breez-payment-qr {
    margin: 2em auto;
    max-width: 300px;
}

.breez-payment-qr img {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
}

.breez-invoice-container {
    position: relative;
    margin: 1em auto;
    max-width: 500px;
}

.breez-invoice-text {
    width: 100%;
    padding: 10px 40px 10px 10px;
    font-family: monospace;
    font-size: 14px;
    resize: none;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
}

.breez-copy-button {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    padding: 5px 10px;
    background: #0073aa;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
}

.breez-copy-button:hover {
    background: #005177;
}

.breez-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 10px;
    vertical-align: middle;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.breez-payment-pending,
.breez-payment-confirming {
    background-color: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    padding: 15px;
    margin: 1em 0;
    border-radius: 4px;
}

.breez-payment-expired {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 15px;
    margin: 1em 0;
    border-radius: 4px;
}
</style>
