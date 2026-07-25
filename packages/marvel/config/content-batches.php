<?php

/**
 * Bulk AI product-content generation (admin product listing → "Generate with AI").
 * Text generation is cheap + fast, so this runs on its own light queue and paces
 * generously. Everything is env-tunable.
 */
return [
    // Dedicated queue so bulk text runs never sit behind image batches.
    'queue' => env('CONTENT_BATCH_QUEUE', 'content'),

    // Products processed per minute (each = one gpt-4o-mini call). gpt-4o-mini
    // limits are high; raise via env if needed.
    'rate_per_minute' => (float) env('CONTENT_BATCH_RATE_PER_MINUTE', 30),

    // Guardrails.
    'max_rows'           => (int) env('CONTENT_BATCH_MAX_ROWS', 2000),
    'max_estimated_cost' => (float) env('CONTENT_BATCH_MAX_COST', 50),

    // Per-row generation attempts (row-level; infra job retries are separate).
    'row_max_attempts' => 3,

    // Cooldown before a `retrying` row may be claimed again.
    'retry_cooldown_seconds' => 30,

    // Sweeper thresholds (minutes).
    'stall_minutes'      => 15,  // processing row with no heartbeat → re-pend
    'redispatch_minutes' => (int) env('CONTENT_BATCH_REDISPATCH_MINUTES', 2),

    // Text model for all generation (shared with the inline description/category endpoints).
    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4o-mini'),

    // Rough USD per product (one combined description+categories call on
    // gpt-4o-mini) — used only for the pre-flight cost estimate.
    'cost_per_product' => (float) env('CONTENT_BATCH_COST_PER_PRODUCT', 0.002),
];
