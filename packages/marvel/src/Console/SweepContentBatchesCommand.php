<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Console\Support\Heartbeat;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;

/**
 * Safety net for bulk AI content runs: re-pend rows whose worker died mid-flight
 * and re-dispatch active batches whose job chain ended (e.g. everything was
 * cooling in `retrying`). Double-dispatch is safe — rows are claimed atomically.
 */
class SweepContentBatchesCommand extends Command
{
    protected $signature = 'content:sweep-batches';

    protected $description = 'Re-drive stalled bulk AI product-content batches and rows.';

    public function handle(): int
    {
        $stallMinutes      = (int) config('content-batches.stall_minutes', 15);
        $redispatchMinutes = (int) config('content-batches.redispatch_minutes', 2);

        // Rows claimed but never finished (worker crash/timeout) → re-pend.
        ProductContentJob::where('status', ProductContentJob::STATUS_PROCESSING)
            ->where('claimed_at', '<=', now()->subMinutes($stallMinutes))
            ->update(['status' => ProductContentJob::STATUS_PENDING, 'claimed_at' => null]);

        $batches = ProductContentBatch::whereIn('status', ProductContentBatch::ACTIVE_STATUSES)
            ->where(function ($q) use ($redispatchMinutes) {
                $q->whereNull('last_dispatched_at')
                    ->orWhere('last_dispatched_at', '<=', now()->subMinutes($redispatchMinutes));
            })
            ->get();

        foreach ($batches as $batch) {
            $open = ProductContentJob::where('batch_id', $batch->id)
                ->whereIn('status', [
                    ProductContentJob::STATUS_PENDING,
                    ProductContentJob::STATUS_RETRYING,
                    ProductContentJob::STATUS_PROCESSING,
                ])
                ->count();

            if ($open > 0) {
                $batch->update(['last_dispatched_at' => now()]);
                GenerateProductContentBatchJob::dispatch($batch->id);
            } else {
                $failed = (int) $batch->failed_count;
                // Guard the flip so a concurrent cancel isn't overwritten
                // (mirrors the worker's guarded finalize).
                ProductContentBatch::where('id', $batch->id)
                    ->whereIn('status', ProductContentBatch::ACTIVE_STATUSES)
                    ->update([
                        'status'       => $failed > 0 ? ProductContentBatch::STATUS_COMPLETED_WITH_ERRORS : ProductContentBatch::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
            }
        }

        Heartbeat::beat('content-sweeper');

        return self::SUCCESS;
    }
}
