<?php

/**
 * Centralized media system (media_items / media_item_versions).
 *
 * `env` decides which S3 prefix this deployment may WRITE (media/p/ vs
 * media/s/) and which versions it may physically delete. It deliberately does
 * NOT use APP_ENV — staging reports 'production' there (see TESTDATA_ENV
 * precedent). Default resolves from the one signal that already distinguishes
 * the environments: staging runs on Railway.
 */
return [
    'env' => env('MEDIA_ENV', env('RAILWAY_PUBLIC_DOMAIN') ? 'staging' : 'production'),

    // S3 key prefix segment per env. Keys: media/{segment}/{hint}/{uuid}/v{n}/…
    'env_prefixes' => [
        'production' => 'p',
        'staging' => 's',
    ],

    // Days a RETIRED version's objects survive before media:gc may purge them.
    // MUST exceed the staging DB-refresh cadence: a stale staging DB pointing
    // at a just-retired prod object is the one cross-env hazard.
    'retired_retention_days' => (int) env('MEDIA_RETIRED_RETENTION_DAYS', 30),

    // Minimum object age before the staging orphan sweep may delete a
    // media/s/ key with no version row (protects in-flight uploads).
    'orphan_min_age_days' => (int) env('MEDIA_ORPHAN_MIN_AGE_DAYS', 7),

    // Queue for variant generation. 'images' exists on BOTH prod systemd and
    // staging supervisord — introducing a new queue name requires editing both.
    'queue' => env('MEDIA_QUEUE', 'images'),

    // Derivative widths (webp). Original is stored untouched alongside.
    'variants' => [
        'thumbnail' => 368,
        'small' => 480,
        'medium' => 800,
        'large' => 1600,
    ],

    // One limit for every upload path (resolves the historic 5MB request vs
    // 10MB media-library mismatch).
    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 10240),
];
