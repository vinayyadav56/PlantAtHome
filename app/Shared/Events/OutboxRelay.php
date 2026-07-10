<?php

namespace App\Shared\Events;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the transactional outbox: claims a batch of pending rows, delivers each
 * to its subscribers (the in-process bus today; a network bus later), and marks
 * the row published — or, on failure, records the error and retries up to
 * MAX_ATTEMPTS before parking the row as failed.
 *
 * Delivery is per-subscriber isolated and idempotent: each subscriber runs in
 * its own savepoint and its success is recorded in the processed_events ledger,
 * so (a) one failing subscriber never blocks its siblings, and (b) a retry (or a
 * second worker, or a restart) never re-runs a subscriber that already
 * succeeded. A row is published only once every subscriber has succeeded.
 *
 * Each row is claimed FOR UPDATE SKIP LOCKED so multiple relay workers run in
 * parallel without convoying or double-processing.
 */
final class OutboxRelay
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SubscriberRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Deliver up to $limit pending events. Returns the number of rows fully
     * published this pass. A failure on one row never aborts the batch.
     */
    public function relay(int $limit = 100): int
    {
        $published = 0;

        $rows = $this->db->table('outbox')
            ->where('status', OutboxStatus::PENDING)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                if ($this->deliver($row)) {
                    $published++;
                }
            } catch (Throwable $e) {
                // Per-row isolation: a claim/DB error on one row must not abort
                // the whole pass. The row stays pending and is retried next time.
                $this->logger->error('[outbox] row delivery aborted', [
                    'outbox_id' => $row->id ?? null,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $published;
    }

    private function deliver(object $row): bool
    {
        return (bool) $this->db->transaction(function () use ($row) {
            $claimed = $this->claim($row->id);
            if (! $claimed) {
                return false; // another worker holds/handled it
            }

            $message = IntegrationMessage::fromOutboxRow($claimed);
            $failures = [];

            foreach ($this->registry->subscribersFor($message->eventName) as $subscriberClass) {
                if ($this->alreadyProcessed($message->eventId, $subscriberClass)) {
                    continue; // idempotent: this subscriber already ran for this event
                }

                try {
                    // Own savepoint: the subscriber's side effect and the ledger
                    // row commit together, or roll back together on failure.
                    $this->db->transaction(function () use ($subscriberClass, $message) {
                        $this->registry->invoke($subscriberClass, $message);
                        $this->recordProcessed($message->eventId, $subscriberClass);
                    });
                } catch (Throwable $e) {
                    $failures[$subscriberClass] = $e->getMessage();
                    $this->logger->error('[outbox] subscriber failed', [
                        'event_id'   => $message->eventId,
                        'event_name' => $message->eventName,
                        'subscriber' => $subscriberClass,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $attempts = (int) $claimed->attempts + 1;

            if ($failures === []) {
                $this->db->table('outbox')->where('id', $claimed->id)->update([
                    'status'       => OutboxStatus::PUBLISHED,
                    'attempts'     => $attempts,
                    'published_at' => Carbon::now(),
                    'last_error'   => null,
                    'updated_at'   => Carbon::now(),
                ]);

                return true;
            }

            // Some subscriber(s) failed — succeeded ones are recorded and won't
            // re-run; the row stays pending (or is parked as failed if exhausted).
            $this->db->table('outbox')->where('id', $claimed->id)->update([
                'status'     => $attempts >= self::MAX_ATTEMPTS ? OutboxStatus::FAILED : OutboxStatus::PENDING,
                'attempts'   => $attempts,
                'last_error' => mb_substr($this->summarize($failures), 0, 1000),
                'updated_at' => Carbon::now(),
            ]);

            return false;
        });
    }

    /** Claim a pending row for this worker, skipping rows another worker holds. */
    private function claim(int $id): ?object
    {
        $query = $this->db->table('outbox')
            ->where('id', $id)
            ->where('status', OutboxStatus::PENDING);

        // SKIP LOCKED (MySQL 8+) lets a second worker skip a row this worker is
        // handling instead of convoying behind it. Other drivers (sqlite tests)
        // ignore the lock clause harmlessly.
        if ($this->db->getDriverName() === 'mysql') {
            $query->lock('for update skip locked');
        } else {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function alreadyProcessed(string $eventId, string $subscriber): bool
    {
        return $this->db->table('processed_events')
            ->where('event_id', $eventId)
            ->where('subscriber', $subscriber)
            ->exists();
    }

    private function recordProcessed(string $eventId, string $subscriber): void
    {
        $this->db->table('processed_events')->insertOrIgnore([
            'event_id'     => $eventId,
            'subscriber'   => $subscriber,
            'processed_at' => Carbon::now(),
        ]);
    }

    /** @param array<string,string> $failures subscriber => error */
    private function summarize(array $failures): string
    {
        $parts = [];
        foreach ($failures as $subscriber => $error) {
            $parts[] = class_basename($subscriber).': '.$error;
        }

        return implode(' | ', $parts);
    }
}
