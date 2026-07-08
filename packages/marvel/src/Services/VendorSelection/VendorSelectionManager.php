<?php

namespace Marvel\Services\VendorSelection;

use Marvel\Database\Models\Settings;

/**
 * Resolves the active vendor-selection strategy from
 * settings.options.assignment.strategy (env MARKETPLACE_VENDOR_STRATEGY overrides).
 * Default 'cheapest' preserves the pre-existing behaviour exactly. Register new
 * strategies here — the assignment pipeline never needs to change.
 */
class VendorSelectionManager
{
    /** @var array<string, class-string<VendorSelectionStrategy>> */
    private const STRATEGIES = [
        'cheapest'    => CheapestRateStrategy::class,
        'nearest'     => NearestVendorStrategy::class,
        'priority'    => PriorityVendorStrategy::class,
        'rating'      => HighestRatingStrategy::class,
        'round_robin' => RoundRobinStrategy::class,
    ];

    public static function make(?string $key = null): VendorSelectionStrategy
    {
        $key = $key ?: self::configuredKey();
        $class = self::STRATEGIES[$key] ?? CheapestRateStrategy::class;
        return new $class();
    }

    public static function configuredKey(): string
    {
        $env = env('MARKETPLACE_VENDOR_STRATEGY');
        if (is_string($env) && $env !== '' && isset(self::STRATEGIES[$env])) {
            return $env;
        }
        try {
            $key = Settings::getData()->options['assignment']['strategy'] ?? null;
        } catch (\Throwable $e) {
            $key = null;
        }
        return (is_string($key) && isset(self::STRATEGIES[$key])) ? $key : 'cheapest';
    }

    /** @return string[] available strategy keys (for admin settings UI). */
    public static function available(): array
    {
        return array_keys(self::STRATEGIES);
    }
}
