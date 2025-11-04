<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Payment Service module. This service provides
    | a loosely coupled payment integration that can be used across multiple
    | applications.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    |
    | Currently supported: razorpay
    | You can extend this by implementing PaymentServiceInterface
    |
    */
    'provider' => env('PAYMENT_PROVIDER', 'razorpay'),

    /*
    |--------------------------------------------------------------------------
    | Razorpay Configuration
    |--------------------------------------------------------------------------
    |
    | Razorpay API credentials. These can be set via environment variables
    | or directly in this config file.
    |
    */
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY'),
        'key_secret' => env('RAZORPAY_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how payment service routes are registered.
    |
    */
    'routes' => [
        'enabled' => env('PAYMENT_SERVICE_ROUTES_ENABLED', true),
        'prefix' => env('PAYMENT_SERVICE_ROUTES_PREFIX', 'payment-service'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('PAYMENT_DEFAULT_CURRENCY', 'INR'),

    /*
    |--------------------------------------------------------------------------
    | Payment Capture
    |--------------------------------------------------------------------------
    |
    | Whether to auto-capture payments when orders are created.
    | Set to 0 for manual capture, 1 for automatic capture.
    |
    */
    'payment_capture' => env('PAYMENT_CAPTURE', 1),

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | When demo mode is enabled, the service will simulate payment operations
    | without making actual API calls. This is useful for testing.
    |
    */
    'demo_mode' => env('PAYMENT_DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Callback URLs
    |--------------------------------------------------------------------------
    |
    | Default callback URLs for payment webhooks and redirects.
    |
    */
    'callbacks' => [
        'success_url' => env('PAYMENT_SUCCESS_URL', '/payment-success'),
        'failure_url' => env('PAYMENT_FAILURE_URL', '/payment-failure'),
        'webhook_url' => env('PAYMENT_WEBHOOK_URL', '/payment-service/webhook'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    |
    | Default payment methods enabled for payment links.
    |
    */
    'payment_methods' => [
        'card' => true,
        'netbanking' => true,
        'wallet' => true,
        'upi' => true,
        'emi' => false,
    ],
];

