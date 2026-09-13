<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;

/**
 * The ONE place floats become money. The accounting layer computes in integer paise
 * (the V2 Money value object) and persists DECIMAL(14,2) strings; legacy float columns
 * (orders.*) cross this boundary exactly once via the app-wide half-up 2dp rounding
 * (Marvel\Support\Money::round). Decimal strings from decimal columns are parsed
 * digit-wise so no float ever touches them.
 */
final class MoneyBridge
{
    public static function zero(): Money
    {
        return Money::zero('INR');
    }

    /** Accepts Money | decimal string | int | float | null → Money (paise). */
    public static function toMoney(mixed $v): Money
    {
        if ($v instanceof Money) {
            return $v;
        }
        if ($v === null || $v === '') {
            return self::zero();
        }
        if (is_string($v) && preg_match('/^\s*(-)?(\d+)(?:\.(\d+))?\s*$/', $v, $m)) {
            $sign = ($m[1] ?? '') === '-' ? -1 : 1;
            $frac = substr(str_pad($m[3] ?? '', 2, '0'), 0, 2);
            // a 3rd+ decimal digit on a decimal string is not expected; round half-up if present
            $extra = strlen($m[3] ?? '') > 2 ? (int) substr($m[3], 2, 1) : 0;
            $minor = ((int) $m[2]) * 100 + (int) $frac + ($extra >= 5 ? 1 : 0);
            return Money::fromMinor($sign * $minor, 'INR');
        }
        return Money::fromDecimal(\Marvel\Support\Money::round((float) $v), 'INR');
    }

    /** DECIMAL(14,2) string for persistence. */
    public static function decimal(Money $m): string
    {
        return $m->toDecimal();
    }

    /** @param iterable<mixed> $items */
    public static function sum(iterable $items): Money
    {
        $t = self::zero();
        foreach ($items as $i) {
            $t = $t->add(self::toMoney($i));
        }
        return $t;
    }

    /** Money × rate% (rate as 18 or "18.0000"), half-up to paise. */
    public static function percent(Money $base, float|string $ratePercent): Money
    {
        $minor = (int) round($base->amountMinor() * ((float) $ratePercent) / 100);
        return Money::fromMinor($minor, 'INR');
    }

    /**
     * Split $total across weights with the largest-remainder method so the parts sum to
     * the total EXACTLY (no paise drift — spec §54). Zero/empty weights → all to the first.
     * @param array<int|string, int|float|string> $weights
     * @return array<int|string, Money>
     */
    public static function allocate(Money $total, array $weights): array
    {
        if (!$weights) {
            return [];
        }
        $w = array_map(fn ($x) => max(0.0, (float) $x), $weights);
        $sumW = array_sum($w);
        $keys = array_keys($w);
        if ($sumW <= 0) {
            $out = array_fill_keys($keys, self::zero());
            $out[$keys[0]] = $total;
            return $out;
        }
        $minor = $total->amountMinor();
        $exact = [];
        $floor = [];
        $assigned = 0;
        foreach ($w as $k => $x) {
            $exact[$k] = $minor * $x / $sumW;
            $floor[$k] = (int) floor($exact[$k]);
            $assigned += $floor[$k];
        }
        $remainder = $minor - $assigned; // >= 0 for positive totals
        uksort($exact, fn ($a, $b) => ($exact[$b] - $floor[$b]) <=> ($exact[$a] - $floor[$a]));
        foreach (array_keys($exact) as $k) {
            if ($remainder === 0) {
                break;
            }
            $step = $remainder > 0 ? 1 : -1;
            $floor[$k] += $step;
            $remainder -= $step;
        }
        return array_map(fn ($p) => Money::fromMinor($p, 'INR'), $floor);
    }
}
