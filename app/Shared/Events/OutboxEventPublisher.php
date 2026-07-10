<?php

namespace App\Shared\Events;

use App\Shared\Domain\DomainEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Transactional-outbox publisher: inserts each event as a row in the `outbox`
 * table using the app DB connection, so it enlists in whatever transaction the
 * caller has open. If the surrounding business transaction rolls back, the
 * outbox rows roll back with it — the outbox and the state change are atomic.
 * The OutboxRelay drains pending rows and delivers them to subscribers.
 */
final class OutboxEventPublisher implements EventPublisher
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        if ($events === []) {
            return;
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'event_id'    => $event->eventId(),
                'event_name'  => $event->eventName(),
                'version'     => $event->version(),
                'payload'     => json_encode($event->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'      => OutboxStatus::PENDING,
                'attempts'    => 0,
                'occurred_at' => $event->occurredAt(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        // insertOrIgnore → publishing the same event_id twice (e.g. a retried
        // request) is a no-op, keeping the outbox idempotent at the producer.
        $this->db->table('outbox')->insertOrIgnore($rows);
    }
}
