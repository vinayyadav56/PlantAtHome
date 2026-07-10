<?php

namespace App\Modules\Identity\Application\Subscribers;

use App\Shared\Events\IntegrationMessage;
use Psr\Log\LoggerInterface;

/**
 * Consumes identity.user_logged_in off the outbox — a light audit trail that
 * runs outside the login request path. Idempotent (dedupes on event id), so the
 * at-least-once relay can re-deliver safely.
 */
final class RecordLoginAudit
{
    /** @var array<string,true> */
    private static array $seen = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
        if (isset(self::$seen[$message->eventId])) {
            return;
        }
        self::$seen[$message->eventId] = true;

        $this->logger->info('[identity] login', [
            'event_id'   => $message->eventId,
            'user_uuid'  => $message->payload['user_uuid'] ?? null,
            'role'       => $message->payload['role'] ?? null,
            'nursery_id' => $message->payload['nursery_id'] ?? null,
            'ip'         => $message->payload['ip'] ?? null,
            'at'         => $message->occurredAt,
        ]);
    }
}
