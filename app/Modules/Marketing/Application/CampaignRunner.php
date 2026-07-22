<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\Rendering\MessageRenderer;
use App\Modules\Marketing\Application\Support\QueueLogger;
use App\Modules\Marketing\Application\Support\SendJobRouter;
use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Domain\NotificationStatus;
use App\Modules\Marketing\Domain\RunStatus;
use App\Modules\Marketing\Infrastructure\Models\AudienceVersion;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignJob;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Jobs\GenerateCampaignBatchesJob;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Materializes a campaign run: reads the pinned audience snapshot, renders one
 * notification per recipient-per-channel (streamed in inserts, never held all at
 * once beyond the snapshot itself), groups them into batch_size batches, and
 * dispatches the matching Send*Job for each batch. Idempotent — only a pending
 * run is materialized, so a job retry can't double-insert.
 */
final class CampaignRunner
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DeliveryService $delivery,
    ) {
    }

    public function materialize(CampaignRun $run): void
    {
        // Atomic claim: only the worker that flips PENDING → MATERIALIZING may
        // proceed, so a duplicate/retried GenerateCampaignBatchesJob can't
        // double-insert notifications (check-then-act would race).
        $claimed = CampaignRun::query()
            ->where('id', $run->id)
            ->where('status', RunStatus::PENDING)
            ->update(['status' => RunStatus::MATERIALIZING, 'started_at' => Carbon::now()]);
        if ($claimed === 0) {
            return;
        }
        $run->refresh();

        $campaign = $run->campaign;
        if (! $campaign) {
            $this->failRun($run, 'Campaign no longer exists.');

            return;
        }

        QueueLogger::record(GenerateCampaignBatchesJob::class, 'processing', ['run' => $run->uuid], $run->id, $campaign->id);

        try {
            $version = $run->audience_version_id ? AudienceVersion::find($run->audience_version_id) : null;
            $rows = $version ? $version->rows() : [];
            $run->update(['total_recipients' => count($rows)]);

            if ($rows === []) {
                $this->delivery->finalizeRunIfComplete($run);

                return;
            }

            $templates = $this->templatesByChannel($campaign);
            $batchSize = max(1, (int) $campaign->batch_size);
            $totalMessages = 0;
            $dispatch = []; // [ [batchId, channel], … ] — fired only AFTER run→QUEUED

            foreach ((array) $campaign->channels as $channel) {
                if (! isset($templates[$channel]) || ! SendJobRouter::jobFor((string) $channel)) {
                    continue;
                }
                $totalMessages += $this->materializeChannel($run, $campaign, (string) $channel, $templates[$channel], $rows, $batchSize, $dispatch);
            }

            // Write QUEUED BEFORE dispatching any Send job. Otherwise a fast Send
            // job could finalize the run to COMPLETED and we'd overwrite it back
            // to QUEUED here, leaving it stuck. Conditional so a concurrent cancel
            // (→ CANCELLED) is never clobbered either.
            CampaignRun::query()
                ->where('id', $run->id)
                ->where('status', RunStatus::MATERIALIZING)
                ->update(['status' => RunStatus::QUEUED, 'total_messages' => $totalMessages]);
            $run->refresh();

            foreach ($dispatch as [$batchId, $channel]) {
                $this->fireBatch($campaign, $batchId, (string) $channel);
            }

            // Every recipient row lacked a usable address for every channel.
            if ($totalMessages === 0) {
                $this->delivery->finalizeRunIfComplete($run);
            }
        } catch (\Throwable $e) {
            $this->failRun($run, $e->getMessage());
        }
    }

    /**
     * @param array{id:int,content:array<string,mixed>} $template
     * @param array<int, array<string,mixed>> $rows
     * @param array<int, array{0:int,1:string}> $dispatch  collected [batchId, channel] to fire later
     */
    private function materializeChannel(CampaignRun $run, Campaign $campaign, string $channel, array $template, array $rows, int $batchSize, array &$dispatch): int
    {
        $field = Channel::recipientField($channel);
        $created = 0;
        $batchNumber = 0;

        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $pending = [];
            foreach ($chunk as $row) {
                $recipient = $this->recipientValue($row, $field);
                if ($recipient === '') {
                    continue; // no address for this channel — skip this recipient
                }
                $rendered = MessageRenderer::render($channel, $template['content'], $row);
                $pending[] = [
                    'run_id'           => $run->id,
                    'campaign_id'      => $campaign->id,
                    'channel'          => $channel,
                    'recipient'        => mb_substr($recipient, 0, 255),
                    'recipient_ref'    => json_encode($row),
                    'template_id'      => $template['id'],
                    'rendered_subject' => $rendered['subject'] !== null ? mb_substr($rendered['subject'], 0, 255) : null,
                    'rendered_body'    => $rendered['body'],
                    'status'           => NotificationStatus::QUEUED,
                ];
            }

            if ($pending === []) {
                continue;
            }

            $batchNumber++;
            $batch = CampaignJob::create([
                'run_id'       => $run->id,
                'campaign_id'  => $campaign->id,
                'batch_number' => $batchNumber,
                'channel'      => $channel,
                'size'         => count($pending),
                'status'       => 'pending',
            ]);

            $now = Carbon::now();
            foreach ($pending as &$row) {
                $row['uuid'] = (string) Str::uuid();
                $row['batch_id'] = $batch->id;
                $row['queued_at'] = $now;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);

            foreach (array_chunk($pending, self::INSERT_CHUNK) as $insert) {
                $this->db->table('marketing_notifications')->insert($insert);
            }
            $created += count($pending);

            // Mark queued now, but fire the Send job later (after run→QUEUED).
            $batch->update(['status' => 'queued', 'queued_at' => Carbon::now()]);
            $dispatch[] = [$batch->id, $channel];
        }

        return $created;
    }

    /** Dispatch a batch's Send*Job — called only after the run is QUEUED. */
    private function fireBatch(Campaign $campaign, int $batchId, string $channel): void
    {
        $jobClass = SendJobRouter::jobFor($channel);
        if (! $jobClass) {
            return;
        }
        $delay = max(0, (int) $campaign->send_delay_seconds);

        $pending = $jobClass::dispatch($batchId)->onQueue((string) config('marketing.queue', 'marketing'));
        if ($delay > 0) {
            $pending->delay(Carbon::now()->addSeconds($delay));
        }

        QueueLogger::record($jobClass, 'dispatched', ['batch' => $batchId], null, $campaign->id, $batchId, message: "channel={$channel}");
    }

    /**
     * @param Campaign $campaign
     * @return array<string, array{id:int,content:array<string,mixed>}>
     */
    private function templatesByChannel(Campaign $campaign): array
    {
        $map = [];
        foreach ($campaign->templates()->with('template')->get() as $binding) {
            $template = $binding->template;
            if ($template) {
                $map[$binding->channel] = ['id' => $template->id, 'content' => (array) $template->content];
            }
        }

        return $map;
    }

    /** Case-insensitive column lookup so {{email}}/EMAIL both resolve. */
    private function recipientValue(array $row, string $field): string
    {
        if (array_key_exists($field, $row)) {
            return trim((string) $row[$field]);
        }
        foreach ($row as $key => $value) {
            if (strtolower((string) $key) === $field) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function failRun(CampaignRun $run, string $error): void
    {
        $run->update([
            'status'      => RunStatus::FAILED,
            'error'       => mb_substr($error, 0, 1000),
            'finished_at' => Carbon::now(),
        ]);
        QueueLogger::record(GenerateCampaignBatchesJob::class, 'failed', ['error' => mb_substr($error, 0, 300)], $run->id, $run->campaign_id);
    }
}
