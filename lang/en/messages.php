<?php

/**
 * PayPal plugin server messages (English)
 */
return [
    'checkout_created' => 'Redirecting to PayPal.',
    'order_description_more' => 'and :count more',

    'errors' => [
        'not_configured' => 'PayPal credentials (Client ID and Secret) are not set. Enter the keys for the current mode (sandbox/live) in the plugin settings.',
        'connection_failed' => 'Could not connect to PayPal. Check outbound access to api-m.paypal.com from the server.',
        'auth_failed' => 'PayPal authentication failed. Check the Client ID and Secret.',
        'currency_not_allowed' => 'PayPal is not available for this currency.',
        'invalid_amount' => 'This amount cannot be paid with PayPal.',
        'order_not_payable' => 'This order can no longer be paid.',
        'payment_method_mismatch' => 'This order was not placed with PayPal.',
        'create_failed' => 'Could not start PayPal checkout. Please try again shortly.',
        'too_many_attempts' => 'Too many requests. Please try again shortly.',
        'order_not_found' => 'Order not found.',
        'ownership_failed' => 'Order ownership verification failed.',
        'amount_mismatch' => 'The paid amount did not match the order total, so the payment was refunded.',
        'USER_CANCEL' => 'You cancelled the PayPal payment.',
        'capture_failed' => 'The PayPal payment could not be completed. Please try again.',
        'capture_declined' => 'PayPal declined the payment. Please use another payment method.',
        'token_mismatch' => 'The payment does not match this order.',
        'callback_locked' => 'Your payment is being processed. Please check your order shortly.',
        'capture_mismatch' => 'The payment does not match this order.',
        'paypal_order_mismatch' => 'The payment does not match this order.',
        'invalid_params' => 'Invalid payment request.',
    ],

    'refund' => [
        'default_note' => 'Refund for order cancellation',
        'verification_failed_note' => 'Automatically refunded because payment verification failed.',
        'missing_capture_id' => 'Cannot refund: the PayPal capture ID is missing.',
        'invalid_amount' => 'Invalid refund amount.',
        'not_accepted' => 'PayPal did not process the refund.',
        'api_error' => 'PayPal refund failed. (:code)',
        'issues' => [
            'REFUND_AMOUNT_EXCEEDED' => 'The refund exceeds the remaining captured amount.',
            'CAPTURE_FULLY_REFUNDED' => 'This payment has already been fully refunded.',
            'REFUND_TIME_LIMIT_EXCEEDED' => 'The PayPal refund window (180 days) has passed.',
            'TRANSACTION_REFUSED' => 'PayPal refused the refund. Check the PayPal dashboard.',
            'INSUFFICIENT_FUNDS' => 'The PayPal account balance is insufficient for this refund.',
        ],
    ],

    'claim' => [
        'description' => 'Exchange/return additional payment :number',
        'failed' => 'The PayPal additional payment could not be completed. (:code)',
    ],

    'settings' => [
        'redirect_url_format' => 'The address must start with {shopBase}, / or http(s):// and contain no spaces.',
    ],
];
