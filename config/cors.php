<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://plantathome-shop-staging.vercel.app',
        'https://plantathome-admin-staging.vercel.app',
        'https://plantathome.in',
        'https://admin.plantathome.in',
        'http://localhost:3002',
        'http://localhost:3003',
    ],

    'allowed_origins_patterns' => [
        '#^https://plantathome-.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Cache preflight verdicts for a day. At 0, every browser request that carries an
    // Authorization header paid a full OPTIONS round trip (~300-500ms through Cloudflare)
    // before the real request — twice the latency on every authenticated call.
    'max_age' => 86400,

    'supports_credentials' => false,

];
