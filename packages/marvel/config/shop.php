<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin email configuration
    |--------------------------------------------------------------------------
    |
    | Set the admin email. This will be used to send email when user contact through contact page.
    |
    */
    'admin_email' => env('ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Vendor KYC deadline
    |--------------------------------------------------------------------------
    |
    | Vendors may register without their documents so onboarding is never
    | blocked. `window_days` is how long they then have to supply them before
    | marvel:sweep-kyc-deadlines puts the account on hold; `warn_before_days`
    | is how far ahead of that the warning email goes out. Admins can extend an
    | individual vendor's deadline from the vendor page.
    |
    */
    'kyc' => [
        'window_days'      => (int) env('VENDOR_KYC_WINDOW_DAYS', 15),
        'warn_before_days' => (int) env('VENDOR_KYC_WARN_BEFORE_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shop url configuration
    |--------------------------------------------------------------------------
    |
    | Shop url is used in order placed template to go to shop order page.
    |
    */
    'shop_url' => env('SHOP_URL'),

    'dashboard_url' => env('DASHBOARD_URL'),

    'media_disk' => env('MEDIA_DISK'),

    'version' => env('APP_VERSION', 12),

    'stripe_api_key' => env('STRIPE_API_KEY'),

    // Error/message translation-key prefix (e.g. PLANTATHOME_ERROR.NOT_FOUND).
    // MUST match the keys the admin/shop locale files define — the old MARVEL_
    // default emitted keys the frontends never had, so users saw raw
    // "MARVEL_ERROR.*" strings in toasts.
    'app_notice_domain' => env('APP_NOTICE_DOMAIN', 'PLANTATHOME_'),

    'dummy_data_path' => env('DUMMY_DATA_PATH', 'plantathome'),

    'default_language' => env('DEFAULT_LANGUAGE', 'en'),

    'translation_enabled' => env('TRANSLATION_ENABLED', true),

    'default_currency' => env('DEFAULT_CURRENCY', 'USD'),

    'active_payment_gateway' => env('ACTIVE_PAYMENT_GATEWAY', 'stripe'),

    /*
     * Oversell policy for the atomic stock decrement at order creation.
     *   'log'   — (default, legacy behavior) a 0-row decrement logs a warning
     *             and the order proceeds; vendors fulfil on demand.
     *   'block' — a 0-row decrement throws InsufficientStockException (422)
     *             inside the order transaction, rolling the whole order back —
     *             inventory=1 with N concurrent buyers sells exactly once.
     * Flip per-environment AFTER verifying published products carry maintained
     * quantity counters (see docs/operations/production-readiness.md).
     */
    'inventory_oversell_policy' => env('INVENTORY_OVERSELL_POLICY', 'log'),

    // Hours before an unpaid PREPAID order is auto-cancelled by
    // orders:cancel-stale-unpaid (stock + coupon slot return). COD exempt.
    'stale_unpaid_order_hours' => (int) env('STALE_UNPAID_ORDER_HOURS', 24),

    /*
     * Gateways whose PUBLIC webhook endpoints are live. 11 gateway classes ship
     * with the platform but this business uses Razorpay (+COD); every other
     * /webhooks/{gateway} URL is a dead, unauthenticated surface. Endpoints not
     * on this list 404 before touching any payment code. Razorpay additionally
     * requires its webhook secret to be configured. CSV env, e.g.
     * WEBHOOK_GATEWAYS=razorpay
     */
    'enabled_webhook_gateways' => array_filter(array_map(
        'trim',
        explode(',', strtolower((string) env('WEBHOOK_GATEWAYS', 'razorpay,stripe,paypal')))
    )),

    'razorpay' => [
        // Accept either env naming — RAZORPAY_KEY_ID/SECRET (canonical, staging) or the
        // legacy RAZORPAY_KEY/SECRET — so a prod box set up from the old example still works.
        'key_id'         => env('RAZORPAY_KEY_ID', env('RAZORPAY_KEY')),
        'key_secret'     => env('RAZORPAY_KEY_SECRET', env('RAZORPAY_SECRET')),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET_KEY', env('RAZORPAY_WEBHOOK_SECRET'))
    ],

    'mollie' => [
        'mollie_key'  => env('MOLLIE_KEY'),
        'webhook_url' => env('MOLLIE_WEBHOOK_URL'),
    ],

    'stripe' => [
        'api_secret'     => env('STRIPE_API_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET_KEY')
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    'xendit' => [
        'api_key'               => env('API_KEY'),
        'webhook_url'           => env('XENDIT_WEBHOOK_URL'),
        'xendit_callback_token' => env('XENDIT_CALLBACK_TOKEN'),
    ],

    'paymongo' => [
        'public_key' => env('PAYMONGO_SECRET_KEY'),
        'secret_key' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_sig' => env('PAYMONGO_WEBHOOK_SIG'),
    ],

    'paypal' => [
        'mode'           => env('PAYPAL_MODE', 'sandbox'), // Can only be 'sandbox' Or 'live'. If empty or invalid, 'live' will be used.
        'sandbox'        => [
            'client_id'     => env('PAYPAL_SANDBOX_CLIENT_ID', ''),
            'client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET', ''),
        ],
        'live'           => [
            'client_id'     => env('PAYPAL_LIVE_CLIENT_ID', ''),
            'client_secret' => env('PAYPAL_LIVE_CLIENT_SECRET', ''),
        ],
        'payment_action' => env('PAYPAL_PAYMENT_ACTION', 'Sale'), // Can only be 'Sale', 'Authorization' or 'Order'
        'webhook_id'     => env('PAYPAL_WEBHOOK_ID'),
        'currency'       => env('PAYPAL_CURRENCY', 'USD'),
        'notify_url'     => env('PAYPAL_NOTIFY_URL', ''), // Change this accordingly for your application.
        'locale'         => env('PAYPAL_LOCALE', 'en_US'), // force gateway language  i.e. it_IT, es_ES, en_US ... (for express checkout only)
        'validate_ssl'   => env('PAYPAL_VALIDATE_SSL', true), // Validate SSL when creating api client.
    ],

    'sslcommerz' => [
        'store_id'       => env('SSLC_STORE_ID'),
        'store_password' => env('SSLC_STORE_PASSWORD'),
    ],
    'iyzico' => [
        'api_Key'    => env('IYZIPAY_API_KEY', ''),
        'secret_Key' => env('IYZIPAY_SECRET_KEY', ''),
        'baseUrl'    => env('IYZIPAY_BASE_URL', 'https://sandbox-api.iyzipay.com'),
    ],
    'bkash' => [
        'app_Key'      => env('BKASH_APP_KEY', ''),
        'app_secret'   => env('BKASH_APP_SECRET', ''),
        'username'     => env('BKASH_USERNAME', ''),
        'password'     => env('BKASH_PASSWORD', ''),
        'callback_url' => env('BKASH_CALLBACK_URL', 'http://127.0.0.1:8000/bkash/callback'),
    ],
    'flutterwave' => [
        'public_key' => env('FLW_PUBLIC_KEY'),
        'secret_key' => env('FLW_SECRET_KEY'),
        'secret_hash' => env('FLW_SECRET_HASH'),
    ],

    'openai' => [
        'secret_Key' => env('OPENAI_SECRET_KEY'),
    ],

    'pusher' => [
        'enabled' => env('PUSHER_ENABLED', false),
    ]
];
