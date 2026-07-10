<?php

namespace App\Modules\Platform\Application\Subscribers;

use App\Shared\Events\IntegrationMessage;
use Psr\Log\LoggerInterface;

/**
 * Example subscriber: reacts to platform.health_pinged. It only logs, but it
 * demonstrates the consumer contract — a `handle(IntegrationMessage)` method,
 * kept idempotent by deduping on the message's eventId (delivery is
 * at-least-once). Real subscribers (reindex search, send notification, …)
 * follow this same shape.
 */
final class RecordHealthPing
{
    /** @var array<string, true> in-process dedupe of already-seen event ids */
    private static array $seen = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
        if (isset(self::$seen[$message->eventId])) {
            return; // idempotent: already processed this event
        }
        self::$seen[$message->eventId] = true;

        $this->logger->info('[platform] health ping received', [
            'event_id' => $message->eventId,
            'source'   => $message->payload['source'] ?? null,
            'note'     => $message->payload['note'] ?? null,
        ]);
    }
}
