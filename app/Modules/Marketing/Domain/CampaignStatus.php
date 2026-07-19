<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain;

final class CampaignStatus
{
    public const DRAFT     = 'draft';
    public const SCHEDULED = 'scheduled';
    public const RUNNING   = 'running';
    public const PAUSED    = 'paused';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    /** @return string[] */
    public static function all(): array
    {
        return [self::DRAFT, self::SCHEDULED, self::RUNNING, self::PAUSED, self::COMPLETED, self::CANCELLED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
