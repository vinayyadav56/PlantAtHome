<?php

namespace Marvel\Integrations;

use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\IntegrationProvider;
use Throwable;

/**
 * Makes a saved provider row actually take effect.
 *
 * Storing a credential is only half the promise. Payment, OTP, mail, maps, storage and the AI
 * clients all read their keys with `config('shop.razorpay.key_secret')`-style lookups, so before
 * this class a super admin could type a key into Settings → Integrations, see it saved, and have
 * nothing change: the running code still read the env var baked in at deploy time. The admin page
 * implied control it did not have, which is worse than not offering the field.
 *
 * At boot, every ENABLED row for the active environment writes its values over the `config()` keys
 * its fields declare (see ProviderDefinition::credentialConfigKeys). ~40 call sites keep reading
 * config exactly as before and transparently get the admin-managed value — no call-site edits, and
 * nothing to remember when a new one is written.
 *
 * Rules that make this safe to leave switched on:
 *  - Only ENABLED rows overlay. Toggling a provider Off is the way back to the deployed env value
 *    without deleting the credential, and a card reading "Off" never silently injects a key.
 *  - Only the ACTIVE environment's rows, with no cross-environment fallback (unlike
 *    IntegrationService::provider). Being lax here would let a sandbox row overwrite production
 *    config process-wide — a much larger blast radius than one lookup.
 *  - Empty values never overlay, so a half-filled row cannot blank out a working env var.
 *  - Nothing here may throw. A missing table, an unreachable cache or a bag encrypted under a
 *    rotated APP_KEY must all degrade to "keep the env value", never to a 500 on every route.
 *
 * ⚠️ This applies at BOOT, so a long-running queue worker keeps the values it booted with. Web
 * requests pick up a rotation on the next request; workers need `php artisan queue:restart`. A key
 * rotated to fix failing jobs will otherwise look like it did not work.
 */
class ConfigOverlay
{
    public static function apply(): void
    {
        foreach (self::rows() as $row) {
            $def = ProviderRegistry::find((string) $row->provider_slug);
            if ($def === null) {
                continue; // a row for a provider no longer in the registry
            }

            try {
                // Reading ->credentials decrypts; a rotated APP_KEY throws here. Skip the row's
                // secrets rather than the whole overlay, so its non-secret config still applies.
                self::set($def->credentialConfigKeys(), (array) $row->credentials);
            } catch (Throwable) {
                // undecryptable bag — the deployed env value stands
            }

            self::set($def->configurationConfigKeys(), (array) $row->configuration);
        }
    }

    /** The cache key holding the overlay row set for an environment. */
    public static function cacheKey(string $environment): string
    {
        return 'integration_overlay:' . $environment;
    }

    /**
     * @param  array<string,string>  $configKeys  field name => config() key
     * @param  array<string,mixed>   $values      field name => saved value
     */
    private static function set(array $configKeys, array $values): void
    {
        foreach ($configKeys as $field => $key) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                continue;
            }
            config([$key => $value]);
        }
    }

    /** @return iterable<IntegrationProvider> */
    private static function rows(): iterable
    {
        try {
            $environment = (new IntegrationService())->environment();

            // Cached as ONE query for all providers: per-slug lookups would be 25 cache reads per
            // request, which on a database cache driver is 25 queries. The cached models hold
            // ENCRYPTED attributes — decryption happens on access, so no plaintext reaches the cache.
            return Cache::remember(
                self::cacheKey($environment),
                (int) config('integrations.cache_ttl', 60),
                fn () => IntegrationProvider::query()
                    ->where('enabled', true)
                    ->where('environment', $environment)
                    ->get()
            );
        } catch (Throwable) {
            // Table not migrated yet, or the DB/cache is unreachable during boot. Every provider
            // falls through to its env value, which is exactly the pre-module behaviour.
            return [];
        }
    }
}
