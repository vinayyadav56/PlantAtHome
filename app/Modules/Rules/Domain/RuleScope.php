<?php

namespace App\Modules\Rules\Domain;

/** The domain a rule governs (Section 6). Scope selects which rules run when. */
final class RuleScope
{
    public const COMPATIBILITY  = 'compatibility';
    public const VISIBILITY     = 'visibility';
    public const PRICING        = 'pricing';
    public const INVENTORY      = 'inventory';
    public const SHIPPING       = 'shipping';
    public const TAX            = 'tax';
    public const PROMOTION      = 'promotion';
    public const SERVICEABILITY = 'serviceability';
    public const VENDOR         = 'vendor';

    public const ALL = [
        self::COMPATIBILITY, self::VISIBILITY, self::PRICING, self::INVENTORY,
        self::SHIPPING, self::TAX, self::PROMOTION, self::SERVICEABILITY, self::VENDOR,
    ];

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::ALL, true);
    }
}
