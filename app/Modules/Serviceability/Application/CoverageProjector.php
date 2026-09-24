<?php

namespace App\Modules\Serviceability\Application;

use App\Modules\Serviceability\Infrastructure\Models\VendorCoverageRule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;

/**
 * Rewrites the vendor_covered_pincodes projection for one vendor from its
 * active coverage rules, then bridges the derived cities into the legacy
 * vendor_service_areas table (source='coverage_sync') so the existing
 * city-availability engine keeps working unchanged. Pure sync internals —
 * cache bumps + events live in DeliveryCoverageService::syncCoverage().
 */
class CoverageProjector
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * Project one vendor, one map per vertical. Rules scoped to vertical X
     * project X's rows ALONE (a named vertical REPLACES the '*' default rather
     * than adding to it); verticals the vendor never named are answered by the
     * '*' map. The legacy city bridge sees the union of all of them.
     *
     * @return array{pincodes:int, by_source:array<string,int>, cities:int, unknown_pincodes:string[], by_vertical:array<string,int>, duration_ms:int}
     */
    public function project(int $shopId): array
    {
        $startedAt = microtime(true);

        $byVertical = [];
        foreach (VendorCoverageRule::where('shop_id', $shopId)->where('is_active', true)->get() as $rule) {
            $byVertical[$rule->vertical ?: VendorCoverageRule::VERTICAL_ALL][] = $rule->toArray();
        }

        $maps = [];
        $unknown = [];
        foreach ($byVertical as $vertical => $rules) {
            [$maps[$vertical], $missing] = $this->computeMap($rules);
            $unknown = array_merge($unknown, $missing);
        }

        // Full rewrite: the projection has no timestamps/identity worth keeping.
        $this->db->table('vendor_covered_pincodes')->where('shop_id', $shopId)->delete();
        $rows = [];
        $bySource = [];
        $byVerticalCount = [];
        $union = [];
        foreach ($maps as $vertical => $map) {
            $byVerticalCount[$vertical] = count($map);
            foreach ($map as $pincode => $hit) {
                $rows[] = [
                    'shop_id'          => $shopId,
                    'pincode'          => (string) $pincode,
                    'vertical'         => (string) $vertical,
                    'source'           => $hit['source'],
                    'fulfillment_mode' => $hit['fulfillment_mode'],
                    'eta_days'         => $hit['eta_days'],
                    'state_id'         => $hit['state_id'],
                    'district_id'      => $hit['district_id'],
                    'city_id'          => $hit['city_id'],
                ];
                $bySource[$hit['source']] = ($bySource[$hit['source']] ?? 0) + 1;
                // The '*' map wins the bridge's mode/ETA: it is the vendor's default promise.
                if (! isset($union[$pincode]) || $vertical === VendorCoverageRule::VERTICAL_ALL) {
                    $union[$pincode] = $hit;
                }
            }
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            $this->db->table('vendor_covered_pincodes')->insert($chunk);
        }

        $cityNames = $this->bridgeToLegacyServiceAreas($shopId, $union);

        return [
            'pincodes'         => count($rows),
            'by_source'        => $bySource,
            'by_vertical'      => $byVerticalCount,
            'cities'           => count($cityNames),
            'unknown_pincodes' => array_values(array_unique($unknown)),
            'duration_ms'      => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * The projection ladder, shared with previewCoverage (no writes). Rules
     * apply in ASCENDING priority — state, district, city, include — each tier
     * overwriting the source AND the delivery promise of pins it re-covers;
     * the excludes (whole district, whole city, single pin) unset pins last.
     * Include pins missing from the postal master are reported, never added.
     *
     * Every rule in $rules is assumed to belong to ONE vertical: project()
     * groups them before calling this.
     *
     * @param  array<int, array{rule_type:string, state_id?:int|null, district_id?:int|null, city_id?:int|null, pincode?:string|null, fulfillment_mode?:string|null, eta_days?:int|null}>  $rules
     * @return array{0: array<string, array{source:string, state_id:?int, district_id:?int, city_id:?int, fulfillment_mode:?string, eta_days:?int}>, 1: string[]}
     */
    public function computeMap(array $rules): array
    {
        // Each tier keeps its targets AND the delivery promise declared per
        // target, so a pin picks up the mode/ETA of the rule that covered it.
        $byType = ['state' => [], 'district' => [], 'city' => [], 'include' => [], 'exclude' => [], 'district_exclude' => [], 'city_exclude' => []];
        foreach ($rules as $rule) {
            $promise = [
                'fulfillment_mode' => $rule['fulfillment_mode'] ?? null,
                'eta_days'         => isset($rule['eta_days']) && $rule['eta_days'] !== null ? (int) $rule['eta_days'] : null,
            ];
            match ($rule['rule_type'] ?? null) {
                VendorCoverageRule::TYPE_STATE            => $byType['state'][(int) $rule['state_id']] = $promise,
                VendorCoverageRule::TYPE_DISTRICT         => $byType['district'][(int) $rule['district_id']] = $promise,
                VendorCoverageRule::TYPE_CITY             => $byType['city'][(int) $rule['city_id']] = $promise,
                VendorCoverageRule::TYPE_PINCODE_INCLUDE  => $byType['include'][(string) $rule['pincode']] = $promise,
                VendorCoverageRule::TYPE_PINCODE_EXCLUDE  => $byType['exclude'][] = (string) $rule['pincode'],
                VendorCoverageRule::TYPE_DISTRICT_EXCLUDE => $byType['district_exclude'][] = (int) $rule['district_id'],
                VendorCoverageRule::TYPE_CITY_EXCLUDE     => $byType['city_exclude'][] = (int) $rule['city_id'],
                default                                   => null,
            };
        }

        $map = [];
        $collect = function ($query, string $source, array $promises, string $key) use (&$map) {
            foreach ($query->get(['pincode', 'state_id', 'district_id', 'city_id']) as $row) {
                $promise = $promises[$key === 'pincode' ? (string) $row->pincode : (int) $row->{$key}] ?? [];
                $map[(string) $row->pincode] = [
                    'source'           => $source,
                    'state_id'         => $row->state_id !== null ? (int) $row->state_id : null,
                    'district_id'      => $row->district_id !== null ? (int) $row->district_id : null,
                    'city_id'          => $row->city_id !== null ? (int) $row->city_id : null,
                    'fulfillment_mode' => $promise['fulfillment_mode'] ?? null,
                    'eta_days'         => $promise['eta_days'] ?? null,
                ];
            }
        };

        if ($byType['state'] !== []) {
            $collect($this->activePins()->whereIn('state_id', array_keys($byType['state'])), 'state', $byType['state'], 'state_id');
        }
        if ($byType['district'] !== []) {
            $collect($this->activePins()->whereIn('district_id', array_keys($byType['district'])), 'district', $byType['district'], 'district_id');
        }
        if ($byType['city'] !== []) {
            $collect($this->activePins()->whereIn('city_id', array_keys($byType['city'])), 'city', $byType['city'], 'city_id');
        }

        $unknown = [];
        if ($byType['include'] !== []) {
            // PHP turns numeric array keys into ints; pincodes are strings on
            // both sides of the query and in the reported list.
            $wanted = array_map('strval', array_keys($byType['include']));
            $collect($this->activePins()->whereIn('pincode', $wanted), 'manual', $byType['include'], 'pincode');
            $unknown = array_values(array_filter(
                $wanted,
                fn (string $pin) => ($map[$pin]['source'] ?? null) !== 'manual',
            ));
        }

        // Removals last, so "the whole state EXCEPT this district" is one rule
        // pair rather than hundreds of pincode excludes.
        if ($byType['district_exclude'] !== [] || $byType['city_exclude'] !== []) {
            $districts = array_flip($byType['district_exclude']);
            $cities = array_flip($byType['city_exclude']);
            foreach ($map as $pin => $hit) {
                if (($hit['district_id'] !== null && isset($districts[$hit['district_id']]))
                    || ($hit['city_id'] !== null && isset($cities[$hit['city_id']]))) {
                    unset($map[$pin]);
                }
            }
        }
        foreach ($byType['exclude'] as $pin) {
            unset($map[(string) $pin]);
        }

        return [$map, $unknown];
    }

    private function activePins()
    {
        return $this->db->table('postal_codes')->where('status', 'active');
    }

    /**
     * Bridge the derived city set into legacy vendor_service_areas so the
     * marvel AvailabilityService (product_city_availability) sees coverage
     * changes. Only rows tagged source='coverage_sync' are ever written or
     * pruned; a vendor's manual rows (source NULL) are read-only here — their
     * fulfillment_mode/eta_days are inherited for the same city.
     *
     * @param  array<string, array{city_id:?int, fulfillment_mode:?string, eta_days:?int}>  $map
     * @return string[] derived city names
     */
    private function bridgeToLegacyServiceAreas(int $shopId, array $map): array
    {
        $schema = $this->db->getSchemaBuilder();
        // Without the source tag we cannot tell bridge rows from manual ones —
        // skip rather than risk clobbering a vendor's hand-entered areas.
        if (! $schema->hasTable('vendor_service_areas') || ! $schema->hasColumn('vendor_service_areas', 'source')) {
            return [];
        }

        // Per city: that it is covered, and the delivery promise its pins
        // carry. The promise has to come from the projection now — the manual
        // rows it used to be read from are being retired, and defaulting every
        // rules-only vendor to 'both' made them silently courier-capable
        // everywhere.
        $cityIds = [];
        $promiseByCityId = [];
        foreach ($map as $hit) {
            if ($hit['city_id'] === null) {
                continue;
            }
            $cityId = $hit['city_id'];
            $cityIds[$cityId] = true;
            if (($hit['fulfillment_mode'] ?? null) !== null && !isset($promiseByCityId[$cityId]['fulfillment_mode'])) {
                $promiseByCityId[$cityId]['fulfillment_mode'] = $hit['fulfillment_mode'];
            }
            if (($hit['eta_days'] ?? null) !== null && !isset($promiseByCityId[$cityId]['eta_days'])) {
                $promiseByCityId[$cityId]['eta_days'] = $hit['eta_days'];
            }
        }

        $cityNames = [];
        $promiseByName = [];
        if ($cityIds !== [] && $schema->hasTable('cities')) {
            // Project the CANONICAL city. A pincode's city row may be a subdivision, and writing
            // "South Delhi" into vendor_service_areas would make this vendor's coverage invisible
            // to every Delhi shopper — the bridge would have manufactured the exact split the
            // canonical model exists to prevent.
            $rows = $this->db->table('cities')->whereIn('id', array_keys($cityIds))->get(['id', 'name']);
            $normalizer = new \Marvel\Services\LocationNormalizer();
            $promiseByName = [];
            foreach ($rows as $r) {
                $name = $normalizer->normalize(['city' => $r->name])['city'] ?: $r->name;
                $cityNames[] = $name;
                foreach ($promiseByCityId[(int) $r->id] ?? [] as $field => $value) {
                    $promiseByName[$name][$field] ??= $value;
                }
            }
            $cityNames = array_values(array_unique($cityNames));
        }

        $manual = [];
        $manualRows = $this->db->table('vendor_service_areas')
            ->where('shop_id', $shopId)->whereNull('source')->get(['city', 'fulfillment_mode', 'eta_days']);
        foreach ($manualRows as $row) {
            $manual[\Marvel\Services\AvailabilityService::canonicalCityKey((string) $row->city)] = $row;
        }

        $now = now();
        foreach ($cityNames as $name) {
            // Canonical key on both sides, so a vendor's hand-entered "Gurgaon" row still hands its
            // mode/ETA down to the bridge row the master calls "Gurugram".
            $inherit = $manual[\Marvel\Services\AvailabilityService::canonicalCityKey((string) $name)] ?? null;
            // The rule's own promise wins; a manual row is the fallback while
            // one still exists; 'both' is the last resort.
            $values = [
                'fulfillment_mode' => $promiseByName[$name]['fulfillment_mode']
                    ?? $inherit->fulfillment_mode
                    ?? 'both',
                'eta_days'         => $promiseByName[$name]['eta_days'] ?? $inherit->eta_days ?? null,
                'is_active'        => true,
                'updated_at'       => $now,
            ];
            $match = $this->db->table('vendor_service_areas')
                ->where('shop_id', $shopId)->where('city', $name)
                ->whereNull('pincode')->where('source', 'coverage_sync');
            if ((clone $match)->exists()) {
                $match->update($values);
            } else {
                $this->db->table('vendor_service_areas')->insert($values + [
                    'shop_id'    => $shopId,
                    'city'       => $name,
                    'pincode'    => null,
                    'source'     => 'coverage_sync',
                    'created_at' => $now,
                ]);
            }
        }

        // Prune bridge rows for cities no longer derived (manual rows untouched).
        $this->db->table('vendor_service_areas')
            ->where('shop_id', $shopId)
            ->where('source', 'coverage_sync')
            ->when($cityNames !== [], fn ($q) => $q->whereNotIn('city', $cityNames))
            ->delete();

        $this->refreshLegacyAvailability($shopId, $cityNames);

        return $cityNames;
    }

    /**
     * Best-effort downstream refresh — recompute the vendor's city-availability
     * projection and surface newly served cities in the storefront picker
     * (equivalent of ShopRepository::activateServedCities, which is private
     * there). Never throws: coverage sync must not fail on legacy hiccups.
     */
    private function refreshLegacyAvailability(int $shopId, array $cityNames): void
    {
        if (class_exists(\Marvel\Services\AvailabilityService::class)) {
            try {
                \Marvel\Jobs\RecomputeShopAvailabilityJob::dispatch($shopId);
            } catch (\Throwable $e) {
                Log::warning('coverage-sync: recomputeForShop failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
            }
        }

        if ($cityNames === []) {
            return;
        }

        try {
            $schema = $this->db->getSchemaBuilder();
            if ($schema->hasTable('cities') && $schema->hasColumn('cities', 'is_serviceable')) {
                // Canonical keys + their raw spellings: the pincode master can name a coverage
                // city after a district ("South Delhi"), and activating THAT row leaves the real
                // city switched off. Districts are never activated — they are not destinations.
                $lower = [];
                foreach ($cityNames as $n) {
                    $key = \Marvel\Services\AvailabilityService::canonicalCityKey((string) $n);
                    if ($key !== '') {
                        $lower = array_merge($lower, \Marvel\Services\AvailabilityService::canonicalCityVariants($key));
                    }
                }
                $lower = array_values(array_unique($lower));
                $this->db->table('cities')
                    ->whereIn($this->db->raw('LOWER(name)'), $lower)
                    ->when(
                        $schema->hasColumn('cities', 'is_subdivision'),
                        fn ($q) => $q->where('is_subdivision', false),
                    )
                    ->where('status', '!=', 'disabled') // ops kill-switch is never overridden
                    ->where('is_serviceable', false)
                    ->update(['is_serviceable' => true]);
            }
        } catch (\Throwable $e) {
            Log::warning('coverage-sync: activate served cities failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
        }
    }
}
