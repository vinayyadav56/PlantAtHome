<?php

declare(strict_types=1);

/**
 * Marketing Automation module (App\Modules\Marketing) runtime config. Every knob
 * is env-overridable so staging/prod can tune throughput and safety without a
 * deploy. Defaults are conservative — a marketing blast must never starve the
 * critical `default` notification queue or hammer a provider.
 */
return [
    // Queue that marketing send/materialize jobs run on. Kept OFF the `default`
    // queue (which carries OTP/order mail) so a large campaign can't delay them.
    // A dedicated supervisord worker consumes it (see .railway/supervisord.conf).
    'queue' => env('MARKETING_QUEUE', 'marketing'),

    'audience' => [
        // Hard cap on how many rows a saved audience snapshot may freeze. Larger
        // result sets are truncated (the version records truncated=true).
        'max_rows'        => (int) env('MARKETING_AUDIENCE_MAX_ROWS', 100000),
        // Rows shown in the preview pane.
        'preview_rows'    => (int) env('MARKETING_AUDIENCE_PREVIEW_ROWS', 100),
        // MySQL statement timeout for an audience SELECT (ms). Best-effort — set
        // via SET SESSION MAX_EXECUTION_TIME; silently skipped on non-MySQL.
        'max_exec_ms'     => (int) env('MARKETING_AUDIENCE_MAX_EXEC_MS', 15000),
        // Optional table allow-list (comma-separated). Empty = any table the
        // app's DB user can read. When set, an audience SQL that references a
        // table outside the list is rejected.
        'allowed_tables'  => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARKETING_AUDIENCE_ALLOWED_TABLES', ''))
        ))),
    ],

    'campaign' => [
        'default_batch_size'     => (int) env('MARKETING_DEFAULT_BATCH_SIZE', 1000),
        'default_max_retries'    => (int) env('MARKETING_DEFAULT_MAX_RETRIES', 3),
        // Per-send-job pacing ceiling (messages/minute); 0 = no pacing.
        'default_throttle_per_min' => (int) env('MARKETING_DEFAULT_THROTTLE_PER_MIN', 0),
        'default_timezone'       => env('MARKETING_DEFAULT_TIMEZONE', 'Asia/Kolkata'),
        // How many notification rows a single Send*Job processes (a "batch").
        'send_chunk'             => (int) env('MARKETING_SEND_CHUNK', 1000),
    ],

    // Master kill-switch: when false, jobs are materialized + queued but the
    // channel adapters short-circuit to a dry-run (nothing leaves the building).
    // Lets you rehearse a campaign end-to-end on staging without real sends.
    'dispatch_enabled' => filter_var(env('MARKETING_DISPATCH_ENABLED', true), FILTER_VALIDATE_BOOL),
];
