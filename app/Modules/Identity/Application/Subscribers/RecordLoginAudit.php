<?php

namespace App\Modules\Identity\Application\Subscribers;

use App\Shared\Events\IntegrationMessage;
use Psr\Log\LoggerInterface;

/**
 * Consumes identity.user_logged_in off the outbox — a light audit trail that
 * runs outside the login request path. Exactly-once processing per event is
 * guaranteed by the OutboxRelay's durable processed-events ledger, so this
 * subscriber does no deduping itself; it just records the ping.
 */
final class RecordLoginAudit
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
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
