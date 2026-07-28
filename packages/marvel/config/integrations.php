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
     | Seconds to cache a provider row. The cached value is the ENCRYPTED model, never decrypted
     | secrets: a plaintext credential in Redis would undo the encryption-at-rest this module
     | exists to provide.
     */
    'cache_ttl' => (int) env('INTEGRATIONS_CACHE_TTL', 60),

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
     | Reveal a stored credential back to a super admin.
     |
     | OFF, and it should stay off. It converts a write-only store into a read-anywhere one: the
     | response shape is not covered by LogRequests redaction, and the admin SPA would cache the
     | plaintext in react-query and the browser devtools network panel. There is no legitimate
     | operator need — the vendor is the source of a key you do not have.
     */
    'allow_reveal' => (bool) env('INTEGRATIONS_ALLOW_REVEAL', false),
];
