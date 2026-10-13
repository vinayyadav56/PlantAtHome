<?php

/**
 * Bulk AI product-content generation (admin product listing → "Generate with AI").
 * Text generation is cheap + fast, so this runs on its own light queue and paces
 * generously. Everything is env-tunable.
 */
return [
    // Dedicated queue so bulk text runs never sit behind image batches.
    'queue' => env('CONTENT_BATCH_QUEUE', 'content'),

    // Products processed per minute (each = one text_model call). The mini models'
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
    // gpt-5.4-mini (owner 2026-10-13): on a graded staging trial 2% of its Hindi names were wrong
    // against gpt-4o-mini's 10%, at the same speed and about $0.0013 a plant. Openai::textParams()
    // gives GPT-5 models the request settings they need.
    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-5.4-mini'),

    // Rough USD per product, used only for the pre-flight cost estimate and cap. A
    // gpt-5.4-mini plant measured about $0.0013, so 0.002 stays on the safe side.
    'cost_per_product' => (float) env('CONTENT_BATCH_COST_PER_PRODUCT', 0.002),

    // "Generate missing content": the ONLY values the AI may store for these two
    // plant_attributes columns — the storefront filter rail's existing spellings
    // ("Low Light Plants" is a collection rule on 'Low Light'). The meaning is
    // sent with each value in the prompt.
    'plant_vocabulary' => [
        'sunlight' => [
            'Full Sun'        => 'at least 6 hours of direct sun a day; outdoor beds, terraces and balconies',
            'Partial Sun'     => 'about 3-6 hours of direct sun a day, ideally the gentler morning sun',
            'Partial Shade'   => 'under 3 hours of direct sun; dappled or filtered light the rest of the day',
            'Bright Indirect' => 'a bright spot indoors with no direct sun on the leaves, e.g. near a window',
            'Low Light'       => 'tolerates dim indoor corners far from windows',
        ],
        'water_requirement' => [
            'Low'      => 'let the soil dry out completely between waterings; about every 2-4 weeks (succulents, cacti)',
            'Moderate' => 'water when the top 2-3 cm of soil is dry; roughly once a week',
            'High'     => 'keep the soil evenly moist; water every 1-3 days in warm weather',
        ],
    ],

    // USD per 1M tokens, by text_model, for the cost shown on run progress. A model that is
    // not listed shows no cost rather than gpt-4o-mini's: add its price when switching.
    'token_price_per_million' => [
        'gpt-4o-mini'  => ['input' => 0.15, 'output' => 0.60],
        'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
    ],
];
