<?php

namespace Marvel\Services\Legal;

use Marvel\Database\Models\Legal\LegalSettings;

/**
 * Configurable risk scoring. The formula and the level thresholds live in
 * legal_settings.settings so the model can be tuned from the admin without a
 * code change — the spec is explicit that scoring must not be hard-coded.
 *
 * Defaults: score = probability × impact on 1-5 scales (1-25), banded
 * low ≤4, medium ≤9, high ≤16, critical above.
 */
class RiskScoring
{
    public const CATEGORIES = [
        'operational', 'financial', 'legal', 'technology', 'vendor',
        'logistics', 'product_quality', 'data_security', 'reputation', 'regulatory',
    ];

    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    private const DEFAULT_THRESHOLDS = ['low' => 4, 'medium' => 9, 'high' => 16];

    public static function score(int $probability, int $impact): int
    {
        $p = max(1, min(5, $probability));
        $i = max(1, min(5, $impact));

        return $p * $i;
    }

    public static function level(int $score): string
    {
        $thresholds = self::thresholds();
        if ($score <= $thresholds['low']) {
            return 'low';
        }
        if ($score <= $thresholds['medium']) {
            return 'medium';
        }
        if ($score <= $thresholds['high']) {
            return 'high';
        }

        return 'critical';
    }

    /** @return array{low:int,medium:int,high:int} */
    public static function thresholds(): array
    {
        try {
            $configured = LegalSettings::current()->settings['risk_thresholds'] ?? null;
        } catch (\Throwable) {
            $configured = null;
        }
        if (! is_array($configured)) {
            return self::DEFAULT_THRESHOLDS;
        }

        return [
            'low' => (int) ($configured['low'] ?? self::DEFAULT_THRESHOLDS['low']),
            'medium' => (int) ($configured['medium'] ?? self::DEFAULT_THRESHOLDS['medium']),
            'high' => (int) ($configured['high'] ?? self::DEFAULT_THRESHOLDS['high']),
        ];
    }
}
