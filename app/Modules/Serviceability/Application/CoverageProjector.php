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
     * @return array{pincodes:int, by_source:array<string,int>, cities:int, unknown_pincodes:string[], duration_ms:int}
     */
    public function project(int $shopId): array
    {
        $startedAt = microtime(true);

        $rules = VendorCoverageRule::where('shop_id', $shopId)
            ->where('is_active', true)
            ->get(['rule_type', 'state_id', 'district_id', 'city_id', 'pincode'])
            ->map(fn (VendorCoverageRule $r) => $r->toArray())
            ->all();

        [$map, $unknown] = $this->computeMap($rules);

        // Full rewrite: the projection has no timestamps/identity worth keeping.
        $this->db->table('vendor_covered_pincodes')->where('shop_id', $shopId)->delete();
        $rows = [];
        foreach ($map as $pincode => $hit) {
            $rows[] = [
                'shop_id'     => $shopId,
                'pincode'     => (string) $pincode,
                'source'      => $hit['source'],
                'state_id'    => $hit['state_id'],
                'district_id' => $hit['district_id'],
                'city_id'     => $hit['city_id'],
            ];
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            $this->db->table('vendor_covered_pincodes')->insert($chunk);
        }

        $cityNames = $this->bridgeToLegacyServiceAreas($shopId, $map);

        $bySource = [];
        foreach ($map as $hit) {
            $bySource[$hit['source']] = ($bySource[$hit['source']] ?? 0) + 1;
        }

        return [
            'pincodes'         => count($map),
            'by_source'        => $bySource,
            'cities'           => count($cityNames),
            'unknown_pincodes' => $unknown,
            'duration_ms'      => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * The projection ladder, shared with previewCoverage (no writes). Rules
     * apply in ASCENDING priority — state, district, city, include — each tier
     * overwriting the source of pins it re-covers; exclude unsets pins last.
     * Include pins missing from the postal master are reported, never added.
     *
     * @param  array<int, array{rule_type:string, state_id?:int|null, district_id?:int|null, city_id?:int|null, pincode?:string|null}>  $rules
     * @return array{0: array<string, array{source:string, state_id:?int, district_id:?int, city_id:?int}>, 1: string[]}
     */
    public function computeMap(array $rules): array
    {
        $byType = ['state' => [], 'district' => [], 'city' => [], 'include' => [], 'exclude' => []];
        foreach ($rules as $rule) {
            match ($rule['rule_type'] ?? null) {
                VendorCoverageRule::TYPE_STATE           => $byType['state'][] = (int) $rule['state_id'],
                VendorCoverageRule::TYPE_DISTRICT        => $byType['district'][] = (int) $rule['district_id'],
                VendorCoverageRule::TYPE_CITY            => $byType['city'][] = (int) $rule['city_id'],
                VendorCoverageRule::TYPE_PINCODE_INCLUDE => $byType['include'][] = (string) $rule['pincode'],
                VendorCoverageRule::TYPE_PINCODE_EXCLUDE => $byType['exclude'][] = (string) $rule['pincode'],
                default                                  => null,
            };
        }

        $map = [];
        $collect = function ($query, string $source) use (&$map) {
            foreach ($query->get(['pincode', 'state_id', 'district_id', 'city_id']) as $row) {
                $map[(string) $row->pincode] = [
                    'source'      => $source,
                    'state_id'    => $row->state_id !== null ? (int) $row->state_id : null,
                    'district_id' => $row->district_id !== null ? (int) $row->district_id : null,
                    'city_id'     => $row->city_id !== null ? (int) $row->city_id : null,
                ];
            }
        };

        if ($byType['state'] !== []) {
            $collect($this->activePins()->whereIn('state_id', $byType['state']), 'state');
        }
        if ($byType['district'] !== []) {
            $collect($this->activePins()->whereIn('district_id', $byType['district']), 'district');
        }
        if ($byType['city'] !== []) {
            $collect($this->activePins()->whereIn('city_id', $byType['city']), 'city');
        }

        $unknown = [];
        if ($byType['include'] !== []) {
            $found = [];
            $q = $this->activePins()->whereIn('pincode', array_unique($byType['include']));
            foreach ($q->get(['pincode', 'state_id', 'district_id', 'city_id']) as $row) {
                $found[(string) $row->pincode] = true;
                $map[(string) $row->pincode] = [
                    'source'      => 'manual',
                    'state_id'    => $row->state_id !== null ? (int) $row->state_id : null,
                    'district_id' => $row->district_id !== null ? (int) $row->district_id : null,
                    'city_id'     => $row->city_id !== null ? (int) $row->city_id : null,
                ];
            }
            $unknown = array_values(array_unique(array_filter(
                $byType['include'],
                fn (string $pin) => ! isset($found[$pin]),
            )));
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
     * @param  array<string, array{city_id:?int}>  $map
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

        $cityIds = [];
        foreach ($map as $hit) {
            if ($hit['city_id'] !== null) {
                $cityIds[$hit['city_id']] = true;
            }
        }

        $cityNames = [];
        if ($cityIds !== [] && $schema->hasTable('cities')) {
            $cityNames = $this->db->table('cities')->whereIn('id', array_keys($cityIds))
                ->pluck('name')->unique()->values()->all();
        }

        $manual = [];
        $manualRows = $this->db->table('vendor_service_areas')
            ->where('shop_id', $shopId)->whereNull('source')->get(['city', 'fulfillment_mode', 'eta_days']);
        foreach ($manualRows as $row) {
            $manual[mb_strtolower(trim((string) $row->city))] = $row;
        }

        $now = now();
        foreach ($cityNames as $name) {
            $inherit = $manual[mb_strtolower(trim((string) $name))] ?? null;
            $values = [
                'fulfillment_mode' => $inherit->fulfillment_mode ?? 'both',
                'eta_days'         => $inherit->eta_days ?? null,
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
                app()->make(\Marvel\Services\AvailabilityService::class)->recomputeForShop($shopId);
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
                $lower = array_values(array_unique(array_map(fn ($n) => mb_strtolower(trim((string) $n)), $cityNames)));
                $this->db->table('cities')
                    ->whereIn($this->db->raw('LOWER(name)'), $lower)
                    ->where('status', '!=', 'disabled') // ops kill-switch is never overridden
                    ->where('is_serviceable', false)
                    ->update(['is_serviceable' => true]);
            }
        } catch (\Throwable $e) {
            Log::warning('coverage-sync: activate served cities failed', ['shop_id' => $shopId, 'error' => $e->getMessage()]);
        }
    }
}
