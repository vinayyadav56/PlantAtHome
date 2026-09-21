<?php

namespace Marvel\Integrations;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\Store\CredentialStore;
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
        $rows = self::rows();
        // One batched read for every enabled provider's bag, cached encrypted. Per-row reads
        // would be N Secrets Manager calls on a cold cache — in one unlucky request, and again
        // in each of the seven queue workers.
        $bags = self::bags($rows);

        foreach ($rows as $row) {
            $def = ProviderRegistry::find((string) $row->provider_slug);
            if ($def === null) {
                continue; // a row for a provider no longer in the registry
            }

            self::set($def->credentialConfigKeys(), (array) ($bags[(string) $row->provider_slug] ?? []));
            self::set($def->configurationConfigKeys(), (array) $row->configuration);
        }

        // Transport fix: whenever a SendGrid key is available (overlay or env), the
        // DEFAULT mailer becomes the HTTPS transport. Railway blackholes outbound
        // SMTP, so with MAIL_MAILER=smtp every Notification/Mailable that didn't
        // hand-pick the sendgrid mailer silently never delivered. This one flip
        // routes ALL of them over 443. MAIL_FORCE_MAILER escapes the behavior
        // (e.g. 'log' locally, or a deliberate smtp box off-Railway).
        if (config('mail.mailers.sendgrid.key') && !env('MAIL_FORCE_MAILER')) {
            config(['mail.default' => 'sendgrid']);
        }
    }

    /** The cache key holding the overlay row set for an environment. */
    public static function cacheKey(string $environment): string
    {
        return 'integration_overlay:' . $environment;
    }

    /**
     * The cache key holding the (encrypted) credential bags of a row set.
     *
     * Fingerprinted by each row's slug and credential version, so it invalidates ITSELF: a
     * credential change bumps the version and lands on a fresh key, and a row created outside
     * IntegrationService (a seeder, the backfill migration, a test) can never be served a bag
     * set that predates it. Nothing has to remember to bust it; stale entries age out by TTL.
     *
     * @param  iterable<IntegrationProvider> $rows
     */
    public static function bagsCacheKey(string $environment, iterable $rows = []): string
    {
        $fingerprint = [];
        foreach ($rows as $row) {
            $fingerprint[] = $row->provider_slug . '#' . (int) $row->credentials_version . '#' . (string) ($row->secret_version_id ?? '');
        }
        sort($fingerprint);

        return 'integration_overlay_bags:' . $environment . ':' . md5(implode('|', $fingerprint));
    }

    /**
     * slug => bag for the enabled rows, from the bound store. Cached under APP_KEY encryption so
     * the cache never holds a plaintext credential; never throws.
     *
     * @param  iterable<IntegrationProvider> $rows
     * @return array<string, array<string,string>>
     */
    private static function bags(iterable $rows): array
    {
        $rows = $rows instanceof \Traversable ? iterator_to_array($rows, false) : (array) $rows;
        if ($rows === []) {
            return [];
        }

        try {
            $environment = (new IntegrationService())->environment();
            $key = self::bagsCacheKey($environment, $rows);
            $cached = Cache::remember(
                $key,
                (int) config('integrations.cache_ttl', 600),
                fn () => Crypt::encrypt(app(CredentialStore::class)->getMany($rows))
            );

            return (array) Crypt::decrypt($cached);
        } catch (DecryptException) {
            // cached under a since-rotated APP_KEY — drop it and read through once
            try {
                Cache::forget(self::bagsCacheKey((new IntegrationService())->environment(), $rows));

                return app(CredentialStore::class)->getMany($rows);
            } catch (Throwable) {
                return [];
            }
        } catch (Throwable) {
            // store or cache unreachable at boot — the deployed env values stand
            return [];
        }
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

            // Cached as ONE query for all providers: per-slug lookups would be 30 cache reads per
            // request, which on a database cache driver is 30 queries. The cached models hold
            // ENCRYPTED attributes — decryption happens on access, so no plaintext reaches the cache.
            return Cache::remember(
                self::cacheKey($environment),
                (int) config('integrations.cache_ttl', 600),
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
