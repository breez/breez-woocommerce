/**
 * Breez Payment Method for WooCommerce Blocks
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement, useEffect } = window.wp.element;
const { Fragment } = window.wp.element;
const { decodeEntities } = window.wp.htmlEntities;
const { PaymentMethodLabel, PaymentMethodIcon } = window.wc.blocksComponents || {};

const BreezLabel = () => {
    return createElement('div', { className: 'wc-block-components-payment-method-label' },
        createElement('span', {}, window.wcSettings?.breez?.title || 'Breez Nodeless Payments')
    );
};

const BreezComponent = () => {
    const description = window.wcSettings?.breez?.description || 'Pay with Lightning';
    return createElement('div', { className: 'wc-block-components-payment-method-description' },
        decodeEntities(description)
    );
};

// Payment processing component
const ProcessPayment = ({ eventRegistration }) => {
    const { onPaymentProcessing } = eventRegistration;

    useEffect(() => {
        const unsubscribe = onPaymentProcessing(() => {
            console.log('Breez payment processing started');
            try {
                return {
                    type: 'success',
                    meta: {
                        paymentMethodData: {
                            payment_method: 'breez',
                            breez_payment_method: window.wcSettings?.breez?.defaultPaymentMethod || 'lightning'
                        }
                    }
                };
            } catch (error) {
                console.error('Breez payment processing error:', error);
                return {
                    type: 'error',
                    message: error.message || 'Payment processing failed'
                };
            }
        });

        return () => {
            unsubscribe();
        };
    }, [onPaymentProcessing]);

    return null;
};

// Register the Breez payment method
const breezPaymentMethod = {
    name: 'breez',
    label: createElement(BreezLabel),
    content: createElement(BreezComponent),
    edit: createElement(BreezComponent),
    canMakePayment: () => true,
    ariaLabel: window.wcSettings?.breez?.title || 'Breez Nodeless Payments',
    paymentMethodId: 'breez',
    supports: {
        features: window.wcSettings?.breez?.supports || ['products'],
        showSavedCards: false,
        showSaveOption: false
    },
    billing: {
        required: false
    },
    data: {
        breez_payment_method: window.wcSettings?.breez?.defaultPaymentMethod || 'lightning'
    },
    placeOrderButtonLabel: window.wcSettings?.breez?.orderButtonText || 'Pay with Bitcoin',
    processPayment: createElement(ProcessPayment)
};

try {
    registerPaymentMethod(breezPaymentMethod);
    console.log('Breez payment method registered successfully');
} catch (error) {
    console.error('Failed to register Breez payment method:', error);
} 