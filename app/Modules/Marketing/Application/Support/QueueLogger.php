<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Support;

use App\Modules\Marketing\Infrastructure\Models\QueueLog;
use Illuminate\Support\Carbon;

/**
 * Thin writer for the marketing_queue_logs audit trail. Records every job
 * lifecycle event (dispatched/processing/completed/failed/retrying) so operators
 * can see exactly what the queue did without reading the framework's jobs table.
 * Best-effort: a logging failure must never break a send.
 */
final class QueueLogger
{
    /** @param array<string,mixed> $context */
    public static function record(
        string $jobClass,
        string $event,
        array $context = [],
        ?int $runId = null,
        ?int $campaignId = null,
        ?int $batchId = null,
        string $queue = null,
        int $attempt = 1,
        ?string $message = null,
    ): void {
        try {
            QueueLog::create([
                'run_id'      => $runId,
                'campaign_id' => $campaignId,
                'batch_id'    => $batchId,
                'job_class'   => class_basename($jobClass),
                'queue'       => $queue ?? (string) config('marketing.queue', 'marketing'),
                'event'       => $event,
                'attempt'     => $attempt,
                'message'     => $message ? mb_substr($message, 0, 1000) : null,
                'context'     => $context ?: null,
                'occurred_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // audit only — never propagate
        }
    }
}
