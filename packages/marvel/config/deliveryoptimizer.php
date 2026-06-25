<?php

/**
 * Delivery Optimizer tunables. Static defaults here; the admin-tunable subset
 * (flat fee, free-delivery threshold) is overlaid live from settings.options by
 * OptimizerConfig so it agrees with what CheckoutRepository::verify charges — no deploy.
 */
return [
    // Master switch for the additive /checkout/estimate `optimized` block + verify firm path.
    'enabled' => (bool) env('DELIVERY_OPTIMIZER_ENABLED', false),

    // Phase A: how many ranked candidates to keep per item (bounds Phase B).
    'top_k' => (int) env('DELIVERY_OPTIMIZER_TOP_K', 5),

    // Phase B: wall-clock budget (ms) for the bounded 2-opt refinement.
    'time_budget_ms' => (int) env('DELIVERY_OPTIMIZER_TIME_BUDGET_MS', 30),

    // Quote-cache TTLs (seconds) by rail. Instant rates move faster than courier.
    'ttl' => [
        'instant' => (int) env('DELIVERY_OPTIMIZER_TTL_INSTANT', 60),
        'courier' => (int) env('DELIVERY_OPTIMIZER_TTL_COURIER', 300),
    ],

    // Objective: penalty (currency) per day an item's ETA runs past the target SLA.
    'sla_penalty_per_day' => (float) env('DELIVERY_OPTIMIZER_SLA_PENALTY_PER_DAY', 5.0),
    'target_sla_days'     => (int) env('DELIVERY_OPTIMIZER_TARGET_SLA_DAYS', 3),

    // Customer-facing flat delivery fee. Defaults mirror the freeShipping settings keys;
    // settings.options.freeShipping / freeShippingAmount override at runtime.
    'flat_fee'                => (float) env('DELIVERY_OPTIMIZER_FLAT_FEE', 49.0),
    'free_delivery_enabled'   => (bool) env('DELIVERY_OPTIMIZER_FREE_DELIVERY', true),
    'free_delivery_threshold' => (float) env('DELIVERY_OPTIMIZER_FREE_THRESHOLD', 999.0),

    // Estimation policy. Keep firm-at-browse OFF so browse never calls Porter/Shiprocket.
    'firm_quotes_at_browse' => (bool) env('DELIVERY_OPTIMIZER_FIRM_AT_BROWSE', false),
    'firm_timeout_ms'       => (int) env('DELIVERY_OPTIMIZER_FIRM_TIMEOUT_MS', 120),

    // Fallbacks / incremental tuning.
    'default_weight_g'           => (int) env('DELIVERY_OPTIMIZER_DEFAULT_WEIGHT_G', 500),
    'full_reopt_every_n_events'  => (int) env('DELIVERY_OPTIMIZER_FULL_REOPT_EVERY_N', 8),
    'marginal_reopt_threshold'   => (float) env('DELIVERY_OPTIMIZER_MARGINAL_REOPT_THRESHOLD', 1.0),
];
