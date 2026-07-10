<?php

namespace App\Modules\Inventory\Domain;

/** Inventory is polymorphic over these SKU kinds — never merged (Section 4.5). */
final class SellableType
{
    public const VARIANT = 'variant';  // a product_variant SKU
    public const OPTION  = 'option';   // a config_option SKU

    public const ALL = [self::VARIANT, self::OPTION];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
