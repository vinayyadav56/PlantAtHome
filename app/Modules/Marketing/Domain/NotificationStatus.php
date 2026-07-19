<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain;

/**
 * Lifecycle of a single materialized notification. queued → processing → sent
 * are driven by the send job; delivered/read arrive later via provider webhooks;
 * failed/retrying are the retry path; cancelled is an operator stop.
 */
final class NotificationStatus
{
    public const QUEUED     = 'queued';
    public const PROCESSING = 'processing';
    public const SENT       = 'sent';
    public const DELIVERED  = 'delivered';
    public const READ       = 'read';
    public const FAILED     = 'failed';
    public const RETRYING   = 'retrying';
    public const CANCELLED  = 'cancelled';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::QUEUED, self::PROCESSING, self::SENT, self::DELIVERED,
            self::READ, self::FAILED, self::RETRYING, self::CANCELLED,
        ];
    }

    /** States a send job is allowed to pick up and (re)send. */
    public static function sendable(): array
    {
        return [self::QUEUED, self::RETRYING];
    }

    /** Ranking so an out-of-order webhook can't downgrade a further-along status. */
    public static function rank(string $status): int
    {
        return [
            self::CANCELLED => 0,
            self::FAILED    => 1,
            self::QUEUED    => 2,
            self::RETRYING  => 2,
            self::PROCESSING => 3,
            self::SENT      => 4,
            self::DELIVERED => 5,
            self::READ      => 6,
        ][$status] ?? 0;
    }
}
