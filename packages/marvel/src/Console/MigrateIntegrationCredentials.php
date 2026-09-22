<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\IntegrationService;
use Marvel\Integrations\ProviderRegistry;
use Marvel\Integrations\Store\CredentialStore;
use Marvel\Integrations\Store\DatabaseCredentialStore;
use Throwable;

/**
 * Move credential bags out of the encrypted `integration_providers.credentials` column and into
 * the bound credential store (AWS Secrets Manager on production and staging).
 *
 * Per row in the target environment that still holds a bag:
 *   1. write the bag to the store             (skipped when the row was migrated before)
 *   2. read it straight back and compare       (a copy that cannot be read back is not a copy)
 *   3. record the secret name/version on the row — WITHOUT bumping credentials_version: the
 *      bag's content has not changed, so the Go shipping service has nothing new to fetch
 *   4. with --purge, empty the column and write an audit row saying so
 *
 * Idempotent: a migrated-and-purged row is reported and left alone; a migrated-but-not-purged
 * row is not re-written (the store is already the source of truth) and only purged on request.
 * Purge is the one-way step, so it is opt-in and meant to run only once the health sweep shows
 * every enabled provider connected through the store.
 *
 * Providers that declare no credential fields (aws_s3 — identity is the IAM role now) get no
 * secret at all; with --purge their stale bag is simply dropped.
 */
class MigrateIntegrationCredentials extends Command
{
    protected $signature = 'integrations:migrate-credentials
        {--environment= : Rows to migrate (default: the active integrations environment)}
        {--relabel= : Rename rows first, as from:to — e.g. sandbox:staging}
        {--dry-run : Report what would happen and write nothing}
        {--purge : Empty the database column after a verified copy (one-way)}';

    protected $description = 'Copy integration credential bags from the encrypted DB column into the bound credential store, verify, and optionally purge the column';

    public function handle(): int
    {
        if (!Schema::hasTable('integration_providers')) {
            $this->error('integration_providers does not exist here.');

            return self::FAILURE;
        }

        $store = app(CredentialStore::class);
        $dryRun = (bool) $this->option('dry-run');
        $purge = (bool) $this->option('purge');
        $environment = (string) ($this->option('environment') ?: (new IntegrationService())->environment());

        if ($store->driver() === CredentialStore::DRIVER_DATABASE) {
            $this->error('The bound credential store IS the database — nothing to migrate to. Set INTEGRATIONS_CREDENTIAL_STORE=secrets_manager.');

            return self::FAILURE;
        }

        if ($relabel = (string) $this->option('relabel')) {
            $this->relabel($relabel, $dryRun);
        }

        $column = new DatabaseCredentialStore();
        $rows = IntegrationProvider::query()->where('environment', $environment)->orderBy('provider_slug')->get();
        if ($rows->isEmpty()) {
            $this->info("No integration rows for environment '{$environment}'.");

            return self::SUCCESS;
        }

        $this->line(($dryRun ? '[dry-run] ' : '') . "Migrating {$rows->count()} row(s) for environment '{$environment}' into the {$store->driver()} store" . ($purge ? ', purging the column after verification' : '') . '.');

        $report = [];
        $failures = 0;
        foreach ($rows as $row) {
            [$line, $ok] = $this->migrateRow($row, $store, $column, $environment, $dryRun, $purge);
            $report[] = $line;
            if (!$ok) {
                $failures++;
            }
        }

        $this->table(['provider', 'action', 'secret', 'verified', 'column'], $report);

        // Nothing reads a row outside the active environment any more (the cross-environment
        // fallback was removed when secrets became environment-scoped), so a row left under
        // another label is invisible to the running app. Worth saying out loud rather than
        // leaving someone to find it when a provider stops working.
        $others = IntegrationProvider::query()
            ->where('environment', '!=', $environment)
            ->whereNotNull('credentials')
            ->get(['provider_slug', 'environment']);
        if ($others->isNotEmpty()) {
            $this->warn('Rows holding credentials under a DIFFERENT environment label (not touched, and not read by this deployment):');
            foreach ($others as $other) {
                $this->line("  {$other->provider_slug} [{$other->environment}] — re-enter it under '{$environment}', or run again with --relabel={$other->environment}:{$environment}");
            }
        }

        if ($failures > 0) {
            $this->error("{$failures} row(s) could not be verified in the store and were NOT purged.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array{0: array<int,string>, 1: bool} */
    private function migrateRow(IntegrationProvider $row, CredentialStore $store, DatabaseCredentialStore $column, string $environment, bool $dryRun, bool $purge): array
    {
        $slug = (string) $row->provider_slug;
        $def = ProviderRegistry::find($slug);
        $declared = $def ? $def->credentialNames() : [];
        $columnBag = $column->get($row) ?? [];
        $bag = array_intersect_key($columnBag, array_flip($declared));
        $alreadyStored = !empty($row->secret_name);

        // A bag this command cannot copy is a bag it must not destroy. Both of these mean the
        // registry no longer declares the fields the column holds — aws_s3 after its AWS keys
        // moved to the IAM role is the known case, but the same branch would fire for a typo or
        // a half-finished registry edit, and purging there would erase live credentials with no
        // copy anywhere. Reported and LEFT ALONE; the column is encrypted and unread.
        if ($columnBag !== [] && $bag === []) {
            $note = $declared === []
                ? 'no credential fields declared — bag left in place'
                : 'bag holds no declared fields — left in place';

            return [[$slug, $note, (string) ($row->secret_name ?? '—'), '—', 'kept'], true];
        }

        if ($bag === []) {
            return [[$slug, $alreadyStored ? 'already migrated' : 'nothing stored', (string) ($row->secret_name ?? '—'), '—', 'empty'], true];
        }

        if ($dryRun) {
            // A dry run that makes no AWS call is a weak pre-flight for a one-way operation, so
            // it does the one call that proves reachability AND permission without writing.
            try {
                $store->get($row);
                $reachable = 'store reachable';
            } catch (Throwable $e) {
                $reachable = 'STORE UNREACHABLE: ' . class_basename($e);
            }

            return [[$slug, ($alreadyStored ? 'would verify' : 'would copy ' . count($bag) . ' field(s)') . " ({$reachable})", $store->secretName($row) ?? '—', 'n/a', $purge ? 'would purge' : 'kept'], true];
        }

        try {
            if (!$alreadyStored) {
                $store->put($row, $bag);
            }
            $readback = $store->get($row) ?? [];
        } catch (Throwable $e) {
            $this->warn("{$slug}: store write failed — " . get_class($e));

            return [[$slug, 'FAILED: store write', $store->secretName($row) ?? '—', 'no', 'kept'], false];
        }

        // Compare VALUES, field by field. An earlier version intersected KEYS, which a single
        // overlapping field name satisfied — so a store holding one stale field would have
        // "verified" a five-field bag and the purge would have destroyed the other four.
        // Extra fields in the store are fine: it may legitimately be newer than the column.
        $verified = true;
        foreach ($bag as $field => $value) {
            if (!array_key_exists($field, $readback) || $readback[$field] !== $value) {
                $verified = false;
                break;
            }
        }
        if (!$verified) {
            $this->warn("{$slug}: the store does not hold every column field verbatim; column left intact.");

            return [[$slug, 'FAILED: readback mismatch', $store->secretName($row) ?? '—', 'no', 'kept'], false];
        }

        // Metadata only, through the query builder: no model events, so no phantom
        // credentials_version bump (the bag's content did not change) and no duplicate audit.
        DB::table('integration_providers')->where('id', $row->id)->update([
            'secret_name'       => $row->secret_name,
            'secret_version_id' => $row->secret_version_id,
            'updated_at'        => now(),
        ]);

        $columnState = 'kept';
        if ($purge) {
            $this->purge($row, $row->secret_name);
            $columnState = 'purged';
        }

        (new IntegrationService())->forget($slug);

        return [[$slug, $alreadyStored ? 'verified' : 'copied ' . count($bag) . ' field(s)', (string) $row->secret_name, 'yes', $columnState], true];
    }

    /** Empty the column and say so in the audit trail — the one-way step. */
    private function purge(IntegrationProvider $row, ?string $secretName): void
    {
        DB::table('integration_providers')->where('id', $row->id)->update([
            'credentials' => null,
            'updated_at'  => now(),
        ]);

        if (!Schema::hasTable('integration_audits')) {
            return;
        }
        DB::table('integration_audits')->insert([
            'provider_slug'  => $row->provider_slug,
            'environment'    => $row->environment,
            'action'         => 'migrated',
            'user_id'        => null, // console
            'ip'             => null,
            'user_agent'     => 'integrations:migrate-credentials',
            'changed_fields' => json_encode(['credentials.*', 'secret_name']),
            'before'         => json_encode(['credentials' => '********', 'secret_name' => null]),
            'after'          => json_encode(['credentials' => null, 'secret_name' => $secretName]),
            'created_at'     => now(),
        ]);
    }

    /**
     * Rename an environment label row by row, refusing any rename that would collide with an
     * existing (slug, environment) pair rather than tripping the unique index mid-way.
     */
    private function relabel(string $spec, bool $dryRun): void
    {
        [$from, $to] = array_pad(array_map('trim', explode(':', $spec, 2)), 2, '');
        if ($from === '' || $to === '') {
            $this->error("--relabel expects from:to, got '{$spec}'.");

            return;
        }

        $rows = IntegrationProvider::query()->where('environment', $from)->get();
        foreach ($rows as $row) {
            $collides = IntegrationProvider::query()->where('provider_slug', $row->provider_slug)->where('environment', $to)->exists();
            if ($collides) {
                $this->warn("{$row->provider_slug}: a '{$to}' row already exists; leaving the '{$from}' row alone.");
                continue;
            }
            if (!$dryRun) {
                // secret_name embeds the environment, so a relabelled row must forget it or the
                // migration treats it as already stored and verifies against the wrong secret.
                DB::table('integration_providers')->where('id', $row->id)->update([
                    'environment'       => $to,
                    'secret_name'       => null,
                    'secret_version_id' => null,
                    'updated_at'        => now(),
                ]);
            }
            $this->line(($dryRun ? '[dry-run] ' : '') . "relabelled {$row->provider_slug}: {$from} → {$to}");
        }
    }
}
