<?php

namespace Marvel\Services\DeliveryOptimizer\Support;

/**
 * Weight / volumetric bucketing — the cache-hit-rate lever.
 *
 * A live carrier quote is (approximately) a function of
 * (origin_pin, dest_pin, weight, dims, rail). Raw grams give a near-unique key per
 * cart and a ~0% cache hit rate. We quantise weight to carrier slabs / 250g steps so
 * that "501g" and "740g" collide on the same cached quote. The collisions are
 * INTENTIONAL — a tiny rate imprecision bought for an order-of-magnitude hit-rate gain.
 * Firm quotes at checkout correct any drift.
 *
 * Pure functions only (no I/O) so cache keys are deterministic and unit-testable.
 */
final class Buckets
{
    public const WEIGHT_STEP_G = 250;

    /** Carrier weight slabs (grams). Anything at/under a slab snaps up to it. */
    private const SLABS_G = [500, 1000, 2000, 5000, 10000, 20000];

    /**
     * Round actual grams up to the next carrier slab; beyond the largest slab, round
     * up to the next 250g step. Always >= 1 slab so an empty/zero weight still keys.
     */
    public static function weightBucket(int $grams): int
    {
        $g = max(1, $grams);
        foreach (self::SLABS_G as $slab) {
            if ($g <= $slab) {
                return $slab;
            }
        }
        return (int) (ceil($g / self::WEIGHT_STEP_G) * self::WEIGHT_STEP_G);
    }

    /**
     * Volumetric weight (grams) from L*B*H (cm) using the common /5000 divisor,
     * then bucketed like real weight. Returns 0 when any dimension is missing so the
     * caller falls back to actual weight only.
     */
    public static function dimsBucket(?float $lengthCm = null, ?float $breadthCm = null, ?float $heightCm = null): int
    {
        if (!$lengthCm || !$breadthCm || !$heightCm) {
            return 0;
        }
        $volKg = ($lengthCm * $breadthCm * $heightCm) / 5000.0;
        return self::weightBucket((int) ceil($volKg * 1000));
    }

    /** Chargeable weight bucket = bucket(max(actual, volumetric)). */
    public static function chargeableWeightBucket(int $actualG, int $volumetricG = 0): int
    {
        return self::weightBucket(max($actualG, $volumetricG));
    }
}
