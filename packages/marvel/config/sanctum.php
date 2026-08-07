<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1,127.0.0.1:8000,::1')),

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. If this value is null, personal access tokens do
    | not expire. This won't tweak the lifetime of first-party sessions.
    |
    */


    /*
     * SECURITY 2026-08-07: this was null — personal access tokens NEVER expired. A token
     * captured once (from the JS-readable cookie the web clients use, a device backup, a
     * proxy log) stayed valid forever, and logout only revoked the single token presented.
     *
     * ⚠️ CHANGING THIS LOGS PEOPLE OUT. Sanctum measures expiry from the token's created_at,
     * so the moment a value lands, every token older than the window dies at once — a mass
     * logout of web and mobile, not a gradual rollover.
     *
     * SANCTUM_EXPIRATION_MINUTES is deliberately env-driven and defaults to null so that
     * DEPLOYING THIS COMMIT CHANGES NOTHING. Turning it on is an explicit operational act:
     * set it in a low-traffic window, start generous (129600 = 90 days) and ratchet down,
     * and tell people first. Pair it with POST /logout-all-devices (added alongside this)
     * so a user who suspects theft has a remedy that does not require waiting for expiry.
     */
    'expiration' => env('SANCTUM_EXPIRATION_MINUTES') !== null
        ? (int) env('SANCTUM_EXPIRATION_MINUTES')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
    ],

];
