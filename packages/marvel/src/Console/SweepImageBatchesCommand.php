<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Console\Support\Heartbeat;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Jobs\GenerateImageBatchJob;
use Marvel\Jobs\PackageImageBatchZipJob;

/**
 * Every-minute safety net for background image batches:
 *  1. Re-pend rows stuck `processing` past the stall window (a crashed worker
 *     that uploaded but didn't record simply overwrites the same s3 key).
 *  2. Re-dispatch active batches with no live job chain (worker died/redeploy).
 *  3. Catch a missed finalize (all rows terminal but batch still processing).
 *  4. Re-kick stuck packaging (idempotent — same keys are overwritten).
 * Double-dispatch is tolerated everywhere: rows are claimed atomically.
 */
class SweepImageBatchesCommand extends Command
{
    protected $signature = 'images:sweep-batches';

    protected $description = 'Recover stalled AI image-generation batches (re-pend stuck rows, re-dispatch, finalize).';

    public function handle(): int
    {
        $stallMinutes      = (int) config('image-batches.stall_minutes', 30);
        $redispatchMinutes = (int) config('image-batches.redispatch_minutes', 10);

        // 1. Stalled rows → back in play.
        $repended = ImageGenerationJob::query()
            ->where('status', ImageGenerationJob::STATUS_PROCESSING)
            ->where('claimed_at', '<', now()->subMinutes($stallMinutes))
            ->whereIn('batch_id', ImageBatch::whereIn('status', [ImageBatch::STATUS_PENDING, ImageBatch::STATUS_PROCESSING])->pluck('id'))
            ->update(['status' => ImageGenerationJob::STATUS_PENDING, 'claimed_at' => null]);
        if ($repended) {
            $this->info("Re-pended {$repended} stalled row(s).");
        }

        // Rows a cancel left mid-flight after their worker died.
        ImageGenerationJob::query()
            ->where('status', ImageGenerationJob::STATUS_PROCESSING)
            ->where('claimed_at', '<', now()->subMinutes($stallMinutes))
            ->whereIn('batch_id', ImageBatch::where('status', ImageBatch::STATUS_CANCELLED)->pluck('id'))
            ->update(['status' => ImageGenerationJob::STATUS_CANCELLED, 'claimed_at' => null]);

        $active = ImageBatch::whereIn('status', [ImageBatch::STATUS_PENDING, ImageBatch::STATUS_PROCESSING])->get();

        foreach ($active as $batch) {
            $open = ImageGenerationJob::where('batch_id', $batch->id)
                ->whereIn('status', [
                    ImageGenerationJob::STATUS_PENDING,
                    ImageGenerationJob::STATUS_RETRYING,
                    ImageGenerationJob::STATUS_PROCESSING,
                ])
                ->count();

            if ($open === 0) {
                // 3. Missed finalize — atomic flip guarantees a single packer.
                $flipped = ImageBatch::where('id', $batch->id)
                    ->whereIn('status', [ImageBatch::STATUS_PENDING, ImageBatch::STATUS_PROCESSING])
                    ->update(['status' => ImageBatch::STATUS_PACKAGING]);
                if ($flipped === 1) {
                    PackageImageBatchZipJob::dispatch($batch->id);
                    $this->info("Batch {$batch->display_id}: finalize was missed — packaging dispatched.");
                }

                continue;
            }

            // 2. Orphaned chain — nothing dispatched recently.
            $lastDispatched = $batch->last_dispatched_at;
            if (!$lastDispatched || $lastDispatched->lt(now()->subMinutes($redispatchMinutes))) {
                ImageBatch::where('id', $batch->id)->update(['last_dispatched_at' => now()]);
                GenerateImageBatchJob::dispatch($batch->id);
                $this->info("Batch {$batch->display_id}: re-dispatched ({$open} open row(s)).");
            }
        }

        // 4. Stuck packaging (not permanently failed) → re-kick.
        ImageBatch::where('status', ImageBatch::STATUS_PACKAGING)
            ->whereNull('zip_error')
            ->where('updated_at', '<', now()->subMinutes($stallMinutes))
            ->each(function (ImageBatch $batch) {
                ImageBatch::where('id', $batch->id)->update(['updated_at' => now()]);
                PackageImageBatchZipJob::dispatch($batch->id);
                $this->info("Batch {$batch->display_id}: packaging re-dispatched.");
            });

        Heartbeat::beat('images-sweeper');

        return self::SUCCESS;
    }
}
