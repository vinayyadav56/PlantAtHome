<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\Channels\ChannelResult;
use App\Modules\Marketing\Application\Support\SendJobRouter;
use App\Modules\Marketing\Domain\CampaignStatus;
use App\Modules\Marketing\Domain\NotificationStatus;
use App\Modules\Marketing\Domain\RunStatus;
use App\Modules\Marketing\Domain\ScheduleType;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignJob;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Infrastructure\Models\DeliveryLog;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use App\Shared\Application\DomainActionException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Delivery tracking, run finalization, the Retry Center, and provider status
 * (webhook) ingestion. Every notification status change also appends a
 * DeliveryLog row, so the timeline is a full audit — nothing is overwritten
 * without a trace.
 */
final class DeliveryService
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function markProcessing(MarketingNotification $n): void
    {
        $n->update(['status' => NotificationStatus::PROCESSING]);
    }

    /**
     * Apply a channel send outcome. Success → sent. Failure → retrying while
     * under the campaign's max_retries, else failed. Always logs.
     */
    public function recordSendResult(MarketingNotification $n, ChannelResult $result, int $maxRetries): void
    {
        if ($result->ok) {
            $n->forceFill([
                'status'              => NotificationStatus::SENT,
                'provider'            => $result->provider,
                'provider_message_id' => $result->messageId,
                'provider_response'   => $result->response ?: null,
                'error'               => null,
                'sent_at'             => Carbon::now(),
            ])->save();
            $this->log($n, NotificationStatus::SENT, $result->provider, $result->messageId, $result->response);

            return;
        }

        $attempts = (int) $n->retry_count + 1;
        $status = $attempts < max(1, $maxRetries) ? NotificationStatus::RETRYING : NotificationStatus::FAILED;
        $n->forceFill([
            'status'            => $status,
            'provider'          => $result->provider,
            'provider_response' => $result->response ?: null,
            'retry_count'       => $attempts,
            'error'             => $result->error ? mb_substr($result->error, 0, 1000) : 'Send failed.',
        ])->save();
        $this->log($n, $status, $result->provider, null, $result->response, $result->error);
    }

    /**
     * Ingest a provider delivery/read callback keyed on provider_message_id. Uses
     * a status rank so an out-of-order webhook can't downgrade (a 'delivered'
     * arriving after 'read' is ignored). Returns whether a row was updated.
     */
    public function recordProviderStatus(string $providerMessageId, string $status, array $response = [], ?string $error = null): bool
    {
        $n = MarketingNotification::where('provider_message_id', $providerMessageId)->latest('id')->first();
        if (! $n) {
            return false;
        }

        return $this->applyStatus($n, $status, $response, $error);
    }

    /**
     * Move a notification to a new lifecycle status, guarding against out-of-order
     * downgrades (a 'delivered' arriving after 'read' is logged but not applied).
     * Stamps delivered_at/read_at and always appends a delivery log.
     */
    public function applyStatus(MarketingNotification $n, string $status, array $response = [], ?string $error = null): bool
    {
        if (! in_array($status, NotificationStatus::all(), true)) {
            return false;
        }
        // Never move backwards (delivered after read, etc.); failures always log.
        if ($status !== NotificationStatus::FAILED && NotificationStatus::rank($status) <= NotificationStatus::rank($n->status)) {
            $this->log($n, $status, $n->provider, $n->provider_message_id, $response, $error);

            return true;
        }

        $patch = ['status' => $status, 'provider_response' => $response ?: $n->provider_response];
        if ($status === NotificationStatus::DELIVERED && ! $n->delivered_at) {
            $patch['delivered_at'] = Carbon::now();
        }
        if ($status === NotificationStatus::READ) {
            $patch['read_at'] = Carbon::now();
            $patch['delivered_at'] = $n->delivered_at ?? Carbon::now();
        }
        if ($error) {
            $patch['error'] = mb_substr($error, 0, 1000);
        }
        $n->forceFill($patch)->save();
        $this->log($n, $status, $n->provider, $n->provider_message_id, $response, $error);

        // A late delivered/read webhook changes a status AFTER the run already
        // completed — refresh the run's frozen counts snapshot so its tally
        // reflects the new state (the campaign analytics query is already live).
        $run = CampaignRun::find($n->run_id);
        if ($run) {
            $run->update(['counts' => $this->counts($run)]);
        }

        return true;
    }

    /**
     * Mark a batch done, then complete the whole run once no notification is
     * still in flight (queued/processing/retrying) anywhere in the run.
     */
    public function finalizeBatch(CampaignJob $batch): void
    {
        $batch->update(['status' => 'completed', 'finished_at' => Carbon::now()]);
        $run = CampaignRun::find($batch->run_id);
        if ($run) {
            $this->finalizeRunIfComplete($run);
        }
    }

    public function finalizeRunIfComplete(CampaignRun $run): void
    {
        $counts = $this->counts($run);
        $inFlight = ($counts[NotificationStatus::QUEUED] ?? 0)
            + ($counts[NotificationStatus::PROCESSING] ?? 0)
            + ($counts[NotificationStatus::RETRYING] ?? 0);

        if ($inFlight > 0) {
            // Persist the running tally, but never touch a terminal run.
            CampaignRun::query()->where('id', $run->id)
                ->whereNotIn('status', RunStatus::terminal())
                ->update(['counts' => json_encode($counts)]);

            return;
        }

        // Atomically claim completion — with ≥2 workers the last two batches can
        // both see inFlight==0; only the one that flips non-terminal → COMPLETED
        // settles the campaign.
        $completed = CampaignRun::query()->where('id', $run->id)
            ->whereNotIn('status', RunStatus::terminal())
            ->update([
                'status'      => RunStatus::COMPLETED,
                'finished_at' => Carbon::now(),
                'counts'      => json_encode($counts),
            ]);
        if ($completed === 0) {
            return;
        }

        $this->settleCampaign($run->fresh());
    }

    /** Status → count tally for a run's notifications. @return array<string,int> */
    public function counts(CampaignRun $run): array
    {
        return MarketingNotification::where('run_id', $run->id)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * The Retry Center: re-queue failed (or selected) notifications as brand-new
     * jobs under a fresh run. Retries create new queue work — they never mutate
     * the framework's failed_jobs table.
     *
     * @param string[] $uuids
     */
    public function retry(Campaign $campaign, string $scope, array $uuids, ?string $actorUuid): CampaignRun
    {
        // Only ever retry TERMINAL rows — never move/duplicate an in-flight
        // notification (that would corrupt its live run's tallies or double-send).
        $query = MarketingNotification::where('campaign_id', $campaign->id);
        if ($scope === 'selected') {
            if ($uuids === []) {
                throw DomainActionException::unprocessable('No notifications selected to retry.', 'RETRY_NOTHING_SELECTED');
            }
            $query->whereIn('uuid', $uuids)
                ->whereIn('status', [NotificationStatus::FAILED, NotificationStatus::CANCELLED]);
        } elseif ($scope === 'all') {
            $query->whereIn('status', [NotificationStatus::FAILED, NotificationStatus::CANCELLED]);
        } else { // 'failed'
            $query->where('status', NotificationStatus::FAILED);
        }

        $targets = $query->get();
        if ($targets->isEmpty()) {
            throw DomainActionException::unprocessable('There is nothing to retry.', 'RETRY_NOTHING_TO_RETRY');
        }

        return $this->db->transaction(function () use ($campaign, $targets, $actorUuid) {
            $run = CampaignRun::create([
                'campaign_id'         => $campaign->id,
                'audience_version_id' => $campaign->audience_version_id,
                'status'              => RunStatus::QUEUED,
                'trigger'             => 'retry',
                'total_recipients'    => $targets->pluck('recipient')->unique()->count(),
                'total_messages'      => $targets->count(),
                'created_by_uuid'     => $actorUuid,
            ]);

            $batchSize = max(1, (int) $campaign->batch_size);
            foreach ($targets->groupBy('channel') as $channel => $group) {
                $batchNumber = 0;
                foreach ($group->chunk($batchSize) as $chunk) {
                    $batchNumber++;
                    $batch = CampaignJob::create([
                        'run_id'       => $run->id,
                        'campaign_id'  => $campaign->id,
                        'batch_number' => $batchNumber,
                        'channel'      => $channel,
                        'size'         => $chunk->count(),
                        'status'       => 'queued',
                        'queued_at'    => Carbon::now(),
                    ]);

                    // CLONE into fresh notifications for the new run — the
                    // originals stay put (historical), so the source run's counts
                    // are never mutated.
                    $now = Carbon::now();
                    $insert = $chunk->map(fn (MarketingNotification $n) => [
                        'uuid'             => (string) Str::uuid(),
                        'run_id'           => $run->id,
                        'campaign_id'      => $campaign->id,
                        'batch_id'         => $batch->id,
                        'channel'          => $n->channel,
                        'recipient'        => $n->recipient,
                        'recipient_ref'    => $n->recipient_ref !== null ? json_encode($n->recipient_ref) : null,
                        'template_id'      => $n->template_id,
                        'rendered_subject' => $n->rendered_subject,
                        'rendered_body'    => $n->rendered_body,
                        'status'           => NotificationStatus::QUEUED,
                        'retry_count'      => 0,
                        'queued_at'        => $now,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ])->all();

                    foreach (array_chunk($insert, 500) as $rows) {
                        MarketingNotification::insert($rows);
                    }

                    $jobClass = SendJobRouter::jobFor((string) $channel);
                    if ($jobClass) {
                        $jobClass::dispatch($batch->id)->onQueue((string) config('marketing.queue', 'marketing'));
                    }
                }
            }

            $campaign->update(['status' => CampaignStatus::RUNNING, 'last_run_at' => Carbon::now()]);

            return $run;
        });
    }

    /** Cancel every not-yet-sent notification of a run (operator stop). */
    public function cancelRun(CampaignRun $run): void
    {
        MarketingNotification::where('run_id', $run->id)
            ->whereIn('status', [NotificationStatus::QUEUED, NotificationStatus::RETRYING])
            ->update(['status' => NotificationStatus::CANCELLED]);
        CampaignJob::where('run_id', $run->id)
            ->whereNotIn('status', ['completed'])
            ->update(['status' => 'completed', 'finished_at' => Carbon::now()]);
        $run->update([
            'status'      => RunStatus::CANCELLED,
            'counts'      => $this->counts($run),
            'finished_at' => Carbon::now(),
        ]);
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** When a run completes, return the campaign to a resting state. */
    private function settleCampaign(CampaignRun $run): void
    {
        $campaign = Campaign::find($run->campaign_id);
        if (! $campaign) {
            return;
        }
        // Another run of the same campaign still going? Leave it running.
        $stillRunning = CampaignRun::where('campaign_id', $campaign->id)
            ->whereNotIn('status', RunStatus::terminal())
            ->exists();
        if ($stillRunning) {
            return;
        }

        $recurringNext = ScheduleType::isRecurring($campaign->schedule_type) && $campaign->next_run_at;
        $campaign->update([
            'status'      => $recurringNext ? CampaignStatus::SCHEDULED : CampaignStatus::COMPLETED,
            'last_run_at' => Carbon::now(),
        ]);
    }

    private function log(MarketingNotification $n, string $status, ?string $provider, ?string $messageId, array $response = [], ?string $error = null): void
    {
        DeliveryLog::create([
            'uuid'                => (string) Str::uuid(),
            'notification_id'     => $n->id,
            'campaign_id'         => $n->campaign_id,
            'channel'             => $n->channel,
            'status'              => $status,
            'provider'            => $provider,
            'provider_message_id' => $messageId,
            'provider_response'   => $response ?: null,
            'error'               => $error ? mb_substr($error, 0, 1000) : null,
            'occurred_at'         => Carbon::now(),
        ]);
    }
}
