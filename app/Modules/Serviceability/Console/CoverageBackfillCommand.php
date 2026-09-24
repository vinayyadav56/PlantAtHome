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
        {--retire-manual : After a PASSING sync, deactivate the manual rows so rules are the only writer}
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
        $retire = (bool) $this->option('retire-manual');
        $onlyShop = $this->option('shop') !== null ? (int) $this->option('shop') : null;

        $shopIds = DB::table('vendor_service_areas')
            ->whereNull('source')
            ->when($onlyShop !== null, fn ($q) => $q->where('shop_id', $onlyShop))
            ->distinct()->orderBy('shop_id')->pluck('shop_id')->map(fn ($id) => (int) $id);

        // Orphaned service-area rows (their shop was deleted; the legacy table
        // has no FK) would violate vendor_coverage_rules' shops FK — skip and
        // report them instead of aborting the whole backfill.
        $existingShopIds = DB::table('shops')->whereIn('id', $shopIds)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $orphans = $shopIds->reject(fn (int $id) => in_array($id, $existingShopIds, true));
        foreach ($orphans as $orphanId) {
            $this->warn(sprintf('shop %d: SKIPPED — service-area rows exist but the shop was deleted (orphaned rows).', $orphanId));
        }
        $shopIds = $shopIds->filter(fn (int $id) => in_array($id, $existingShopIds, true))->values();

        if ($shopIds->isEmpty()) {
            $this->info('No shops with manual service areas found — nothing to backfill.');

            return self::SUCCESS;
        }

        $pass = 0;
        $fail = 0;
        $planned = 0;
        $created = 0;
        $skipped = 0;
        $promised = 0;
        $retired = 0;

        foreach ($shopIds as $shopId) {
            $areas = DB::table('vendor_service_areas')
                ->where('shop_id', $shopId)->whereNull('source')
                ->get(['city', 'pincode', 'fulfillment_mode', 'eta_days']);

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
                    } else {
                        $key = VendorCoverageRule::targetKey(VendorCoverageRule::TYPE_PINCODE_INCLUDE, $pin);
                        $rules[$key] = $this->promiseOf($area) + ['rule_type' => VendorCoverageRule::TYPE_PINCODE_INCLUDE, 'pincode' => $pin];
                    }
                    // A row can carry BOTH a pincode and a city — the city half
                    // still contributes coverage (dropping it shrank vendors'
                    // reach and failed the superset invariant on staging).
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
                $rules[$key] = $this->promiseOf($area) + ['rule_type' => VendorCoverageRule::TYPE_CITY, 'city_id' => $cityId];
            }

            // Idempotency: rules the shop already has are not re-created — but
            // they DO adopt the manual row's delivery promise when they carry
            // none. Skipping them wholesale is how a second run used to drop
            // every vendor to 'both' with no ETA: the promise lives on the
            // manual rows this command is about to retire.
            $existing = $rules === [] ? collect() : VendorCoverageRule::where('shop_id', $shopId)
                ->whereIn('target_key', array_keys($rules))
                ->get(['id', 'target_key', 'fulfillment_mode', 'eta_days'])
                ->keyBy('target_key');
            $new = array_diff_key($rules, $existing->all());

            $adopt = [];
            foreach ($existing as $key => $rule) {
                $fill = [];
                if ($rule->fulfillment_mode === null && !empty($rules[$key]['fulfillment_mode'])) {
                    $fill['fulfillment_mode'] = $rules[$key]['fulfillment_mode'];
                }
                if ($rule->eta_days === null && ($rules[$key]['eta_days'] ?? null) !== null) {
                    $fill['eta_days'] = $rules[$key]['eta_days'];
                }
                if ($fill !== []) {
                    $adopt[(int) $rule->id] = $fill;
                }
            }
            $planned += count($new);
            $skipped += count($rules) - count($new);

            $this->line(sprintf(
                'shop %d: %d manual area row(s) → %d rule(s) (%d new, %d existing, %d adopting a mode/ETA)%s',
                $shopId,
                $areas->count(),
                count($rules),
                count($new),
                count($rules) - count($new),
                count($adopt),
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
            foreach ($adopt as $ruleId => $fill) {
                VendorCoverageRule::where('id', $ruleId)->update($fill);
                $promised++;
            }
            $stats = $coverage->syncCoverage($shopId);

            CoverageAuditLog::create([
                'shop_id' => $shopId,
                'user_id' => null,
                'action'  => 'backfill',
                'payload' => [
                    'rules_created'  => count($new),
                    'rules_skipped'  => count($rules) - count($new),
                    'rules_promised' => count($adopt),
                    'retired'        => $retire,
                    'unresolved'     => array_values(array_unique($unresolved)),
                    'stats'          => $stats,
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

                // Rules become the ONLY writer — but only for a shop whose
                // every manual city came back through the projection. Retiring
                // on a FAIL would delete a served city outright.
                if ($retire) {
                    $n = DB::table('vendor_service_areas')
                        ->where('shop_id', $shopId)->whereNull('source')
                        ->update(['is_active' => false, 'source' => 'migrated', 'updated_at' => now()]);
                    $retired += $n;
                    $this->line("  shop {$shopId}: retired {$n} manual row(s) → source='migrated'");
                }
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
                '%d shop(s) processed: %d rule(s) created, %d skipped, %d given a mode/ETA, %d manual row(s) retired — invariant %d PASS / %d FAIL.',
                $shopIds->count(),
                $created,
                $skipped,
                $promised,
                $retired,
                $pass,
                $fail
            ));
        }

        return $fail > 0 && !$dryRun ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The delivery promise a manual row was carrying. It has to move onto the
     * rule before the row is retired, or every migrated vendor silently becomes
     * courier-capable everywhere with a default ETA.
     *
     * @return array{fulfillment_mode?:string, eta_days?:int}
     */
    private function promiseOf(object $area): array
    {
        $out = [];
        if (!empty($area->fulfillment_mode)) {
            $out['fulfillment_mode'] = (string) $area->fulfillment_mode;
        }
        if (($area->eta_days ?? null) !== null) {
            $out['eta_days'] = (int) $area->eta_days;
        }

        return $out;
    }
}
