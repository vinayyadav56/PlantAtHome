<?php

/**
 * Wiring for CredentialSync (packages/marvel/src/Integrations/CredentialSync.php),
 * which pushes delivery-partner credentials (Porter/Borzo/Shiprocket) from the
 * admin's Settings → Integrations page to the Go shipping-service, sealed with
 * AES-256-GCM so a rotated key never needs a redeploy on either side.
 *
 * This file did not exist. `CredentialSync::enabled()` reads
 * `config('integrations.sync_to_shipping', false)` — with no config source
 * anywhere in the app defining that key, it evaluated to `false` unconditionally,
 * forever. The feature was fully built (credential fields declared in
 * ProviderRegistry, the sealed push implemented, the Go side ready to receive
 * it) but had no way to ever turn on.
 *
 * Defaults to enabled: `enabled()` already separately requires the shipping
 * service URL, API key and this sync key to all be non-empty, so this flag was
 * never adding a real gate of its own — just an accidentally-permanent off switch.
 */
return [
    'sync_to_shipping' => env('INTEGRATIONS_SYNC_TO_SHIPPING', true),

    /*
     * The environment label stamped on integration rows AND sent with credential pushes.
     *
     * The Go shipping-service refuses a push whose environment differs from its own APP_ENV —
     * a guard so a prod monolith can never overwrite a staging service's credentials. But the
     * two sides never agreed on vocabulary: IntegrationService::environment() falls back to
     * "sandbox" on any non-production app, while the service runs as "staging". Every non-prod
     * credential push was therefore refused with
     *
     *   409 "this service runs environment staging, refusing a credential push for sandbox"
     *
     * On production both sides already say "production", so only non-prod needed this override.
     * Set INTEGRATIONS_ENVIRONMENT to the shipping service's APP_ENV (staging → "staging").
     */
    'environment' => env('INTEGRATIONS_ENVIRONMENT', ''),

    // Fallback only. CredentialSync::syncKey() prefers a DB-stored value on the
    // 'shipping_service' provider row (set via Settings → Integrations); this is
    // what lets the sync work purely from environment before any admin has
    // touched that page.
    'sync_key' => env('INTEGRATION_SYNC_KEY', ''),
];
