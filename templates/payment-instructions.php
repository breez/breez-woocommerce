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
    <h3><?php _e('Bitcoin/Lightning Payment', 'breez-woocommerce'); ?></h3>
    
    <?php if ($payment_status === 'completed'): ?>
        <div class="breez-payment-status breez-payment-completed">
            <p><?php _e('Payment received! Thank you for your payment.', 'breez-woocommerce'); ?></p>
            <p><?php _e('Your order is now being processed.', 'breez-woocommerce'); ?></p>
        </div>
    <?php elseif ($payment_status === 'failed'): ?>
        <div class="breez-payment-status breez-payment-failed">
            <p><?php _e('Payment failed or expired.', 'breez-woocommerce'); ?></p>
            <p><?php _e('Please contact us for assistance.', 'breez-woocommerce'); ?></p>
        </div>
    <?php else: ?>
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
                
                <p class="breez-payment-info">
                    <?php echo esc_html(get_option('woocommerce_breez_instructions')); ?>
                </p>
            </div>
        <?php else: ?>
            <div class="breez-payment-expired">
                <p><?php _e('Payment time expired. Please contact support or place a new order.', 'breez-woocommerce'); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // QR Code generation
    var qrContainer = document.getElementById('breez-qr-container');
    if (qrContainer) {
        var qrData = qrContainer.getAttribute('data-qr-data');
        generateQRCode(qrData);
    }

    // Get API configuration
    var apiUrl = '<?php echo esc_js(get_option('woocommerce_breez_api_url')); ?>';
    var apiKey = '<?php echo esc_js(get_option('woocommerce_breez_api_key')); ?>';
    var invoiceId = '<?php echo esc_js($invoice_id); ?>';

    // Ensure API URL ends with a slash
    apiUrl = apiUrl.replace(/\/?$/, '/');

    // Countdown functionality
    var countdownEl = document.querySelector('.breez-countdown');
    var expiryTime = parseInt(document.querySelector('.breez-payment-countdown')?.dataset.expiry, 10);
    
    if (countdownEl && expiryTime) {
        var countdownInterval = setInterval(function() {
            var now = Math.floor(Date.now() / 1000);
            var timeLeft = expiryTime - now;
            
            if (timeLeft <= 0) {
                clearInterval(countdownInterval);
                clearInterval(statusCheckInterval); // Also clear status check
                document.querySelector('.breez-payment-countdown').innerHTML = '<p><?php _e('Payment time expired.', 'breez-woocommerce'); ?></p>';
                document.querySelector('.breez-payment-qr').style.opacity = '0.3';
                
                // Show expired message
                var paymentBox = document.querySelector('.breez-payment-box');
                if (paymentBox) {
                    paymentBox.innerHTML = `
                        <h3><?php _e('Bitcoin/Lightning Payment', 'breez-woocommerce'); ?></h3>
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

    // Payment status check functionality
    function checkPaymentStatus() {
        var paymentBox = document.querySelector('.breez-payment-box');
        
        // Stop checking if payment box doesn't exist
        if (!paymentBox) {
            clearInterval(statusCheckInterval);
            return;
        }

        // Stop checking if payment is already completed or failed
        if (paymentBox.querySelector('.breez-payment-completed') || 
            paymentBox.querySelector('.breez-payment-failed') || 
            paymentBox.querySelector('.breez-payment-expired')) {
            clearInterval(statusCheckInterval);
            return;
        }

        // Show loading indicator
        var statusIndicator = paymentBox.querySelector('.breez-payment-status');
        if (!statusIndicator) {
            statusIndicator = document.createElement('div');
            statusIndicator.className = 'breez-payment-status';
            paymentBox.appendChild(statusIndicator);
        }
        
        // Use WordPress REST API endpoint instead of direct Breez API call
        fetch('/wp-json/breez-wc/v1/check-payment-status/' + invoiceId, {
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response error: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            // Use the 'data' property from our API wrapper
            const paymentData = data.data || data;
            
            // Clear any existing error message
            statusIndicator.classList.remove('breez-payment-error');
            
            // If status is pending, don't show any status message
            if (paymentData.status === 'pending') {
                return;
            }
            
            if (paymentData.status === 'SUCCEEDED' || (data.status && data.status === 'SUCCEEDED')) {
                // Stop checking status immediately
                clearInterval(statusCheckInterval);
                
                // Update UI to show payment completed
                paymentBox.innerHTML = `
                    <h3><?php _e('Bitcoin/Lightning Payment', 'breez-woocommerce'); ?></h3>
                    <div class="breez-payment-status breez-payment-completed">
                        <p><?php _e('Payment received! Thank you for your payment.', 'breez-woocommerce'); ?></p>
                        <p><?php _e('Your order is now being processed.', 'breez-woocommerce'); ?></p>
                        <p class="breez-payment-details">
                            <?php _e('Amount paid:', 'breez-woocommerce'); ?> ${Number(paymentData.amount_sat).toLocaleString()} sats<br>
                            <?php _e('Network fee:', 'breez-woocommerce'); ?> ${Number(paymentData.fees_sat).toLocaleString()} sats
                        </p>
                    </div>
                `;
                
                // Notify WordPress about the completed payment
                notifyServer(paymentData);
                
                // Double check interval is cleared
                if (statusCheckInterval) {
                    clearInterval(statusCheckInterval);
                    statusCheckInterval = null;
                }
            } else if (paymentData.status === 'FAILED' || (data.status && data.status === 'FAILED')) {
                // Stop checking status immediately
                clearInterval(statusCheckInterval);
                
                // Update UI to show payment failed
                paymentBox.innerHTML = `
                    <h3><?php _e('Bitcoin/Lightning Payment', 'breez-woocommerce'); ?></h3>
                    <div class="breez-payment-status breez-payment-failed">
                        <p><?php _e('Payment failed.', 'breez-woocommerce'); ?></p>
                        <p><?php _e('Please try again or contact us for assistance.', 'breez-woocommerce'); ?></p>
                        ${paymentData.error ? `<p class="breez-error-details">${paymentData.error}</p>` : ''}
                    </div>
                `;
                
                // Notify WordPress about the failed payment
                notifyServer(paymentData);
                
                // Double check interval is cleared
                if (statusCheckInterval) {
                    clearInterval(statusCheckInterval);
                    statusCheckInterval = null;
                }
            }
        })
        .catch(error => {
            console.error('Error checking payment status:', error);
            // Don't show error message for status check failures
            // Just log to console and continue checking
        });
    }

    // Function to notify WordPress about payment status changes
    function notifyServer(paymentData) {
        fetch('/wp-json/breez-wc/v1/check-payment-status?order_id=<?php echo esc_js($order->get_id()); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
            },
            body: JSON.stringify(paymentData)
        })
        .catch(error => console.error('Error notifying server:', error));
    }

    // Check payment status every 5 seconds
    var statusCheckInterval = setInterval(checkPaymentStatus, 5000);
    
    // Do an initial check immediately
    checkPaymentStatus();

    // Copy invoice button functionality
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
                setTimeout(function() {
                    copyButton.textContent = '<?php _e('Copy', 'breez-woocommerce'); ?>';
                }, 2000);
            } catch (err) {
                console.error('Failed to copy: ', err);
            }
            
            document.body.removeChild(textarea);
        });
    }

    // QR Code generation function
    function generateQRCode(data) {
        // Try QRServer.com first
        var primaryUrl = 'https://api.qrserver.com/v1/create-qr-code/?' + new URLSearchParams({
            data: data,
            size: '300x300',
            margin: '10',
            format: 'svg',
            qzone: '1',
            color: '000000',
            bgcolor: 'FFFFFF'
        }).toString();

        // Fallback URL (Google Charts)
        var fallbackUrl = 'https://chart.googleapis.com/chart?' + new URLSearchParams({
            chs: '300x300',
            cht: 'qr',
            chl: data,
            choe: 'UTF-8',
            chld: 'M|4'
        }).toString();

        // Create image element
        var img = new Image();
        img.style.cssText = 'display:block; max-width:300px; height:auto;';
        img.alt = 'QR Code';

        // Try primary service first
        img.onerror = function() {
            // If primary fails, try fallback
            img.src = fallbackUrl;
        };

        img.onload = function() {
            qrContainer.innerHTML = '';
            var wrapper = document.createElement('div');
            wrapper.className = 'breez-qr-wrapper';
            wrapper.style.cssText = 'background:white; padding:15px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1); display:inline-block;';
            wrapper.appendChild(img);
            qrContainer.appendChild(wrapper);
        };

        // Start loading primary QR code
        img.src = primaryUrl;
    }
});
</script>

<style>
    .breez-payment-box {
        max-width: 600px;
        margin: 0 auto;
        padding: 20px;
        background: #f8f8f8;
        border-radius: 5px;
        text-align: center;
    }
    
    .breez-payment-status {
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .breez-payment-completed {
        background: #d4edda;
        color: #155724;
    }
    
    .breez-payment-failed {
        background: #f8d7da;
        color: #721c24;
    }
    
    .breez-payment-countdown {
        font-size: 18px;
        margin-bottom: 20px;
    }
    
    .breez-countdown {
        font-weight: bold;
    }
    
    .breez-payment-qr {
        margin-bottom: 20px;
        display: flex;
        justify-content: center;
    }

    .breez-qr-loading {
        text-align: center;
        padding: 20px;
    }

    .breez-spinner {
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 10px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .breez-invoice-container {
        position: relative;
        margin-bottom: 20px;
    }
    
    .breez-invoice-text {
        width: 100%;
        padding: 10px;
        font-family: monospace;
        font-size: 14px;
        resize: none;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .breez-copy-button {
        position: absolute;
        right: 10px;
        top: 10px;
        padding: 5px 10px;
        background: #0073aa;
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
    }
    
    .breez-copy-button:hover {
        background: #005177;
    }
    
    .breez-payment-info {
        margin-bottom: 20px;
    }
    
    .breez-payment-expired {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .breez-payment-error {
        background: #fff3cd;
        color: #856404;
        padding: 10px;
        margin: 10px 0;
        border-radius: 4px;
        border: 1px solid #ffeeba;
    }
    
    .breez-error-details {
        font-size: 0.9em;
        margin-top: 5px;
        color: #721c24;
    }
    
    .breez-payment-details {
        font-size: 0.9em;
        margin-top: 15px;
        padding: 10px;
        background: rgba(0, 0, 0, 0.05);
        border-radius: 4px;
    }
</style>
