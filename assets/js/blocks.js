/**
 * Breez Payment Method for WooCommerce Blocks
 * Compatible with WooCommerce Blocks version 9.x
 */
(function() {
    // Safe console logging
    var log = function(msg) {
        if (window.console && window.console.log) {
            console.log('[Breez] ' + msg);
        }
    };

    // Register payment method function
    function registerBreezPaymentMethod(registerFn) {
        try {
            // Create React element for content
            var Content = function() {
                return window.wp.element.createElement(
                    'div',
                    null,
                    window.breezSettings?.description || '.'
                );
            };

            registerFn({
                name: 'breez',
                label: window.breezSettings?.title || 'Breez Nodeless Payments',
                content: window.wp.element.createElement(Content, null),
                edit: window.wp.element.createElement(Content, null),
                canMakePayment: function() { return true; },
                ariaLabel: window.breezSettings?.title || 'Breez Nodeless Payments',
                supports: {
                    features: window.breezSettings?.supports || ['products']
                },
                paymentMethodId: 'breez',
                billing: {
                    required: true
                },
                // Add data to be sent to the server
                getData: function() {
                    log('Getting payment data');
                    return {
                        payment_method: 'breez',
                        payment_data: {
                            breez_payment_method: 'LIGHTNING'
                        }
                    };
                },
                // Add payment processing
                onSubmit: function(data) {
                    log('Processing payment submission');
                    return {
                        type: 'success',
                        meta: {
                            paymentMethodData: {
                                payment_method: 'breez',
                                payment_data: {
                                    breez_payment_method: 'LIGHTNING'
                                }
                            }
                        }
                    };
                },
                // Add payment processing status
                onPaymentProcessing: function() {
                    log('Payment processing started');
                    return {
                        type: 'success',
                        meta: {
                            paymentMethodData: {
                                payment_method: 'breez',
                                payment_data: {
                                    breez_payment_method: 'LIGHTNING'
                                }
                            }
                        }
                    };
                }
            });
            log('Payment method registered successfully');
        } catch (error) {
            log('Error registering payment method: ' + error.message);
        }
    }

    // Wait for DOM content to be loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Only run on checkout page
        if (!document.body.classList.contains('woocommerce-checkout')) {
            return;
        }
        
        log('Initializing Breez payment method for WooCommerce Blocks 9.x');
        
        // Check if blocks object exists in newer location
        if (window.wc && window.wc.blocks && window.wc.blocks.registry && 
            typeof window.wc.blocks.registry.registerPaymentMethod === 'function') {
            
            log('Found WC Blocks registry in wc.blocks.registry');
            registerBreezPaymentMethod(window.wc.blocks.registry.registerPaymentMethod);
            
        } 
        // Try fallback locations
        else if (window.wc && window.wc.wcBlocksRegistry && 
                 typeof window.wc.wcBlocksRegistry.registerPaymentMethod === 'function') {
            
            log('Found WC Blocks registry in wc.wcBlocksRegistry');
            registerBreezPaymentMethod(window.wc.wcBlocksRegistry.registerPaymentMethod);
            
        } else {
            // Log available WooCommerce structure to help debug
            var wcPaths = [];
            
            if (window.wc) {
                wcPaths.push('wc');
                
                if (window.wc.blocks) {
                    wcPaths.push('wc.blocks');
                    
                    if (window.wc.blocks.registry) {
                        wcPaths.push('wc.blocks.registry');
                        if (typeof window.wc.blocks.registry.registerPaymentMethod === 'function') {
                            wcPaths.push('wc.blocks.registry.registerPaymentMethod (function)');
                        }
                    }
                    
                    if (window.wc.blocks.payment) {
                        wcPaths.push('wc.blocks.payment');
                    }
                }
                
                if (window.wc.wcBlocksRegistry) {
                    wcPaths.push('wc.wcBlocksRegistry');
                    if (typeof window.wc.wcBlocksRegistry.registerPaymentMethod === 'function') {
                        wcPaths.push('wc.wcBlocksRegistry.registerPaymentMethod (function)');
                    }
                }
            }
            
            if (wcPaths.length > 0) {
                log('WooCommerce paths found: ' + wcPaths.join(', '));
            } else {
                log('No WooCommerce Blocks registry found. Payment method registration not possible.');
            }
        }
    });
})(); 