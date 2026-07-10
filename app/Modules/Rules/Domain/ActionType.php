<?php

namespace App\Modules\Rules\Domain;

/** The action types a rule may emit (Section 6), grouped loosely by scope. */
final class ActionType
{
    // visibility / compatibility
    public const HIDE_OPTION      = 'hide_option';
    public const SHOW_OPTION      = 'show_option';
    public const REQUIRE_OPTION   = 'require_option';
    public const BLOCK_SELECTION  = 'block_selection';
    // pricing / promotion
    public const APPLY_DISCOUNT   = 'apply_discount';
    public const SET_FREE         = 'set_free';
    public const ADD_SURCHARGE    = 'add_surcharge';
    // shipping / serviceability
    public const RESTRICT_SHIPPING = 'restrict_shipping';
    public const RESTRICT_CITY     = 'restrict_city';

    public const ALL = [
        self::HIDE_OPTION, self::SHOW_OPTION, self::REQUIRE_OPTION, self::BLOCK_SELECTION,
        self::APPLY_DISCOUNT, self::SET_FREE, self::ADD_SURCHARGE,
        self::RESTRICT_SHIPPING, self::RESTRICT_CITY,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
