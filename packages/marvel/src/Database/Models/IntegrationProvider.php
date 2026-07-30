<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Audit lives here for the same reason the version bump does: every write path must be
        // covered, including console commands and the backfill migration. A controller-level audit
        // would silently miss those, and "who changed this credential" is exactly the question
        // asked when something has already gone wrong.
        //
        // `created`/`updated` rather than `saved`, because `saved` cannot tell the two apart:
        // `wasRecentlyCreated` stays true for the life of the instance, and `performInsert` never
        // calls syncChanges() so getChanges() is empty after an insert. Using the specific events
        // means an insert is an insert and a no-op save fires nothing at all.
        static::created(fn (self $provider) => $provider->writeAuditRow('created'));
        static::updated(fn (self $provider) => $provider->writeAuditRow('updated'));
        static::deleted(fn (self $provider) => $provider->writeAuditRow('deleted'));
    }

    /**
     * Record one configuration change.
     *
     * Secret fields contribute their NAME to `changed_fields` and a literal "********" to the
     * before/after payloads. Storing the real values in an unencrypted audit table would undo the
     * encryption on the row it is auditing.
     *
     * Never throws: an audit-table failure must not roll back a legitimate credential save. The
     * write is skipped entirely when the table is absent (pre-migration environments).
     */
    public function writeAuditRow(string $action): void
    {
        try {
            if (!Schema::hasTable('integration_audits')) {
                return;
            }

            // An insert leaves getChanges() empty (performInsert never syncs them), so a creation
            // has to be described by the attributes it was created WITH.
            $dirty    = $action === 'created' ? $this->getAttributes() : $this->getChanges();
            $original = $action === 'created' ? [] : $this->getOriginal();
            unset($dirty['updated_at'], $dirty['created_at'], $dirty['id']);

            if ($dirty === [] && $action !== 'deleted') {
                return;
            }

            $mask = static function (string $field, mixed $value): mixed {
                return in_array($field, ['credentials'], true) ? '********' : $value;
            };

            $before = $after = [];
            foreach (array_keys($dirty) as $field) {
                $before[$field] = $mask($field, $original[$field] ?? null);
                $after[$field]  = $mask($field, $this->getAttribute($field));
            }

            // A credential change is reported as the field NAMES that were touched, resolved from
            // the decrypted bag — never the values themselves.
            $changed = array_keys($dirty);
            if (array_key_exists('credentials', $dirty)) {
                $changed = array_values(array_diff($changed, ['credentials']));
                foreach (array_keys((array) ($this->credentials ?? [])) as $name) {
                    $changed[] = "credentials.$name";
                }
            }

            $request = request();

            DB::table('integration_audits')->insert([
                'provider_slug'  => $this->provider_slug,
                'environment'    => $this->environment,
                'action'         => $action,
                'user_id'        => optional($request?->user())->id,
                'ip'             => $request?->ip(),
                'user_agent'     => substr((string) $request?->userAgent(), 0, 255) ?: null,
                'changed_fields' => json_encode($changed),
                'before'         => json_encode($before),
                'after'          => json_encode($after),
                'created_at'     => now(),
            ]);
        } catch (\Throwable) {
            // Auditing is best-effort. Losing an audit row is bad; losing the credential save that
            // an operator just made, because the audit table hiccuped, is worse.
        }
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
