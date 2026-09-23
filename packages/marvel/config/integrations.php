<?php

/**
 * Integration Management module configuration.
 *
 * ⚠️ This file is only live because ShopServiceProvider::register() calls mergeConfigFrom() for it.
 * `packages/marvel/config/services.php` exists and is NEVER merged, so every config('services.*')
 * key defined there silently resolves to null. If a config('integrations.*') lookup starts
 * returning null, check that the mergeConfigFrom line is still present before debugging anything
 * else.
 */
return [
    /*
     | Which environment's provider rows are active. Blank = derive from APP_ENV
     | (production ⇒ 'production', anything else ⇒ 'sandbox').
     |
     | ⚠️ APP_ENV is NOT a reliable prod/staging discriminator in this project — it reads
     | 'production' locally AND on Railway staging — so set this explicitly per environment
     | rather than relying on the derivation.
     */
    'environment' => env('INTEGRATIONS_ENVIRONMENT', ''),

    /*
     | Seconds to cache a provider row and its credential bag. Cached values are ENCRYPTED —
     | the model as stored, and the bag under APP_KEY — never plaintext: a readable credential
     | in Redis would undo the at-rest protection this module exists to provide. Every write
     | busts these keys, so the TTL only bounds an edit made outside the admin (e.g. in the
     | AWS console).
     */
    'cache_ttl' => (int) env('INTEGRATIONS_CACHE_TTL', 600),

    /*
     | Push credentials to the Go shipping-service on save. Off ⇒ the row is stored locally and the
     | service keeps using its own env vars, which is the correct state until the service has
     | INTEGRATION_SYNC_KEY set.
     */
    'sync_to_shipping' => (bool) env('INTEGRATIONS_SYNC_TO_SHIPPING', false),

    /*
     | 32 bytes as hex (64 chars) or base64 — MUST equal the shipping-service's
     | INTEGRATION_SYNC_KEY. Seals the credential bag at the application layer so a plaintext key
     | never appears in request logs, APM traces or proxy buffers on either side.
     | Generate with: openssl rand -hex 32
     */
    'sync_key' => env('INTEGRATION_SYNC_KEY', ''),

    /*
     | Reveal a stored credential back to an authorised admin.
     |
     | This was OFF and documented as "should stay off". The owner asked for it on 2026-09-23,
     | so it is built — but each of the original three objections is answered rather than waved
     | through, because they were all real:
     |
     |   "not covered by LogRequests redaction"  -> the /reveal path is in that middleware's
     |                                              SKIP list, so the row is never written at all,
     |                                              and the response carries Cache-Control: no-store.
     |   "the SPA would cache the plaintext"     -> the admin calls it as a mutation with no query
     |                                              cache, holds the value in component state only,
     |                                              and drops it on close or after a timeout.
     |   "no legitimate operator need"           -> there is one: recovering a credential nobody
     |                                              else has a copy of. That is the owner's call.
     |
     | What is NOT waved through: .edit does not imply .reveal, the caller re-enters their own
     | password per reveal, attempts are rate limited, and every reveal lands in integration_audits
     | with the field names and never the values.
     */
    // Break-glass switch for "Show credentials" (Settings → Integrations). This key sat here
    // unread since the module was built; it is now the flag IntegrationController::reveal()
    // checks first. The real gate is the `settings.integrations.reveal` permission plus a
    // password re-entry — this just turns the whole endpoint off with no deploy.
    'allow_reveal' => (bool) env('INTEGRATIONS_ALLOW_REVEAL', true),

    'reveal_max_attempts' => (int) env('INTEGRATIONS_REVEAL_MAX_ATTEMPTS', 5),

    // See config/integrations.php (the app file, which wins on merge) for the credential store.
    'credential_store'          => env('INTEGRATIONS_CREDENTIAL_STORE', 'database'),
    'secrets_manager'           => [
        'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION', 'ap-south-1')),
        'prefix' => env('INTEGRATIONS_SECRET_PREFIX', 'plantathome'),
    ],
    'restart_workers_on_change' => (bool) env('INTEGRATIONS_RESTART_WORKERS', true),
];
