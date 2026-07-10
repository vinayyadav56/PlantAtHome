<?php

namespace App\Modules\Catalog\Domain;

/** Lifecycle of a product in the master catalog. */
final class ProductStatus
{
    public const DRAFT     = 'draft';      // being authored, not shown to customers
    public const PUBLISHED = 'published';  // live in the catalog
    public const ARCHIVED  = 'archived';   // retired, kept for history

    public const ALL = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
