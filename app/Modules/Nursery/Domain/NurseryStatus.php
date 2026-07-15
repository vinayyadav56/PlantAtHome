<?php

namespace App\Modules\Nursery\Domain;

/** Lifecycle states of a nursery (vendor). Only `active` sells. */
final class NurseryStatus
{
    public const PENDING   = 'pending';
    public const ACTIVE    = 'active';
    public const SUSPENDED = 'suspended';
    public const REJECTED  = 'rejected';

    public const ALL = [self::PENDING, self::ACTIVE, self::SUSPENDED, self::REJECTED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
