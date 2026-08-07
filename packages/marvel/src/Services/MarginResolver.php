<?php

namespace Marvel\Services;

use Marvel\Database\Models\PricingMargin;
use Marvel\Database\Models\Settings;

/**
 * Resolves the PlantAtHome selling margin for a (city, vertical) pair and
 * applies it. Selling price everywhere is:
 *
 *   apply(MAX(vendor rate)) = rate × (1 + percent/100)   for percent rules
 *                             rate + flat                 for flat-₹ rules
 *
 * THE FORMULA LIVES HERE AND ONLY HERE — PricingService, AvailabilityService
 * and ItemAssignmentService all call apply(), so a rule-type change can never
 * make the card, the projection and the order snapshot disagree.
 *
 * Precedence (most specific wins):
 *   1. city + vertical        2. city (all verticals)
 *   3. vertical (all cities)  4. global default (both NULL)
 * Fallback when no row matches: legacy settings.options.vendorPricing.globalMarginPercent, else 0.
 *
 * The map is loaded ONCE per request into a static (services are new'd ad hoc across
 * controllers/repositories, so an instance cache would reload repeatedly). Under FPM
 * the static dies with the request, so flush() after any margin mutation is enough.
 *
 * ⚠️ In a LONG-RUNNING process the static does NOT die: a queue worker lives for an
 * hour and would reuse the margins it happened to load on its first job, so a margin
 * edit would silently never reach the projection. Every recompute entry point that
 * can run in a worker therefore flushes first — see RecomputeShopAvailabilityJob and
 * RecomputeCityAvailabilityCommand. Add the same call to any new long-lived caller.
 */
class MarginResolver
{
    /** @var array<string,array{type:string,percent:float,flat:float}>|null "city|type" => rule; '*' = NULL side. */
    private static ?array $map = null;

    /**
     * Apply the resolved margin rule to a vendor rate. This is the ONE pricing
     * formula — do not reintroduce `* (1 + margin/100)` at call sites.
     */
    public function apply(float $maxRate, ?string $cityKey, ?int $typeId): float
    {
        $rule = $this->rule($cityKey, $typeId);
        if ($rule['type'] === 'flat') {
            return round($maxRate + $rule['flat'], 2);
        }
        return round($maxRate * (1 + $rule['percent'] / 100), 2);
    }

    /**
     * The margin as an EFFECTIVE percent of the given rate — what order
     * snapshots and finance reports store. For percent rules this is the rule
     * itself; for flat rules it is the flat amount expressed against the rate,
     * so downstream margin_percent columns keep working with no schema change.
     */
    public function effectivePercent(float $maxRate, ?string $cityKey, ?int $typeId): float
    {
        $rule = $this->rule($cityKey, $typeId);
        if ($rule['type'] === 'flat') {
            return $maxRate > 0 ? round(($rule['flat'] / $maxRate) * 100, 4) : 0.0;
        }
        return $rule['percent'];
    }

    /** Back-compat: the percent for display paths. Flat rules report 0 here — use apply(). */
    public function marginPercent(?string $cityKey, ?int $typeId): float
    {
        return $this->rule($cityKey, $typeId)['percent'];
    }

    /** @return array{type:string,percent:float,flat:float} */
    private function rule(?string $cityKey, ?int $typeId): array
    {
        $this->load();
        $city = ($cityKey !== null && $cityKey !== '') ? $cityKey : '*';
        $type = $typeId ? (string) $typeId : '*';
        foreach ([
            $city . '|' . $type, // city + vertical
            $city . '|*',        // city
            '*|' . $type,        // vertical
            '*|*',               // global default
        ] as $key) {
            if (isset(self::$map[$key])) {
                return self::$map[$key];
            }
        }
        try {
            $vp = (array) ((Settings::getData()->options['vendorPricing'] ?? []) ?: []);
            return ['type' => 'percent', 'percent' => (float) ($vp['globalMarginPercent'] ?? 0), 'flat' => 0.0];
        } catch (\Throwable $e) {
            return ['type' => 'percent', 'percent' => 0.0, 'flat' => 0.0];
        }
    }

    private function load(): void
    {
        if (self::$map !== null) {
            return;
        }
        self::$map = [];
        try {
            foreach (PricingMargin::where('is_active', true)->get() as $m) {
                $city = ($m->city !== null && $m->city !== '') ? strtolower(trim($m->city)) : '*';
                $type = $m->type_id ? (string) $m->type_id : '*';
                // margin_type may predate the flat-margin migration → percent.
                $flat = ($m->margin_type ?? 'percent') === 'flat';
                self::$map[$city . '|' . $type] = [
                    'type'    => $flat ? 'flat' : 'percent',
                    'percent' => $flat ? 0.0 : (float) $m->margin_percent,
                    'flat'    => $flat ? (float) ($m->margin_flat ?? 0) : 0.0,
                ];
            }
        } catch (\Throwable $e) {
            // Table not migrated yet / DB hiccup → empty map; rule() falls back
            // to the legacy settings margin so pricing never throws.
        }
    }

    /** Forget the request-cache (call after any pricing_margins mutation). */
    public static function flush(): void
    {
        self::$map = null;
    }
}
