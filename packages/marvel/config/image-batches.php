<?php

/**
 * Batch AI image generation (admin AI Image Generator → Background Batches).
 *
 * Everything the admin UI offers as a dropdown comes from THIS file via
 * GET /ai-image-batches/capabilities — no provider capability (model, size,
 * quality, style, background, price) is hardcoded in the frontend or jobs,
 * so OpenAI catalogue/pricing drift is a config edit, not a code change.
 */
return [
    // Dedicated queue so multi-hour image batches never starve fast
    // notification jobs (mirrors the careplans/marketing worker split).
    'queue' => env('IMAGE_BATCH_QUEUE', 'images'),

    // Instant admin previews get their own worker so they never wait behind
    // a running batch.
    'instant_queue' => env('IMAGE_INSTANT_QUEUE', 'images-instant'),

    // Global pacing across a batch: images generated per minute. gpt-image-1
    // orgs commonly allow ~5 img/min; raise via env once limits are verified.
    'rate_per_minute' => (float) env('IMAGE_BATCH_RATE_PER_MINUTE', 5),

    // Sheet + settings guardrails.
    'max_rows'             => (int) env('IMAGE_BATCH_MAX_ROWS', 2000),
    'max_images_per_plant' => (int) env('IMAGE_BATCH_MAX_IMAGES_PER_PLANT', 10),
    'max_reference_images' => 4,
    'max_file_mb'          => 10, // spreadsheet + each reference image

    // Hard cost ceiling (USD) — an upload whose estimate exceeds this is
    // rejected with the estimate, forcing a deliberate split/quality drop.
    'max_estimated_cost' => (float) env('IMAGE_BATCH_MAX_COST', 200),

    // Per-row generation attempts (row-level; infra job retries are separate).
    'row_max_attempts' => 3,

    // Cooldown before a `retrying` row may be claimed again (0 in tests).
    'retry_cooldown_seconds' => 60,

    // Sweeper thresholds (minutes).
    'stall_minutes'      => 30, // processing row with no heartbeat → re-pend
    // Active batch with no live job → re-dispatch. This is also what re-drives
    // rows cooling down in `retrying` (the job chain deliberately ends rather
    // than spin-wait), so keep it short; double-dispatch is safe (atomic claims).
    'redispatch_minutes' => (int) env('IMAGE_BATCH_REDISPATCH_MINUTES', 2),

    // Days generated files + zips are kept before the daily prune. Audit rows
    // are never deleted — only the files.
    'retention_days' => (int) env('IMAGE_BATCH_RETENTION_DAYS', 30),

    // OpenAI HTTP timeout per image (high-quality gpt-image-1 can take ~60s+).
    'request_timeout' => (int) env('IMAGE_BATCH_REQUEST_TIMEOUT', 300),

    // ZIP packaging: parts are capped so temp-disk use stays bounded
    // (~2× one part peak). PNGs are STOREd, not compressed.
    'zip' => [
        'max_part_bytes' => (int) env('IMAGE_BATCH_ZIP_PART_BYTES', 1_500_000_000),
    ],

    's3_prefix' => 'ai-batches',

    /*
     * Provider capability + price matrix. sizes are ordered highest → lowest,
     * qualities lowest → highest — the admin renders them in this order.
     * cost_per_image is USD per generated image (verify against
     * platform.openai.com/pricing when adding models).
     */
    'models' => [
        'gpt-image-1' => [
            'label'     => 'GPT Image 1',
            'sizes'     => ['1536x1024', '1024x1536', '1024x1024', 'auto'],
            'qualities' => ['low', 'medium', 'high', 'auto'],
            // gpt-image-1 has no style API param — each style folds its
            // suffix into the prompt (style_prompts).
            'styles'        => ['natural', 'vivid', 'photorealistic', 'studio', 'botanical-illustration'],
            'style_prompts' => [
                'natural'                => 'Natural, true-to-life colours and soft daylight',
                'vivid'                  => 'Vivid, dramatic lighting with rich saturated colours',
                'photorealistic'        => 'Photorealistic product photography, sharp focus, high detail',
                'studio'                 => 'Clean studio product shot with soft diffused lighting',
                'botanical-illustration' => 'Detailed vintage botanical illustration style',
            ],
            // Real API param on gpt-image-1.
            'backgrounds'               => ['auto', 'opaque', 'transparent'],
            'supports_reference_images' => true,
            'cost_per_image' => [
                'low'    => ['1024x1024' => 0.011, '1536x1024' => 0.016, '1024x1536' => 0.016, 'auto' => 0.016],
                'medium' => ['1024x1024' => 0.042, '1536x1024' => 0.063, '1024x1536' => 0.063, 'auto' => 0.063],
                'high'   => ['1024x1024' => 0.167, '1536x1024' => 0.25,  '1024x1536' => 0.25,  'auto' => 0.25],
                // 'auto' may resolve to any tier — budget at the highest.
                'auto'   => ['1024x1024' => 0.167, '1536x1024' => 0.25,  '1024x1536' => 0.25,  'auto' => 0.25],
            ],
        ],
        'dall-e-3' => [
            'label'     => 'DALL·E 3',
            'sizes'     => ['1792x1024', '1024x1792', '1024x1024'],
            'qualities' => ['standard', 'hd'],
            // Real API param on dall-e-3.
            'styles'                    => ['vivid', 'natural'],
            'style_prompts'             => [],
            'backgrounds'               => [],
            'supports_reference_images' => false,
            'cost_per_image' => [
                'standard' => ['1024x1024' => 0.04, '1792x1024' => 0.08, '1024x1792' => 0.08],
                'hd'       => ['1024x1024' => 0.08, '1792x1024' => 0.12, '1024x1792' => 0.12],
            ],
        ],
    ],
];
