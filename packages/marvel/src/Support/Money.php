<?php

namespace Marvel\Support;

/**
 * The one money-rounding helper for the tax engine.
 *
 * The app stores money as floats and rounds with round(x, 2) at persistence
 * points; this centralises that so every tax figure rounds the SAME way
 * (half-up, to the currency's fraction digits) and line-item taxes reconcile
 * against order totals within one paisa. No new money type — just consistency.
 */
final class Money
{
    /** Currency fraction digits. INR = 2. */
    public const SCALE = 2;

    /** Round a money amount half-up to the currency scale. */
    public static function round(float $amount, int $scale = self::SCALE): float
    {
        return round($amount, $scale, PHP_ROUND_HALF_UP);
    }

    /**
     * Back-calculate the taxable (net) value out of a TAX-INCLUSIVE price:
     * taxable = price * 100 / (100 + rate). A 0% rate returns the price unchanged.
     */
    public static function taxableFromInclusive(float $inclusive, float $rate): float
    {
        if ($rate <= 0) {
            return self::round($inclusive);
        }
        return self::round($inclusive * 100 / (100 + $rate));
    }

    /** Tax on a TAX-EXCLUSIVE (net) value: net * rate / 100. */
    public static function taxFromExclusive(float $net, float $rate): float
    {
        if ($rate <= 0) {
            return 0.0;
        }
        return self::round($net * $rate / 100);
    }
}
