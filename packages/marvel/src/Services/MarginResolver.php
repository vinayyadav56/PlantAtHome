<?php

namespace Marvel\Services;

use Marvel\Database\Models\PricingMargin;
use Marvel\Database\Models\Settings;

/**
 * Resolves the PlantAtHome selling margin for a (city, vertical) pair. Selling price
 * everywhere is MAX(vendor rate) × (1 + marginPercent(city, type)/100).
 *
 * Precedence (most specific wins):
 *   1. city + vertical        2. city (all verticals)
 *   3. vertical (all cities)  4. global default (both NULL)
 * Fallback when no row matches: legacy settings.options.vendorPricing.globalMarginPercent, else 0.
 *
 * The map is loaded ONCE per request into a static (services are new'd ad hoc across
 * controllers/repositories, so an instance cache would reload repeatedly). Plain FPM —
 * the static dies with the request. flush() after any margin mutation.
 */
class MarginResolver
{
    /** @var array<string,float>|null "city|type" => percent; '*' = NULL side. */
    private static ?array $map = null;

    public function marginPercent(?string $cityKey, ?int $typeId): float
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
            return (float) ($vp['globalMarginPercent'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
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
                self::$map[$city . '|' . $type] = (float) $m->margin_percent;
            }
        } catch (\Throwable $e) {
            // Table not migrated yet / DB hiccup → empty map; marginPercent falls back
            // to the legacy settings margin so pricing never throws.
        }
    }

    /** Forget the request-cache (call after any pricing_margins mutation). */
    public static function flush(): void
    {
        self::$map = null;
    }
}
