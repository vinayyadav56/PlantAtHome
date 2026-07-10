<?php

namespace App\Modules\Platform\Application\Subscribers;

use App\Shared\Events\IntegrationMessage;
use Psr\Log\LoggerInterface;

/**
 * Example subscriber: reacts to platform.health_pinged. It only logs, but it
 * demonstrates the consumer contract — a `handle(IntegrationMessage)` method.
 * Exactly-once processing per event is enforced by the OutboxRelay's durable
 * processed-events ledger, so subscribers don't dedupe themselves. Real
 * subscribers (reindex search, send notification, …) follow this same shape.
 */
final class RecordHealthPing
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
        $this->logger->info('[platform] health ping received', [
            'event_id' => $message->eventId,
            'source'   => $message->payload['source'] ?? null,
            'note'     => $message->payload['note'] ?? null,
        ]);
    }
}
