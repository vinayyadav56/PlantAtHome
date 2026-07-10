<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identity module (v2) — JWT auth
    |--------------------------------------------------------------------------
    | Self-contained config for the modular-monolith Identity context. Tokens
    | are short-lived HS256 JWTs (access) plus opaque, rotating refresh tokens
    | persisted server-side so they can be revoked. The signing secret falls
    | back to APP_KEY so no extra env var is required to boot.
    */
    'jwt' => [
        'secret'      => env('IDENTITY_JWT_SECRET', env('APP_KEY')),
        'algo'        => 'HS256',
        'issuer'      => env('IDENTITY_JWT_ISSUER', env('APP_URL', 'plantathome')),
        // Access token lifetime (seconds). Short — refresh to extend a session.
        'access_ttl'  => (int) env('IDENTITY_ACCESS_TTL', 15 * 60),
        // Refresh token lifetime (seconds). 14 days by default.
        'refresh_ttl' => (int) env('IDENTITY_REFRESH_TTL', 14 * 24 * 60 * 60),
        // Leeway (seconds) to tolerate small clock skew on exp/nbf.
        'leeway'      => (int) env('IDENTITY_JWT_LEEWAY', 10),
    ],
];
