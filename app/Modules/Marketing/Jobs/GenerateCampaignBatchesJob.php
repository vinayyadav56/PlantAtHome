<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Jobs;

use App\Modules\Marketing\Application\CampaignRunner;
use App\Modules\Marketing\Application\Support\QueueLogger;
use App\Modules\Marketing\Domain\RunStatus;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Materializes a campaign run off the request path: freezes the audience into
 * per-recipient notifications, batches them, and dispatches the Send*Jobs.
 * tries=1 — materialization is deterministic, so a failure is recorded on the
 * run (not blindly retried, which would risk partial double-inserts).
 */
final class GenerateCampaignBatchesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public readonly int $runId)
    {
    }

    public function handle(CampaignRunner $runner): void
    {
        $run = CampaignRun::find($this->runId);
        if (! $run) {
            return;
        }
        $runner->materialize($run);
    }

    public function failed(\Throwable $e): void
    {
        $run = CampaignRun::find($this->runId);
        if ($run && ! in_array($run->status, RunStatus::terminal(), true)) {
            $run->update([
                'status'      => RunStatus::FAILED,
                'error'       => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
        QueueLogger::record(self::class, 'failed', ['error' => mb_substr($e->getMessage(), 0, 300)], $this->runId);
    }
}
