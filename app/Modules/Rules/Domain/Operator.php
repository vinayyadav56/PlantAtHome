<?php

namespace App\Modules\Rules\Domain;

/**
 * The comparison operators a condition may use (Section 6). `contains` /
 * `not_contains` treat the fact as a set (array) OR a string (substring);
 * `in` / `not_in` treat the VALUE as the set.
 */
final class Operator
{
    public const EQ           = 'eq';
    public const NEQ          = 'neq';
    public const IN           = 'in';
    public const NOT_IN       = 'not_in';
    public const GT           = 'gt';
    public const GTE          = 'gte';
    public const LT           = 'lt';
    public const LTE          = 'lte';
    public const EXISTS        = 'exists';
    public const CONTAINS      = 'contains';
    public const NOT_CONTAINS  = 'not_contains';

    public const ALL = [
        self::EQ, self::NEQ, self::IN, self::NOT_IN, self::GT, self::GTE,
        self::LT, self::LTE, self::EXISTS, self::CONTAINS, self::NOT_CONTAINS,
    ];

    public static function isValid(string $operator): bool
    {
        return in_array($operator, self::ALL, true);
    }
}
