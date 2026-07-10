<?php

namespace App\Shared\Events;

/**
 * Lifecycle of an outbox row. Enum-as-lookup (string) so the column stays
 * human-readable and queryable (Section 4.1: no free-text statuses).
 */
final class OutboxStatus
{
    public const PENDING = 'pending';
    public const PUBLISHED = 'published';
    public const FAILED = 'failed';
}
