<?php

namespace App\Shared\Events;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the transactional outbox: claims a batch of pending rows, delivers
 * each to its subscribers (the in-process bus today; a network bus later), and
 * marks the row published — or, on failure, records the error and retries up to
 * MAX_ATTEMPTS before parking the row as failed. Delivery is at-least-once, so
 * subscribers must be idempotent (dedupe on event_id).
 *
 * Each row is claimed inside a short transaction with a row lock so multiple
 * relay workers never process the same event twice.
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
     * Deliver up to $limit pending events. Returns the number successfully
     * published this pass.
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
            if ($this->deliver($row)) {
                $published++;
            }
        }

        return $published;
    }

    private function deliver(object $row): bool
    {
        // Claim the row: re-read FOR UPDATE and skip if another worker already
        // moved it out of pending. lockForUpdate is a no-op on sqlite (tests)
        // but real on MySQL.
        return (bool) $this->db->transaction(function () use ($row) {
            $claimed = $this->db->table('outbox')
                ->where('id', $row->id)
                ->where('status', OutboxStatus::PENDING)
                ->lockForUpdate()
                ->first();

            if (! $claimed) {
                return false; // already handled by another worker
            }

            try {
                $this->registry->dispatch(IntegrationMessage::fromOutboxRow($claimed));

                $this->db->table('outbox')->where('id', $claimed->id)->update([
                    'status'       => OutboxStatus::PUBLISHED,
                    'attempts'     => $claimed->attempts + 1,
                    'published_at' => Carbon::now(),
                    'last_error'   => null,
                    'updated_at'   => Carbon::now(),
                ]);

                return true;
            } catch (Throwable $e) {
                $attempts = $claimed->attempts + 1;
                $this->logger->error('[outbox] delivery failed', [
                    'event_id'   => $claimed->event_id,
                    'event_name' => $claimed->event_name,
                    'attempts'   => $attempts,
                    'error'      => $e->getMessage(),
                ]);

                $this->db->table('outbox')->where('id', $claimed->id)->update([
                    // exhausted → park as failed (needs manual/ops intervention);
                    // otherwise leave pending for the next pass.
                    'status'     => $attempts >= self::MAX_ATTEMPTS ? OutboxStatus::FAILED : OutboxStatus::PENDING,
                    'attempts'   => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'updated_at' => Carbon::now(),
                ]);

                return false;
            }
        });
    }
}
