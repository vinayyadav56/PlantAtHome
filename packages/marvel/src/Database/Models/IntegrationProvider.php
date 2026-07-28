<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One third-party provider's configuration for one environment.
 *
 * Generalizes the shape `courier_partner_configs` and `translation_provider_configs` had already
 * arrived at independently: (slug, enabled, settings json, credentials encrypted, $hidden).
 *
 * `credentials` is cast `encrypted:array` (Laravel Crypt / APP_KEY) so secrets are encrypted at
 * rest, and `$hidden` so a decrypted bag can never leak by returning the model directly from a
 * controller. Reads go through IntegrationService, which exposes presence booleans rather than
 * values.
 */
class IntegrationProvider extends Model
{
    protected $table = 'integration_providers';

    protected $guarded = [];

    protected $casts = [
        'enabled'             => 'boolean',
        'priority'            => 'integer',
        'configuration'       => 'array',
        'credentials'         => 'encrypted:array',
        'credentials_version' => 'integer',
        'health_detail'       => 'array',
        'health_checked_at'   => 'datetime',
        'synced_at'           => 'datetime',
    ];

    /**
     * Defense in depth: never serialize decrypted credentials, even if a model instance is returned
     * directly from somewhere that forgot to project its fields.
     */
    protected $hidden = ['credentials'];

    /** Health states, mirroring the module spec. */
    public const HEALTH_CONNECTED    = 'connected';
    public const HEALTH_AUTH_FAILED  = 'auth_failed';
    public const HEALTH_TOKEN_EXPIRED = 'token_expired';
    public const HEALTH_WEBHOOK_ERROR = 'webhook_error';
    public const HEALTH_DISABLED     = 'disabled';
    public const HEALTH_MAINTENANCE  = 'maintenance';
    public const HEALTH_UNKNOWN      = 'unknown';

    public const SYNC_NA      = 'n/a';
    public const SYNC_PENDING = 'pending';
    public const SYNC_SYNCED  = 'synced';
    public const SYNC_FAILED  = 'failed';

    protected static function booted(): void
    {
        // The version bump lives HERE and not in the controller on purpose. Any code path that
        // writes credentials — controller, console command, seeder, backfill — must bump it, or the
        // Go service keeps serving the previous key from its cache until the TTL expires and the
        // rotation looks like it silently failed.
        static::saving(function (self $provider) {
            if ($provider->isDirty('credentials')) {
                $provider->credentials_version = (int) $provider->getOriginal('credentials_version', 0) + 1;
            }
        });
    }

    /**
     * Credential field names that are actually set — NEVER their values. This is the read contract
     * every provider form uses: it can render "— set (leave blank to keep)" without the secret ever
     * reaching the browser.
     *
     * @param  string[]  $fields
     * @return array<string,bool>
     */
    public function credentialsSet(array $fields): array
    {
        $creds = (array) ($this->credentials ?? []);
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = isset($creds[$field]) && trim((string) $creds[$field]) !== '';
        }

        return $out;
    }

    /**
     * Merge new credential values, IGNORING blank ones.
     *
     * "Blank means leave it alone" is the contract the admin forms promise: a form re-submitted
     * without retyping every secret must not wipe the ones it did not show. Passing an explicit
     * null removes a field.
     *
     * @param  array<string,string|null>  $values
     */
    public function mergeCredentials(array $values): void
    {
        $creds = (array) ($this->credentials ?? []);
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($creds[$key]);
                continue;
            }
            if (trim((string) $value) === '') {
                continue; // leave the stored value untouched
            }
            $creds[$key] = (string) $value;
        }
        $this->credentials = $creds;
    }
}
