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

    // PlantAtHome — Shiprocket courier aggregator (cross-city fulfilment + COD).
    // Provider-agnostic: CourierService resolves the active provider from COURIER_PROVIDER.
    // Inert until enabled + credentials set; secrets are env-only (never committed).
    'shiprocket' => [
        'enabled'       => env('COURIER_PROVIDER') === 'shiprocket',
        'email'         => env('SHIPROCKET_EMAIL'),
        'password'      => env('SHIPROCKET_PASSWORD'),
        'base_url'      => env('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'),
        'webhook_token' => env('SHIPROCKET_WEBHOOK_TOKEN'),
    ],

];
