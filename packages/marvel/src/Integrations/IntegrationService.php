<?php

namespace Marvel\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\IntegrationProvider;
use Throwable;

/**
 * The ONE place third-party credentials are read.
 *
 * Controllers and services must not touch `integration_providers` (or a legacy settings table)
 * directly — that is how credentials end up copied into logs, responses and caches. They ask this
 * service for a secret by name and get a string.
 *
 * Resolution order, per field:
 *   1. the integration_providers row for the active environment
 *   2. the legacy table this provider used to live in (see LegacyBridge)
 *   3. config()/env()
 *
 * Steps 2 and 3 are what make the module shippable without a flag day: the new table can be empty
 * and every existing feature keeps working untouched.
 */
class IntegrationService
{
    /** In-request memo so repeated secret() calls in one request don't re-decrypt. */
    private array $memo = [];

    public function __construct(private ?LegacyBridge $legacy = null)
    {
        $this->legacy = $legacy ?? new LegacyBridge();
    }

    /** The environment whose rows are active (sandbox|production). */
    public function environment(): string
    {
        $env = (string) config('integrations.environment', '');

        return $env !== '' ? $env : (app()->environment('production') ? 'production' : 'sandbox');
    }

    /**
     * The provider row for a slug in the active environment, or null.
     *
     * A provider may pin itself to a different environment than the app — "production site, Porter
     * still on UAT" is a real and necessary state during onboarding — via
     * configuration.environment_pin.
     */
    public function provider(string $slug): ?IntegrationProvider
    {
        if (array_key_exists("row:$slug", $this->memo)) {
            return $this->memo["row:$slug"];
        }

        $row = null;
        try {
            // Cache the ENCRYPTED row, never decrypted values — a decrypted secret in Redis would
            // undo the encryption-at-rest this module exists to provide.
            $row = Cache::remember(
                $this->cacheKey($slug),
                (int) config('integrations.cache_ttl', 60),
                fn () => IntegrationProvider::query()
                    ->where('provider_slug', $slug)
                    ->where('environment', $this->environment())
                    ->first()
                    ?? IntegrationProvider::query()->where('provider_slug', $slug)->first()
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

        $row = $this->provider($slug);
        if ($row !== null) {
            $creds = (array) ($row->credentials ?? []);
            $value = trim((string) ($creds[$field] ?? ''));
            if ($value !== '') {
                return $this->memo[$key] = $value;
            }
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
     * Which credential fields are SET — booleans only, never values. This is what a controller
     * returns to the admin so a form can render "— set (leave blank to keep)".
     */
    public function credentialsSet(string $slug): array
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            return [];
        }

        $row = $this->provider($slug);
        $out = [];
        foreach ($def->credentialNames() as $field) {
            // A legacy/env-sourced credential still counts as "set" — otherwise the admin shows a
            // provider as unconfigured while it is demonstrably working.
            $out[$field] = $this->secret($slug, $field) !== '';
        }
        unset($row);

        return $out;
    }

    /**
     * Create or update a provider row.
     *
     * Blank credential values are IGNORED rather than stored, so re-saving a form without retyping
     * every secret cannot wipe the ones it never displayed.
     *
     * @param  array<string,string|null>  $credentials
     * @param  array<string,mixed>        $configuration
     */
    public function put(string $slug, array $attributes = [], array $credentials = [], array $configuration = []): IntegrationProvider
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

        if ($credentials !== []) {
            $allowed = array_flip($def->credentialNames());
            $row->mergeCredentials(array_intersect_key($credentials, $allowed));
        }

        $row->save();
        $this->forget($slug);

        return $row;
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

    /** Record the outcome of a push to the Go shipping-service. */
    public function recordSync(string $slug, string $status, ?string $error = null): void
    {
        $row = $this->provider($slug);
        if ($row === null) {
            return;
        }
        $row->forceFill([
            'sync_status' => $status,
            'sync_error'  => $error ? mb_substr($error, 0, 1000) : null,
            'synced_at'   => $status === IntegrationProvider::SYNC_SYNCED ? now() : $row->synced_at,
        ])->save();
        $this->forget($slug);

        if ($status === IntegrationProvider::SYNC_FAILED) {
            // Worth a log line: the save succeeded locally but the shipping service is still using
            // the previous key, which is exactly the state that looks like "the save did nothing".
            Log::warning("integration sync failed for {$slug}: " . (string) $error);
        }
    }

    /**
     * The decrypted credential bag for a provider — the ONLY method that returns secret values, and
     * the only caller is the sync job that seals them for transport.
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

    public function forget(string $slug): void
    {
        $this->memo = [];
        try {
            Cache::forget($this->cacheKey($slug));
        } catch (Throwable) {
            // A cache backend outage must not break a save.
        }
    }

    private function cacheKey(string $slug): string
    {
        return 'integration_provider:' . $this->environment() . ':' . $slug;
    }
}
