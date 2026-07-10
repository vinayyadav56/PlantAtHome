<?php

namespace App\Modules\Configuration\Domain;

/** How many options a customer may pick from a configuration group. */
final class SelectType
{
    public const SINGLE = 'single'; // exactly one (if required) / at most one
    public const MULTI  = 'multi';  // any number of compatible options

    public const ALL = [self::SINGLE, self::MULTI];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
