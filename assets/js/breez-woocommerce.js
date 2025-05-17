/**
 * Breez WooCommerce JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Countdown functionality
    var countdownEl = document.querySelector('.breez-countdown');
    var countdownContainer = document.querySelector('.breez-payment-countdown');
    
    if (countdownEl && countdownContainer) {
        var expiryTime = parseInt(countdownContainer.dataset.expiry, 10);
        
        if (expiryTime) {
            var countdownInterval = setInterval(function() {
                var now = Math.floor(Date.now() / 1000);
                var timeLeft = expiryTime - now;
                
                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                    countdownContainer.innerHTML = '<p>Payment time expired.</p>';
                    document.querySelector('.breez-payment-qr').style.opacity = '0.3';
                    
                    // Reload page to show expired message
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                    
                    return;
                }
                
                var minutes = Math.floor(timeLeft / 60);
                var seconds = timeLeft % 60;
                countdownEl.textContent = (minutes < 10 ? '0' : '') + minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            }, 1000);
        }
    }
    
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
                this.textContent = 'Copied!';
                setTimeout(function() {
                    copyButton.textContent = 'Copy';
                }, 2000);
            } catch (err) {
                console.error('Failed to copy: ', err);
            }
            
            document.body.removeChild(textarea);
        });
    }
    
    // Auto-refresh payment status
    function checkPaymentStatus() {
        var orderStatusCheck = document.querySelector('.breez-payment-box');
        if (orderStatusCheck && !document.querySelector('.breez-payment-completed') && !document.querySelector('.breez-payment-failed') && !document.querySelector('.breez-payment-expired')) {
            // Get current URL
            var currentUrl = window.location.href;
            
            // Append a random parameter to force a fresh response
            var refreshUrl = currentUrl + (currentUrl.indexOf('?') > -1 ? '&' : '?') + '_=' + Math.random();
            
            // Make AJAX request to check status
            var xhr = new XMLHttpRequest();
            xhr.open('GET', refreshUrl, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    // Parse HTML response
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(xhr.responseText, 'text/html');
                    
                    // Find payment box in the response
                    var newPaymentBox = doc.querySelector('.breez-payment-box');
                    
                    if (newPaymentBox) {
                        // Check if payment is now completed
                        if (newPaymentBox.querySelector('.breez-payment-completed')) {
                            orderStatusCheck.innerHTML = newPaymentBox.innerHTML;
                            clearInterval(statusInterval);
                        }
                        // Check if payment has failed
                        else if (newPaymentBox.querySelector('.breez-payment-failed')) {
                            orderStatusCheck.innerHTML = newPaymentBox.innerHTML;
                            clearInterval(statusInterval);
                        }
                    }
                }
            };
            xhr.send();
        } else {
            // If payment is already completed or failed, stop checking
            clearInterval(statusInterval);
        }
    }
    
    // Check every 10 seconds
    var statusInterval = setInterval(checkPaymentStatus, 10000);
});
