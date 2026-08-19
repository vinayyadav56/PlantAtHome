<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Executes a CleanupPlan safely, or refuses to.
 *
 * Every destructive guarantee lives here rather than in the individual cleaners, so a new
 * module cannot accidentally opt out of one:
 *   • a DENY list that no plan may name (catalog, taxonomy, config, RBAC);
 *   • a snapshot of every row taken BEFORE deletion, stored on the run — a hard delete you
 *     can undo;
 *   • one transaction, so a failure mid-graph rolls back instead of orphaning it;
 *   • production ceremony + the Launch Guard;
 *   • query-builder deletes (never model deletes), because model events would fire vendor
 *     notifications and availability recomputes thousands of times mid-transaction.
 */
class CleanupRunner
{
    /**
     * Tables this system may NEVER delete from, whatever a plan says. These hold the catalog
     * and the platform's configuration — the things the business actually built. Enforced as
     * a hard exception, not a convention, so the protection survives future refactors.
     */
    public const PROTECTED_TABLES = [
        'products', 'variation_options', 'category_product', 'categories', 'types',
        'plant_attributes', 'plant_attribute_definitions', 'plant_attribute_terms',
        'plant_attribute_product', 'plant_collections', 'attributes', 'attribute_values',
        'attribute_product', 'product_tag', 'tags', 'manufacturers', 'authors',
        'settings', 'cities', 'states', 'districts', 'roles', 'permissions', 'designations',
        'email_templates', 'email_events', 'integration_providers', 'refund_reasons',
        'refund_policies', 'shipping_classes', 'migrations',
    ];

    public const CONFIRM_PHRASE = 'RESET PRODUCTION TEST DATA';

    /**
     * Resolved environment. Deliberately NOT app()->environment(): in this deployment staging
     * also reports APP_ENV=production (see routes/web.php — the emergency route gates on a
     * secret for the same reason), so an env-name check would be silently wrong. An unset
     * TESTDATA_ENV yields 'production', i.e. maximum ceremony, never less.
     */
    public function environment(): string
    {
        $v = strtolower((string) env('TESTDATA_ENV', 'production'));
        return $v === 'staging' ? 'staging' : 'production';
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    /** @throws \RuntimeException when the plan names a protected table. */
    public function assertPlanIsSafe(CleanupPlan $plan): void
    {
        // Nullifications may name a protected table (that is the point — the catalog row
        // survives with a cleared pointer), but only ever to NULL a column, never to delete.
        $violations = array_intersect($plan->tables(), self::PROTECTED_TABLES);
        if ($violations) {
            throw new \RuntimeException(
                'Refused: this cleanup would touch protected catalog/config tables: '
                . implode(', ', $violations)
            );
        }
    }

    /**
     * The Launch Guard. Production cleanup is a ONE-TIME pre-launch reset; the same action a
     * month after launch would destroy a live business. So: refuse once the system looks live
     * (a successful payment exists, or a previous pre-launch reset was recorded) unless the
     * operator explicitly overrides.
     *
     * @return array{blocked: bool, reasons: array}
     */
    public function launchGuard(): array
    {
        if (!$this->isProduction()) {
            return ['blocked' => false, 'reasons' => []];
        }
        $reasons = [];
        try {
            $paid = Schema::hasTable('orders')
                ? DB::table('orders')->where('payment_status', 'payment-success')->count()
                : 0;
            if ($paid > 0) {
                $reasons[] = "{$paid} order(s) have a successful payment — this system looks live.";
            }
            $done = Schema::hasTable('settings')
                ? DB::table('test_data_cleanup_runs')->where('mode', 'execute')
                    ->where('environment', 'production')->where('status', 'completed')->first()
                : null;
            if ($done) {
                $reasons[] = "A production cleanup already ran on {$done->finished_at} (run #{$done->id}).";
            }
        } catch (\Throwable $e) {
            $reasons[] = 'Could not verify launch state: ' . $e->getMessage();
        }
        return ['blocked' => (bool) $reasons, 'reasons' => $reasons];
    }

    /** Preview: exact per-table impact, no writes beyond the audit row. */
    public function preview(CleanerContract $cleaner, array $scope): array
    {
        $plan = $cleaner->plan($scope);
        $this->assertPlanIsSafe($plan);
        $guard = $this->launchGuard();

        return [
            'module'        => $cleaner->key(),
            'label'         => $cleaner->label(),
            'description'   => $cleaner->description(),
            'environment'   => $this->environment(),
            'scope'         => $scope,
            'table_counts'  => $plan->counts(),
            'total_rows'    => $plan->totalRows(),
            'warnings'      => $plan->warnings,
            'launch_guard'  => $guard,
            'requires_phrase' => $this->isProduction() ? self::CONFIRM_PHRASE : null,
            'protected_tables_untouched' => true,
        ];
    }

    /**
     * Execute the plan: snapshot → delete (children first) → audit.
     *
     * @param array $opts confirm_phrase, acknowledge, override_launch_guard, backup_reference
     */
    public function execute(CleanerContract $cleaner, array $scope, array $opts, $actor = null): array
    {
        $plan = $cleaner->plan($scope);
        $this->assertPlanIsSafe($plan);

        if ($this->isProduction()) {
            if (($opts['confirm_phrase'] ?? null) !== self::CONFIRM_PHRASE) {
                throw new \RuntimeException('Production cleanup requires the exact confirmation phrase.');
            }
            if (empty($opts['acknowledge'])) {
                throw new \RuntimeException('Production cleanup requires explicit acknowledgement that this is permanent.');
            }
            if (empty($opts['backup_reference'])) {
                throw new \RuntimeException('Production cleanup requires a fresh database backup reference (run the cat-backup data-op first).');
            }
            $guard = $this->launchGuard();
            if ($guard['blocked'] && empty($opts['override_launch_guard'])) {
                throw new \RuntimeException('Launch Guard: ' . implode(' ', $guard['reasons'])
                    . ' Override explicitly if this is genuinely still pre-launch.');
            }
        }

        $runId = DB::table('test_data_cleanup_runs')->insertGetId([
            'module' => $cleaner->key(), 'mode' => 'execute', 'environment' => $this->environment(),
            'scope' => json_encode($scope), 'status' => 'pending',
            'backup_reference' => $opts['backup_reference'] ?? null,
            'actor_user_id' => $actor?->id, 'actor_name' => $actor?->name,
            'ip' => request()?->ip(), 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $snapshot = [];
            $counts = [];

            DB::transaction(function () use ($plan, &$snapshot, &$counts) {
                // Clear surviving references first (a catalog product keeps existing with its
                // proposer nulled), so the later deletes cannot strand a pointer.
                foreach ($plan->nullifications as $n) {
                    if (Schema::hasTable($n['table']) && Schema::hasColumn($n['table'], $n['column'])) {
                        DB::table($n['table'])->whereIn($n['column'], $n['ids'])->update([$n['column'] => null]);
                    }
                }
                foreach ($plan->steps as $step) {
                    if (!Schema::hasTable($step['table'])) {
                        continue;
                    }
                    // Chunked so a large plan cannot blow the packet limit or memory.
                    foreach (array_chunk($step['ids'], 500) as $chunk) {
                        $rows = DB::table($step['table'])->whereIn($step['column'], $chunk)->get();
                        if ($rows->isEmpty()) {
                            continue;
                        }
                        $snapshot[$step['table']] = array_merge(
                            $snapshot[$step['table']] ?? [],
                            $rows->map(fn ($r) => (array) $r)->all()
                        );
                        $deleted = DB::table($step['table'])->whereIn($step['column'], $chunk)->delete();
                        $counts[$step['table']] = ($counts[$step['table']] ?? 0) + $deleted;
                    }
                }
            });

            DB::table('test_data_cleanup_runs')->where('id', $runId)->update([
                'table_counts' => json_encode($counts),
                'total_rows'   => array_sum($counts),
                'snapshot'     => json_encode($snapshot),
                'status'       => 'completed',
                'finished_at'  => now(),
                'updated_at'   => now(),
            ]);

            // Availability/caches are rebuilt ONCE, after the graph is consistent — never per row.
            $this->afterCleanup($cleaner->key());

            return ['ok' => true, 'run_id' => $runId, 'table_counts' => $counts, 'total_rows' => array_sum($counts)];
        } catch (\Throwable $e) {
            DB::table('test_data_cleanup_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => substr($e->getMessage(), 0, 2000),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    /**
     * Restore a completed run from its snapshot. Inserts are ignore-on-conflict so a partial
     * restore (some rows already re-created) still converges.
     */
    public function restore(int $runId): array
    {
        $run = DB::table('test_data_cleanup_runs')->where('id', $runId)->first();
        if (!$run || $run->status !== 'completed') {
            throw new \RuntimeException('Only a completed run can be restored.');
        }
        $snapshot = json_decode($run->snapshot ?? '[]', true) ?: [];
        $restored = [];

        DB::transaction(function () use ($snapshot, &$restored) {
            // Reverse order: parents back before children, mirroring the deletion order.
            foreach (array_reverse($snapshot, true) as $table => $rows) {
                if (!Schema::hasTable($table) || !$rows) {
                    continue;
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insertOrIgnore($chunk);
                }
                $restored[$table] = count($rows);
            }
        });

        DB::table('test_data_cleanup_runs')->where('id', $runId)
            ->update(['status' => 'restored', 'updated_at' => now()]);

        return ['ok' => true, 'restored' => $restored];
    }

    /** One recompute + cache bust per run, for the modules whose data feeds the storefront. */
    private function afterCleanup(string $module): void
    {
        if (!in_array($module, ['vendors', 'vendor_inventory', 'prices'], true)) {
            return;
        }
        try {
            \Illuminate\Support\Facades\Cache::increment('products:ver');
            \Illuminate\Support\Facades\Artisan::call('marvel:recompute-city-availability');
        } catch (\Throwable $e) {
            Log::warning('testdata cleanup: post-run recompute failed', ['error' => $e->getMessage()]);
        }
    }
}
