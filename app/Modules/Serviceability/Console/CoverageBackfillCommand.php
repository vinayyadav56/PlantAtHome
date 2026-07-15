<?php

namespace App\Modules\Serviceability\Console;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use App\Modules\Serviceability\Infrastructure\Models\CoverageAuditLog;
use App\Modules\Serviceability\Infrastructure\Models\VendorCoverageRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time bridge from the legacy manual service areas to Delivery Coverage:
 * for every shop with MANUAL vendor_service_areas rows (source NULL), derive
 * coverage rules — a `city` rule per city row (resolved by case-insensitive
 * name against the cities master) and a `pincode_include` rule per pincode
 * row — then run ONE projection sync per shop.
 *
 * INVARIANT (printed per shop): after the sync, the bridge-derived city set
 * (vendor_service_areas source='coverage_sync') must be a SUPERSET of the
 * shop's original manual city names — the backfill may widen coverage (a city
 * rule covers every pin of the city) but must never lose a served city.
 * Cities that cannot be resolved, or that own no mapped postal codes, fail the
 * invariant and are listed so ops can fix the geo master and re-run (the
 * command is idempotent: existing identical rules are skipped by target_key).
 */
class CoverageBackfillCommand extends Command
{
    protected $signature = 'plantathome:coverage-backfill
        {--shop= : Only backfill this shop id}
        {--dry-run : Print the planned rules without writing anything}';

    protected $description = 'Derive Delivery Coverage rules from legacy manual vendor_service_areas rows';

    public function handle(DeliveryCoverageService $coverage): int
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('vendor_service_areas') || !$schema->hasColumn('vendor_service_areas', 'source')) {
            $this->error('vendor_service_areas (with the source column) is missing — run migrations first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $onlyShop = $this->option('shop') !== null ? (int) $this->option('shop') : null;

        $shopIds = DB::table('vendor_service_areas')
            ->whereNull('source')
            ->when($onlyShop !== null, fn ($q) => $q->where('shop_id', $onlyShop))
            ->distinct()->orderBy('shop_id')->pluck('shop_id')->map(fn ($id) => (int) $id);

        if ($shopIds->isEmpty()) {
            $this->info('No shops with manual service areas found — nothing to backfill.');

            return self::SUCCESS;
        }

        $pass = 0;
        $fail = 0;
        $planned = 0;
        $created = 0;
        $skipped = 0;

        foreach ($shopIds as $shopId) {
            $areas = DB::table('vendor_service_areas')
                ->where('shop_id', $shopId)->whereNull('source')
                ->get(['city', 'pincode']);

            $manualCities = $areas->pluck('city')
                ->map(fn ($c) => mb_strtolower(trim((string) $c)))
                ->filter()->unique()->values();

            // ── plan the rules ────────────────────────────────────────────
            $rules = []; // target_key => attrs
            $unresolved = [];
            foreach ($areas as $area) {
                $pin = preg_replace('/\D+/', '', (string) ($area->pincode ?? ''));
                if ($pin !== '') {
                    // Pincode-specific row → include rule (must exist in the master).
                    if (!preg_match('/^\d{6}$/', $pin) || !DB::table('postal_codes')->where('pincode', $pin)->exists()) {
                        $unresolved[] = "pincode {$pin} not in postal master";
                        continue;
                    }
                    $key = VendorCoverageRule::targetKey(VendorCoverageRule::TYPE_PINCODE_INCLUDE, $pin);
                    $rules[$key] = ['rule_type' => VendorCoverageRule::TYPE_PINCODE_INCLUDE, 'pincode' => $pin];
                    continue;
                }

                $cityName = trim((string) $area->city);
                if ($cityName === '') {
                    continue;
                }
                $matches = DB::table('cities')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($cityName)])
                    ->orderBy('id')->pluck('id');
                if ($matches->isEmpty()) {
                    $unresolved[] = "city '{$cityName}' not found in cities master";
                    continue;
                }
                if ($matches->count() > 1) {
                    $this->warn("  shop {$shopId}: city '{$cityName}' is ambiguous (" . $matches->count() . ' matches) — using id ' . $matches->first());
                }
                $cityId = (int) $matches->first();
                $key = VendorCoverageRule::targetKey(VendorCoverageRule::TYPE_CITY, $cityId);
                $rules[$key] = ['rule_type' => VendorCoverageRule::TYPE_CITY, 'city_id' => $cityId];
            }

            // Idempotency: skip rules the shop already has (by target_key).
            $existing = $rules === [] ? collect() : VendorCoverageRule::where('shop_id', $shopId)
                ->whereIn('target_key', array_keys($rules))
                ->pluck('target_key')->flip();
            $new = array_diff_key($rules, $existing->all());
            $planned += count($new);
            $skipped += count($rules) - count($new);

            $this->line(sprintf(
                'shop %d: %d manual area row(s) → %d rule(s) (%d new, %d existing)%s',
                $shopId,
                $areas->count(),
                count($rules),
                count($new),
                count($rules) - count($new),
                $unresolved ? ' — UNRESOLVED: ' . implode('; ', array_unique($unresolved)) : ''
            ));
            foreach ($new as $key => $attrs) {
                $this->line("    + {$key}");
            }

            if ($dryRun) {
                continue;
            }

            // ── write + one sync per shop ─────────────────────────────────
            foreach ($new as $key => $attrs) {
                VendorCoverageRule::updateOrCreate(
                    ['shop_id' => $shopId, 'target_key' => $key],
                    $attrs + ['is_active' => true]
                );
                $created++;
            }
            $stats = $coverage->syncCoverage($shopId);

            CoverageAuditLog::create([
                'shop_id' => $shopId,
                'user_id' => null,
                'action'  => 'backfill',
                'payload' => [
                    'rules_created' => count($new),
                    'rules_skipped' => count($rules) - count($new),
                    'unresolved'    => array_values(array_unique($unresolved)),
                    'stats'         => $stats,
                ],
            ]);

            // ── invariant: derived city set ⊇ original manual city set ────
            $derivedCities = DB::table('vendor_service_areas')
                ->where('shop_id', $shopId)->where('source', 'coverage_sync')
                ->pluck('city')
                ->map(fn ($c) => mb_strtolower(trim((string) $c)))
                ->flip();
            $missing = $manualCities->reject(fn ($c) => isset($derivedCities[$c]))->values();

            if ($missing->isEmpty()) {
                $pass++;
                $this->info("  shop {$shopId}: INVARIANT PASS ({$stats['pincodes']} pincode(s), {$stats['cities']} derived city(ies))");
            } else {
                $fail++;
                $this->error("  shop {$shopId}: INVARIANT FAIL — manual city(ies) not re-derived: " . $missing->implode(', '));
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->info(sprintf('[dry-run] %d shop(s), %d rule(s) planned, %d already present.', $shopIds->count(), $planned, $skipped));
        } else {
            $this->info(sprintf(
                '%d shop(s) processed: %d rule(s) created, %d skipped — invariant %d PASS / %d FAIL.',
                $shopIds->count(),
                $created,
                $skipped,
                $pass,
                $fail
            ));
        }

        return $fail > 0 && !$dryRun ? self::FAILURE : self::SUCCESS;
    }
}
