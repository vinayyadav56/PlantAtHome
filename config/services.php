<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // PlantAtHome — plant image fetch pipeline
    'pixabay' => [
        'key' => env('PIXABAY_API_KEY'),
    ],

    // PlantAtHome — MSG91 OTP gateway (phone signup). DLT-registered sender + template.
    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'template_id' => env('MSG91_TEMPLATE_ID'),
        'sender' => env('MSG91_SENDER'),
        // optional non-OTP SMS flow id
        'flow_id' => env('MSG91_FLOW_ID'),
    ],

    // PlantAtHome — WhatsApp Business (Meta Cloud API): login OTP + order
    // notifications. Both flow through WhatsappGateway when ACTIVE_OTP_GATEWAY=whatsapp.
    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'otp_template' => env('WHATSAPP_OTP_TEMPLATE'),
        'otp_lang' => env('WHATSAPP_OTP_LANG', 'en'),
        'otp_has_button' => env('WHATSAPP_OTP_HAS_BUTTON', false),
        'notify_template' => env('WHATSAPP_NOTIFY_TEMPLATE'),
        'notify_lang' => env('WHATSAPP_NOTIFY_LANG', 'en'),
    ],

    // PlantAtHome — LinkedIn ("Sign In with LinkedIn using OpenID Connect").
    // Web posts the NextAuth access_token to /social-login-token; the native app sends
    // the auth code to /social-login/linkedin/exchange (secret stays server-side).
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI'),
    ],

    // Partner credentials (Borzo / Shiprocket / Porter) live EXCLUSIVELY in the Go shipping-service
    // env — the monolith holds only the service link below and never calls a partner API directly.

    // Dedicated Go shipping microservice — the ONLY shipping path. CourierService delegates
    // quote/book/cancel/track to it (X-Api-Key); status/COD flow back via POST /api/shipping/callback.
    // Gated solely by the admin master switch (settings.options.courier.enabled) + this link.
    'shipping_service' => [
        'url'          => env('SHIPPING_SERVICE_URL'),                                   // e.g. https://shipping-staging.up.railway.app
        'api_key'      => env('SHIPPING_SERVICE_API_KEY'),                               // sent as X-Api-Key when calling the service
        'callback_key' => env('SHIPPING_SERVICE_CALLBACK_KEY', env('SHIPPING_SERVICE_API_KEY')), // expected X-Api-Key on inbound callbacks
        'timeout'      => (int) env('SHIPPING_SERVICE_TIMEOUT', 25),
    ],

    // External competitor-catalogue intelligence service (NurseryLive + Ugaoo scrape).
    // Read-only source for the admin Market Intelligence page: bulk plant-name import
    // into the master catalogue + price watchlist/snapshots. Sent as X-Api-Key if set.
    'market_intelligence' => [
        'url'     => env('MARKET_INTEL_SERVICE_URL', 'https://plant-processings-production.up.railway.app'),
        'api_key' => env('MARKET_INTEL_SERVICE_API_KEY'),
        'timeout' => (int) env('MARKET_INTEL_SERVICE_TIMEOUT', 30),
        // Direct Shopify catalogue feeds (full product lists) for the name import —
        // the intelligence service above only holds a partial scrape.
        'nurserylive_url' => env('NURSERYLIVE_CATALOG_URL', 'https://nurserylive.com'),
    ],

];
