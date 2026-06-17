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

    // PlantAtHome — multi-partner shipping. CourierService resolves EVERY partner whose code is
    // listed in COURIER_PROVIDER (comma-separated, e.g. "shiprocket,borzo"). Each lane is inert
    // until its code is listed AND its credentials are set; the admin master switch
    // (settings.options.courier.enabled) gates them all. Secrets are env-only — never committed.
    'courier' => [
        // Which partners are wired this environment. CSV: shiprocket | borzo | porter | ...
        'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('COURIER_PROVIDER', ''))))),
    ],

    // Shiprocket — cross-city / intercity courier aggregator (+ COD).
    'shiprocket' => [
        'enabled'       => in_array('shiprocket', array_map('trim', explode(',', (string) env('COURIER_PROVIDER', ''))), true),
        'email'         => env('SHIPROCKET_EMAIL'),
        'password'      => env('SHIPROCKET_PASSWORD'),
        // Optional: a permanent API token. When set, it is used directly as the bearer and the
        // email/password login is skipped (handles cabinet "API token" style credentials).
        'api_token'     => env('SHIPROCKET_API_TOKEN'),
        'base_url'      => env('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'),
        'webhook_token' => env('SHIPROCKET_WEBHOOK_TOKEN'),
    ],

    // Borzo (ex-WeFast) — on-demand intra-city / same-city instant delivery (+ cash-on-delivery).
    // Auth: X-DV-Auth-Token header. Test host robotapitest-in.*, prod host robot-in.* — set
    // BORZO_BASE_URL per environment. Default vehicle 8 = bike (small parcels / plants).
    'borzo' => [
        'enabled'         => in_array('borzo', array_map('trim', explode(',', (string) env('COURIER_PROVIDER', ''))), true),
        'token'           => env('BORZO_TOKEN'),
        'base_url'        => env('BORZO_BASE_URL', 'https://robot-in.borzodelivery.com/api/business/1.8'),
        'callback_token'  => env('BORZO_CALLBACK_TOKEN'),
        'vehicle_type_id' => (int) env('BORZO_VEHICLE_TYPE_ID', 8),
        'matter'          => env('BORZO_MATTER', 'Plants & garden supplies'),
    ],

];
