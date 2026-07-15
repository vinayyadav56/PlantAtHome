<?php

namespace App\Modules\Nursery\Domain;

/**
 * Withdrawal states — the EXACT legacy `withdraws.status` strings, so the
 * backfill passes them through untouched. approved/rejected are terminal.
 */
final class WithdrawalStatus
{
    public const PENDING    = 'pending';
    public const PROCESSING = 'processing';
    public const APPROVED   = 'approved';
    public const ON_HOLD    = 'on_hold';
    public const REJECTED   = 'rejected';

    public const ALL = [self::PENDING, self::PROCESSING, self::APPROVED, self::ON_HOLD, self::REJECTED];

    public const TERMINAL = [self::APPROVED, self::REJECTED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
