<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain;

final class RunStatus
{
    public const PENDING      = 'pending';
    public const MATERIALIZING = 'materializing';
    public const QUEUED       = 'queued';
    public const PROCESSING   = 'processing';
    public const COMPLETED    = 'completed';
    public const FAILED       = 'failed';
    public const CANCELLED    = 'cancelled';

    /** @return string[] terminal states — a run here does no more work */
    public static function terminal(): array
    {
        return [self::COMPLETED, self::FAILED, self::CANCELLED];
    }
}
