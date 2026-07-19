<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Jobs;

use App\Modules\Marketing\Application\Channels\ChannelManager;
use App\Modules\Marketing\Application\DeliveryService;
use App\Modules\Marketing\Application\Support\QueueLogger;
use App\Modules\Marketing\Domain\NotificationStatus;
use App\Modules\Marketing\Domain\RunStatus;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignJob;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Sends one batch of notifications through the ChannelManager. Shared base for
 * the per-channel Send*Jobs (the spec's SendEmailJob / SendSmsJob /
 * SendWhatsappJob) — the concrete class only names the channel; the notification
 * itself carries which channel to route to.
 *
 * Idempotent by construction: it only picks notifications still in a sendable
 * state (queued/retrying), and a notification is left sendable until its outcome
 * is recorded — so a timeout/crash mid-batch safely resumes the unsent tail
 * without re-sending anyone. Failed sends flip to retrying (under max_retries)
 * and the batch re-dispatches itself with backoff until they succeed or exhaust.
 */
abstract class AbstractSendBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Infra-level retries for an unexpected whole-batch failure. */
    public int $tries = 3;
    public int $timeout = 280; // < the worker's retry_after (960s)

    public function __construct(public readonly int $batchId)
    {
    }

    /** @return int[] per-attempt backoff seconds */
    public function backoff(): array
    {
        return [10, 30, 120];
    }

    public function handle(ChannelManager $channels, DeliveryService $delivery): void
    {
        $batch = CampaignJob::find($this->batchId);
        if (! $batch || $batch->status === 'completed') {
            return; // already drained
        }

        $campaign = Campaign::find($batch->campaign_id);
        $maxRetries = $campaign ? (int) $campaign->max_retries : (int) config('marketing.campaign.default_max_retries', 3);
        $throttlePerMin = $campaign ? (int) ($campaign->throttle_per_minute ?? 0) : 0;
        $sleepMicros = $throttlePerMin > 0 ? (int) floor(60_000_000 / $throttlePerMin) : 0;

        $attempt = (int) $batch->attempt + 1;
        $batch->update(['status' => 'processing', 'started_at' => $batch->started_at ?? Carbon::now(), 'attempt' => $attempt]);
        $this->markRunProcessing($batch->run_id);
        QueueLogger::record(static::class, 'processing', ['batch' => $batch->batch_number], $batch->run_id, $batch->campaign_id, $batch->id, attempt: $attempt);

        // Recover any rows a previous (timed-out/crashed) attempt of THIS batch
        // left mid-send in PROCESSING — put them back in play. Safe because a
        // single batch is only ever processed by one job at a time.
        MarketingNotification::where('batch_id', $batch->id)
            ->where('status', NotificationStatus::PROCESSING)
            ->update(['status' => NotificationStatus::QUEUED]);

        // Cap the work this invocation does so a throttled large batch cannot
        // exceed the job timeout and silently drop its tail — process a slice,
        // then re-dispatch the remainder as fresh queue work.
        $capacity = $sleepMicros > 0
            ? max(1, (int) floor((($this->timeout - 20) * 1_000_000) / $sleepMicros))
            : PHP_INT_MAX;

        $ids = MarketingNotification::where('batch_id', $batch->id)
            ->whereIn('status', NotificationStatus::sendable())
            ->orderBy('id')
            ->pluck('id');

        $processed = 0;
        foreach ($ids as $id) {
            if ($processed >= $capacity) {
                break;
            }
            // Atomic claim: only the worker that flips sendable → processing sends
            // this row. Guards double-send under retries/concurrency and lets a
            // cancel of a not-yet-started row win the race.
            $claimed = MarketingNotification::where('id', $id)
                ->whereIn('status', NotificationStatus::sendable())
                ->update(['status' => NotificationStatus::PROCESSING]);
            if ($claimed === 0) {
                continue; // taken by another attempt / cancelled
            }
            $notification = MarketingNotification::find($id);
            if (! $notification) {
                continue;
            }
            $result = $channels->send($notification);
            $delivery->recordSendResult($notification, $result, $maxRetries);
            $processed++;
            if ($sleepMicros > 0) {
                usleep($sleepMicros);
            }
        }

        // Anything still sendable (capacity tail + transient RETRYING) → continue
        // as new queue work. Termination is guaranteed: each pass either sends a
        // row or increments its retry_count until it becomes FAILED (no longer
        // sendable). New job = new queue work, per the Retry Center contract.
        $remaining = MarketingNotification::where('batch_id', $batch->id)
            ->whereIn('status', NotificationStatus::sendable())
            ->count();

        if ($remaining > 0) {
            $batch->update(['status' => 'queued']);
            static::dispatch($batch->id)
                ->onQueue((string) config('marketing.queue', 'marketing'))
                ->delay(Carbon::now()->addSeconds($sleepMicros > 0 ? 1 : min(300, 15 * $attempt)));
            QueueLogger::record(static::class, 'retrying', ['remaining' => $remaining], $batch->run_id, $batch->campaign_id, $batch->id, attempt: $attempt);

            return;
        }

        QueueLogger::record(static::class, 'completed', ['batch' => $batch->batch_number], $batch->run_id, $batch->campaign_id, $batch->id, attempt: $attempt);
        $delivery->finalizeBatch($batch);
    }

    /** Whole-batch infra failure after tries exhausted: fail the batch cleanly. */
    public function failed(\Throwable $e): void
    {
        $batch = CampaignJob::find($this->batchId);
        if (! $batch) {
            return;
        }
        $batch->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => Carbon::now()]);
        // Fail everything not yet resolved (incl. rows left PROCESSING) so the
        // run can still finalize rather than hang on in-flight counts.
        MarketingNotification::where('batch_id', $batch->id)
            ->whereIn('status', [NotificationStatus::QUEUED, NotificationStatus::RETRYING, NotificationStatus::PROCESSING])
            ->update(['status' => NotificationStatus::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);

        $run = CampaignRun::find($batch->run_id);
        if ($run) {
            app(DeliveryService::class)->finalizeRunIfComplete($run);
        }
        QueueLogger::record(static::class, 'failed', ['error' => mb_substr($e->getMessage(), 0, 300)], $batch->run_id, $batch->campaign_id, $batch->id);
    }

    private function markRunProcessing(int $runId): void
    {
        $run = CampaignRun::find($runId);
        if ($run && ! in_array($run->status, RunStatus::terminal(), true) && $run->status !== RunStatus::PROCESSING) {
            $run->update(['status' => RunStatus::PROCESSING]);
        }
    }
}
