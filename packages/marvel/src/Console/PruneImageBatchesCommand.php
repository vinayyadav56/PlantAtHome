<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ImageBatch;

/**
 * Daily retention: delete a lapsed batch's generated files (s3 prefix incl.
 * zips + manifest + reference images) and the uploaded spreadsheet. The DB
 * rows — batch, per-row jobs, per-image results — are kept forever as the
 * audit trail; files_pruned_at marks that the files are gone.
 */
class PruneImageBatchesCommand extends Command
{
    protected $signature = 'images:prune-batches {--force : Prune regardless of expiry}';

    protected $description = 'Delete generated files of AI image batches past their retention window.';

    public function handle(): int
    {
        $query = ImageBatch::whereNull('files_pruned_at')
            ->whereNotIn('status', ImageBatch::ACTIVE_STATUSES);

        if (!$this->option('force')) {
            $query->whereNotNull('expires_at')->where('expires_at', '<', now());
        }

        $pruned = 0;
        $query->orderBy('id')->chunkById(20, function ($batches) use (&$pruned) {
            foreach ($batches as $batch) {
                try {
                    Storage::disk('s3')->deleteDirectory($batch->storagePrefix());
                } catch (\Throwable $e) {
                    $this->warn("Batch {$batch->display_id}: s3 prune failed — {$e->getMessage()}");

                    continue; // retried on the next run
                }

                if ($batch->original_file) {
                    try {
                        Storage::disk('local')->delete($batch->original_file);
                    } catch (\Throwable $e) {
                        // Local original is best-effort.
                    }
                }

                $batch->update(['files_pruned_at' => now()]);
                $pruned++;
            }
        });

        $this->info("Pruned files of {$pruned} batch(es).");

        return self::SUCCESS;
    }
}
