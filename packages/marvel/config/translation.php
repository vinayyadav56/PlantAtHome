<?php

/*
|--------------------------------------------------------------------------
| Enterprise multilingual / translation engine
|--------------------------------------------------------------------------
|
| Dynamic content (products, categories, …) is stored ONCE in English (the
| canonical row) and translated on-demand into a cache table, then overlaid
| onto the canonical row at read time. Adding a language requires NO code
| change — extend `languages` here (or override from the admin settings).
|
| Everything here is env-overridable so ops can tune without a deploy, and
| the admin Language-Management module can override the effective language
| list + active provider at runtime (stored in settings.options).
*/

return [

    // Master switch for the read-overlay. When false the API behaves exactly
    // as before (raw English columns), so this is a safe kill-switch.
    'enabled' => env('TRANSLATION_OVERLAY_ENABLED', true),

    // The canonical source language — the only language stored on the entity row.
    'default_language' => env('DEFAULT_LANGUAGE', 'en'),

    // Languages the platform serves. Comma-separated env override lets ops add a
    // language without a code change; the admin module can override at runtime.
    'languages' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('TRANSLATION_LANGUAGES', 'en,hi,mr,kn,ta,te'))
    ))),

    // Human-readable names used in AI prompts (provider-agnostic). Extend freely.
    'language_names' => [
        'en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi', 'kn' => 'Kannada',
        'ta' => 'Tamil', 'te' => 'Telugu', 'bn' => 'Bengali', 'gu' => 'Gujarati',
        'ml' => 'Malayalam', 'pa' => 'Punjabi', 'or' => 'Odia', 'as' => 'Assamese',
        'ur' => 'Urdu', 'sa' => 'Sanskrit', 'ne' => 'Nepali', 'kok' => 'Konkani',
        'mai' => 'Maithili', 'doi' => 'Dogri', 'ks' => 'Kashmiri', 'sd' => 'Sindhi',
        'sat' => 'Santali', 'mni' => 'Manipuri (Meitei)', 'brx' => 'Bodo',
    ],

    // Default provider id (overridable per-environment and from the admin module,
    // which stores the active provider + encrypted keys in translation_provider_configs).
    'default_provider' => env('TRANSLATION_PROVIDER', 'google'),

    // Dedicated queue so translation work never blocks order/payment jobs.
    'queue' => env('TRANSLATION_QUEUE', 'translations'),

    // Lazy on-demand translation: on a cache miss the overlay returns English
    // immediately and dispatches a background job. Turn off to translate only
    // via explicit admin/bulk actions (cheaper, fully controlled).
    'lazy' => env('TRANSLATION_LAZY', true),

    // Redis key prefix for the assembled per-language field cache.
    'cache_prefix' => env('TRANSLATION_CACHE_PREFIX', 'txn'),

    // Provider env fallbacks (DB-configured encrypted creds take precedence).
    'providers' => [
        'google' => [
            'api_key' => env('GOOGLE_TRANSLATE_API_KEY'),
            // Optional service-account / project for the v3 API.
            'project_id' => env('GOOGLE_TRANSLATE_PROJECT_ID'),
            'endpoint' => env('GOOGLE_TRANSLATE_ENDPOINT', 'https://translation.googleapis.com/language/translate/v2'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_SECRET_KEY'),
            'model' => env('OPENAI_TRANSLATE_MODEL', 'gpt-4o-mini'),
        ],
        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('CLAUDE_TRANSLATE_MODEL', 'claude-sonnet-4-6'),
        ],
        'azure' => [
            'api_key' => env('AZURE_TRANSLATOR_KEY'),
            'region' => env('AZURE_TRANSLATOR_REGION'),
            'endpoint' => env('AZURE_TRANSLATOR_ENDPOINT', 'https://api.cognitive.microsofttranslator.com'),
        ],
        'deepl' => [
            'api_key' => env('DEEPL_API_KEY'),
            'endpoint' => env('DEEPL_ENDPOINT', 'https://api-free.deepl.com/v2/translate'),
        ],
    ],

    // Per-1M-character cost estimates (USD) for the admin cost dashboard. LLM
    // providers are char-approximate; Google/DeepL/Azure are list price.
    'cost_per_million_chars' => [
        'google' => 20.0,
        'deepl' => 25.0,
        'azure' => 10.0,
        'openai' => 6.0,
        'claude' => 9.0,
    ],
];
