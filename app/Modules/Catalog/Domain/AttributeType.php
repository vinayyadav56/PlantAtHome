<?php

namespace App\Modules\Catalog\Domain;

/** The typed kinds an attribute definition can take (Section 4.3). */
final class AttributeType
{
    public const TEXT   = 'text';
    public const NUMBER = 'number';
    public const ENUM   = 'enum';
    public const BOOL   = 'bool';

    public const ALL = [self::TEXT, self::NUMBER, self::ENUM, self::BOOL];

    public const SCOPE_UNIVERSAL = 'universal';
    public const SCOPE_CATEGORY  = 'category';
    public const SCOPES = [self::SCOPE_UNIVERSAL, self::SCOPE_CATEGORY];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /** Coerce a raw value to the attribute's declared type for storage. */
    public static function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            self::NUMBER => is_numeric($value) ? $value + 0 : null,
            self::BOOL   => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default      => is_scalar($value) ? (string) $value : $value,
        };
    }
}
