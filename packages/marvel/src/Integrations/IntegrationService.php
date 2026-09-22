<?php

namespace Marvel\Integrations;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\Store\CredentialStore;
use Marvel\Integrations\Store\CredentialStoreUnavailable;
use Throwable;

/**
 * The ONE place third-party credentials are read.
 *
 * Controllers and services must not touch `integration_providers`, the credential store or a
 * legacy settings table directly — that is how credentials end up copied into logs, responses and
 * caches. They ask this service for a secret by name and get a string.
 *
 * Resolution order, per field:
 *   1. the bound CredentialStore, for the provider row in the active environment
 *      (Secrets Manager on production/staging, the encrypted DB column locally)
 *   2. the legacy table this provider used to live in (see LegacyBridge)
 *   3. config()/env()
 *
 * Steps 2 and 3 are what make the module shippable without a flag day: the store can be empty and
 * every existing feature keeps working untouched. credentialSources() says which step answered, so
 * the admin can show "from environment — migrate" rather than a false "configured".
 */
class IntegrationService
{
    /** In-request memo so repeated secret() calls in one request don't re-read the store. */
    private array $memo = [];

    public function __construct(private ?LegacyBridge $legacy = null)
    {
        $this->legacy = $legacy ?? new LegacyBridge();
    }

    /** The environment whose rows are active (production|staging|sandbox). */
    public function environment(): string
    {
        $env = (string) config('integrations.environment', '');

        return $env !== '' ? $env : (app()->environment('production') ? 'production' : 'sandbox');
    }

    /**
     * The provider row for a slug in the active environment, or null.
     *
     * Strictly the active environment. There used to be a "?? any environment" fallback here;
     * with secrets named per environment that would make a sandbox row resolve a production
     * secret name (or the reverse), so it is gone. ConfigOverlay never had it.
     */
    public function provider(string $slug): ?IntegrationProvider
    {
        if (array_key_exists("row:$slug", $this->memo)) {
            return $this->memo["row:$slug"];
        }

        $row = null;
        try {
            // Cache the row as stored (its DB bag, if any, is ciphertext), never decrypted values.
            $row = Cache::remember(
                $this->cacheKey($slug),
                $this->ttl(),
                fn () => IntegrationProvider::query()
                    ->where('provider_slug', $slug)
                    ->where('environment', $this->environment())
                    ->first()
            );
        } catch (Throwable $e) {
            // The table may not exist yet (a deploy where migrations have not run). Treat it as
            // "no row" so every feature falls through to its legacy/env source rather than 500ing.
            $row = null;
        }

        return $this->memo["row:$slug"] = $row;
    }

    /** Is this provider present AND switched on? */
    public function enabled(string $slug): bool
    {
        $row = $this->provider($slug);

        return $row !== null && (bool) $row->enabled;
    }

    /**
     * A secret value for a provider, falling back to the legacy source and then to config/env.
     * Returns '' when nothing is configured anywhere.
     */
    public function secret(string $slug, string $field, string $default = ''): string
    {
        $key = "secret:$slug:$field";
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $value = trim((string) ($this->storedBag($slug)[$field] ?? ''));
        if ($value !== '') {
            return $this->memo[$key] = $value;
        }

        $legacy = trim((string) $this->legacy->secret($slug, $field));
        if ($legacy !== '') {
            return $this->memo[$key] = $legacy;
        }

        return $this->memo[$key] = $default;
    }

    /** A non-secret configuration value, with the same fallback chain. */
    public function config(string $slug, string $field, mixed $default = null): mixed
    {
        $row = $this->provider($slug);
        if ($row !== null) {
            $conf = (array) ($row->configuration ?? []);
            if (array_key_exists($field, $conf) && $conf[$field] !== null && $conf[$field] !== '') {
                return $conf[$field];
            }
        }

        $legacy = $this->legacy->config($slug, $field);

        return ($legacy === null || $legacy === '') ? $default : $legacy;
    }

    /**
     * Where each credential field is coming from — never the value.
     *
     *   secrets_manager | database   held by the bound store
     *   env                          only in a legacy table or config()/env() (works, but is not
     *                                managed here — the admin shows "migrate")
     *   none                         nowhere
     *
     * @return array<string,string>
     */
    public function credentialSources(string $slug): array
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            return [];
        }

        $bag = $this->storedBag($slug);
        $driver = $this->store()->driver();
        $out = [];
        foreach ($def->credentialNames() as $field) {
            if (trim((string) ($bag[$field] ?? '')) !== '') {
                $out[$field] = $driver;
            } elseif (trim((string) $this->legacy->secret($slug, $field)) !== '') {
                $out[$field] = 'env';
            } else {
                $out[$field] = 'none';
            }
        }

        return $out;
    }

    /**
     * Which credential fields are SET — booleans only, never values. This is what a controller
     * returns to the admin so a form can render "— set (leave blank to keep)". A legacy/env-sourced
     * credential still counts as set — otherwise the admin shows a provider as unconfigured while
     * it is demonstrably working.
     *
     * @return array<string,bool>
     */
    public function credentialsSet(string $slug): array
    {
        return array_map(static fn (string $source) => $source !== 'none', $this->credentialSources($slug));
    }

    /**
     * Create or update a provider row.
     *
     * Blank credential values are IGNORED rather than stored, so re-saving a form without retyping
     * every secret cannot wipe the ones it never displayed. An explicit null removes a field.
     *
     * @param  array<string,string|null>  $credentials
     * @param  array<string,mixed>        $configuration
     * @throws \Marvel\Integrations\Store\CredentialStoreUnavailable  when this environment must use
     *         Secrets Manager and is not configured to (reads keep working; the save is refused)
     */
    public function put(string $slug, array $attributes = [], array $credentials = [], array $configuration = [], ?int $userId = null): IntegrationProvider
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            throw new \InvalidArgumentException("Unknown integration provider: {$slug}");
        }

        $environment = $attributes['environment'] ?? $this->environment();

        $row = IntegrationProvider::query()->firstOrNew([
            'provider_slug' => $slug,
            'environment'   => $environment,
        ]);

        $row->category ??= $def->category;
        $row->display_name = $attributes['display_name'] ?? $row->display_name ?? $def->displayName;
        $row->priority = $attributes['priority'] ?? $row->priority ?? $def->priority;
        if (array_key_exists('enabled', $attributes)) {
            $row->enabled = (bool) $attributes['enabled'];
        }

        if ($configuration !== []) {
            // Only fields this provider actually declares — an unexpected key in the config blob is
            // either a typo or an attempt to smuggle a secret into the unencrypted column.
            $allowed = array_flip($def->configNames());
            $merged = (array) ($row->configuration ?? []);
            foreach ($configuration as $k => $v) {
                if (isset($allowed[$k])) {
                    $merged[$k] = $v;
                }
            }
            $row->configuration = $merged;
        }

        $credentialsChanged = false;
        if ($credentials !== []) {
            $incoming = array_intersect_key($credentials, array_flip($def->credentialNames()));

            // get() answers null for BOTH "nothing stored" and "the read failed" — it never
            // throws, so a boot can always fall through to the env value. On a WRITE that
            // ambiguity is dangerous: merging onto an empty bag would replace every stored
            // field with just the one being typed. A row that names a secret must have a
            // readable bag, so null there means the read failed, and the save is refused.
            $current = $row->exists ? $this->store()->get($row) : [];
            if ($current === null && !empty($row->secret_name)) {
                throw new CredentialStoreUnavailable(
                    "Could not read the stored credentials for {$slug}, so they were not overwritten. Nothing was saved — try again."
                );
            }
            $current = $current ?? [];
            $merged  = self::mergeBag($current, $incoming);

            if ($merged !== $current) {
                // Throws on a refused environment or an AWS failure — the operator must see it.
                $this->store()->put($row, $merged, $userId);
                $row->auditCredentialFields = self::changedFields($current, $merged);
                $credentialsChanged = true;
            }
        }
        if ($userId !== null && $row->isDirty()) {
            $row->last_updated_by = $userId;
        }

        $row->save();
        $this->forget($slug);

        if ($credentialsChanged && config('integrations.restart_workers_on_change', true)) {
            // Exactly what `php artisan queue:restart` does, without booting the console kernel
            // on every save. Workers check this timestamp after each job and exit; systemd /
            // supervisord bring them back on the new key.
            try {
                Cache::forever('illuminate:queue:restart', time());
            } catch (Throwable) {
                // a cache outage must not fail a save that already landed
            }
        }

        return $row;
    }

    /**
     * Apply incoming values over a stored bag: blank keeps, explicit null removes.
     *
     * @param  array<string,string>       $current
     * @param  array<string,string|null>  $incoming
     * @return array<string,string>
     */
    public static function mergeBag(array $current, array $incoming): array
    {
        $bag = $current;
        foreach ($incoming as $key => $value) {
            if ($value === null) {
                unset($bag[$key]);
                continue;
            }
            if (trim((string) $value) === '') {
                continue; // leave the stored value untouched
            }
            $bag[$key] = (string) $value;
        }

        return $bag;
    }

    /** Field NAMES whose value differs between two bags — for the audit row, never the values. */
    private static function changedFields(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = (string) $field;
            }
        }
        sort($changed);

        return $changed;
    }

    /** Record the outcome of a health check. Never stores credential material. */
    public function recordHealth(string $slug, string $status, array $detail = []): void
    {
        $row = $this->provider($slug);
        if ($row === null) {
            return;
        }
        $row->forceFill([
            'health_status'     => $status,
            'health_checked_at' => now(),
            'health_detail'     => $detail,
        ])->save();
        $this->forget($slug);
    }

    /**
     * Record the outcome of a push to the Go shipping-service.
     *
     * $error is a SHORT status line, never a response body: sync_error is rendered verbatim in the
     * admin, and a vendor body that echoed the request would put a freshly typed key on screen.
     */
    public function recordSync(string $slug, string $status, ?string $error = null): void
    {
        $row = $this->provider($slug);
        if ($row === null) {
            return;
        }
        $row->forceFill([
            'sync_status' => $status,
            'sync_error'  => $error ? mb_substr($error, 0, 200) : null,
            'synced_at'   => $status === IntegrationProvider::SYNC_SYNCED ? now() : $row->synced_at,
        ])->save();
        $this->forget($slug);

        IntegrationLog::record(
            $slug,
            IntegrationLog::ACTION_SYNC,
            $status === IntegrationProvider::SYNC_SYNCED ? IntegrationLog::STATUS_OK : IntegrationLog::STATUS_FAILED,
            ['error_code' => $status, 'error_message' => $error]
        );

        if ($status === IntegrationProvider::SYNC_FAILED) {
            // Worth a log line: the save succeeded locally but the shipping service is still using
            // the previous key, which is exactly the state that looks like "the save did nothing".
            Log::warning("integration sync failed for {$slug}: " . (string) $error);
        }
    }

    /**
     * The decrypted credential bag for a provider, with the legacy/env fallback applied per field.
     * The ONLY method (besides secret()) that returns secret values; its one caller is the sync
     * that seals them for transport to the shipping service.
     *
     * @return array<string,string>
     */
    public function credentialBag(string $slug): array
    {
        $def = ProviderRegistry::find($slug);
        $row = $this->provider($slug);
        if ($def === null || $row === null) {
            return [];
        }

        $out = [];
        foreach ($def->credentialNames() as $field) {
            $value = $this->secret($slug, $field);
            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * The bag the STORE holds for a provider — no legacy/env fallback. This is what the overlay
     * and the source report need: "what is managed here", not "what works".
     *
     * Cached under APP_KEY encryption so Redis never holds a plaintext credential; a bag cached
     * under a since-rotated key just refetches.
     *
     * @return array<string,string>
     */
    public function storedBag(string $slug): array
    {
        $key = "bag:$slug";
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $row = $this->provider($slug);
        if ($row === null) {
            return $this->memo[$key] = [];
        }

        $bag = [];
        try {
            $cached = Cache::remember(
                $this->bagCacheKey($slug),
                $this->ttl(),
                fn () => Crypt::encrypt($this->store()->get($row) ?? [])
            );
            $bag = (array) Crypt::decrypt($cached);
        } catch (DecryptException) {
            try {
                Cache::forget($this->bagCacheKey($slug));
                $bag = $this->store()->get($row) ?? [];
            } catch (Throwable) {
                $bag = [];
            }
        } catch (Throwable) {
            $bag = [];
        }

        return $this->memo[$key] = $bag;
    }

    public function forget(string $slug): void
    {
        $this->memo = [];
        try {
            Cache::forget($this->cacheKey($slug));
            Cache::forget($this->bagCacheKey($slug));
            // The overlay caches all rows under one key. Without this a save would keep booting
            // from the stale set for a full TTL, which reads as "the save did nothing". (Its bag
            // set needs no busting: that key is fingerprinted by the rows' credential versions,
            // so a change lands on a fresh one by itself.)
            Cache::forget(ConfigOverlay::cacheKey($this->environment()));
        } catch (Throwable) {
            // A cache backend outage must not break a save.
        }
    }

    /** The store bound for this process — resolved lazily so a test can swap it. */
    public function store(): CredentialStore
    {
        return app(CredentialStore::class);
    }

    private function ttl(): int
    {
        return (int) config('integrations.cache_ttl', 600);
    }

    private function cacheKey(string $slug): string
    {
        return 'integration_provider:' . $this->environment() . ':' . $slug;
    }

    private function bagCacheKey(string $slug): string
    {
        return 'integration_secret:' . $this->environment() . ':' . $slug;
    }
}
