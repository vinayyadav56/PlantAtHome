<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Ai\ImageGenerationException;
use Marvel\Ai\OpenAiImageClient;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Database\Models\ImageGenerationResult;

/**
 * Self-re-dispatching claim-loop worker for one image batch (mirrors the
 * marketing AbstractSendBatchJob pattern — one live queue job per batch, not
 * one per image).
 *
 * Idempotent by construction: rows are claimed atomically, and an image slot
 * with a completed result row is never regenerated — a crash/timeout resumes
 * exactly at the unfinished tail. Pacing sleeps only the remainder of the
 * configured per-image interval, and each invocation caps its work so it can
 * never outlive the queue timeout; the tail is re-dispatched as fresh work.
 */
class GenerateImageBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Infra-level retries for an unexpected whole-invocation failure. */
    public int $tries = 2;
    public int $timeout = 900; // < queue retry_after (960s)

    // Not readonly: DB-queue jobs are restored via reflection and readonly
    // props break deserialization (documented on AbstractSendBatchJob).
    public int $batchId;

    public function __construct(int $batchId)
    {
        $this->batchId = $batchId;
        $this->onQueue((string) config('image-batches.queue', 'images'));
    }

    /** @return int[] per-attempt backoff seconds */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(OpenAiImageClient $client): void
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch || !in_array($batch->status, [ImageBatch::STATUS_PENDING, ImageBatch::STATUS_PROCESSING], true)) {
            return; // cancelled / already packaging / gone
        }

        $batch->update([
            'status'             => ImageBatch::STATUS_PROCESSING,
            'last_dispatched_at' => now(),
        ]);

        $interval = 60.0 / max(0.1, (float) config('image-batches.rate_per_minute', 5));
        // Never outlive the queue timeout: leave 120s headroom for the slow
        // final call + finalization, then hand the tail to a fresh job.
        $capacity  = max(1, (int) floor(($this->timeout - 120) / max(1.0, $interval)));
        $processed = 0;

        $references = $this->downloadReferences($batch);

        try {
            while ($processed < $capacity) {
                $batch->refresh();
                if ($batch->status !== ImageBatch::STATUS_PROCESSING) {
                    return; // cancelled mid-run
                }

                $row = $this->claimNextRow($batch);
                if (!$row) {
                    break; // drained (or only backoff-cooling retries left)
                }

                [$processed, $finished] = $this->processRow($client, $batch, $row, $references, $processed, $capacity, $interval);

                if (!$finished) {
                    break; // capacity hit mid-row; row was re-pended
                }
            }
        } finally {
            $this->cleanupReferences($references);
        }

        $this->finalizeOrContinue($batch);
    }

    /**
     * Whole-invocation infra failure after tries: don't fail the batch — rows
     * left `processing` are re-pended by the sweeper and the batch re-dispatched.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('GenerateImageBatchJob failed', [
            'batch_id' => $this->batchId,
            'error'    => mb_substr($e->getMessage(), 0, 500),
        ]);
    }

    /* ── claiming ─────────────────────────────────────────────────────────── */

    private function claimNextRow(ImageBatch $batch): ?ImageGenerationJob
    {
        for ($i = 0; $i < 25; $i++) {
            $candidate = ImageGenerationJob::where('batch_id', $batch->id)
                ->where('status', ImageGenerationJob::STATUS_PENDING)
                ->orderBy('id')
                ->first();

            if (!$candidate) {
                // Retrying rows cool off between attempts (0 in tests).
                $cooldown  = (int) config('image-batches.retry_cooldown_seconds', 60);
                $candidate = ImageGenerationJob::where('batch_id', $batch->id)
                    ->where('status', ImageGenerationJob::STATUS_RETRYING)
                    ->where('updated_at', '<=', now()->subSeconds($cooldown))
                    ->orderBy('id')
                    ->first();
            }
            if (!$candidate) {
                return null;
            }

            // Atomic claim — only the worker that flips claimable → processing
            // owns the row (guards double-generation under sweeper re-dispatch).
            $claimed = ImageGenerationJob::where('id', $candidate->id)
                ->whereIn('status', ImageGenerationJob::CLAIMABLE_STATUSES)
                ->update(['status' => ImageGenerationJob::STATUS_PROCESSING, 'claimed_at' => now()]);

            if ($claimed === 1) {
                return $candidate->fresh();
            }
        }

        return null;
    }

    /* ── row processing ───────────────────────────────────────────────────── */

    /**
     * @param array<int, array{path: string, name: string}> $references
     * @return array{0: int, 1: bool} [processed, rowFinished]
     */
    private function processRow(
        OpenAiImageClient $client,
        ImageBatch $batch,
        ImageGenerationJob $row,
        array $references,
        int $processed,
        int $capacity,
        float $interval,
    ): array {
        $settings = (array) $batch->settings;

        for ($index = 1; $index <= (int) $row->images_requested; $index++) {
            // Partial-success invariant: a completed slot is never regenerated.
            $existing = ImageGenerationResult::where('job_id', $row->id)->where('image_index', $index)->first();
            if ($existing && $existing->status === ImageGenerationResult::STATUS_COMPLETED) {
                continue;
            }

            if ($processed >= $capacity || !ImageBatch::where('id', $batch->id)->where('status', ImageBatch::STATUS_PROCESSING)->exists()) {
                // Out of budget (or cancelled) mid-row: put the row back —
                // completed slots are recorded, so resume picks up the tail.
                $row->update(['status' => ImageGenerationJob::STATUS_PENDING, 'claimed_at' => null]);

                return [$processed, false];
            }

            $started = microtime(true);
            $paceThisImage = true;

            try {
                $image = $this->generateOne($client, $batch, $row, $references, $settings);

                $fileName = $row->folder_name . '_' . $index . '.png';
                // 'folders' = per-plant subfolder (default); 'flat' = all files together.
                $s3Path = $batch->fileStructure() === 'flat'
                    ? $batch->storagePrefix() . '/' . $fileName
                    : $batch->storagePrefix() . '/' . $row->folder_name . '/' . $fileName;
                // Never set visibility — the bucket has ACLs disabled and is
                // public via bucket policy (see config/filesystems.php).
                Storage::disk('s3')->put($s3Path, $image['bytes']);

                ImageGenerationResult::updateOrCreate(
                    ['job_id' => $row->id, 'image_index' => $index],
                    [
                        'batch_id'       => $batch->id,
                        'status'         => ImageGenerationResult::STATUS_COMPLETED,
                        'file_name'      => $fileName,
                        's3_path'        => $s3Path,
                        'public_url'     => Storage::disk('s3')->url($s3Path),
                        'bytes'          => strlen($image['bytes']),
                        'model'          => $settings['model'] ?? null,
                        'size'           => $settings['size'] ?? null,
                        'quality'        => $settings['quality'] ?? null,
                        'style'          => $settings['style'] ?? null,
                        'revised_prompt' => $image['revised_prompt'],
                        'error'          => null,
                    ]
                );
                ImageBatch::where('id', $batch->id)->increment('generated_count');
            } catch (\Throwable $e) {
                ImageGenerationResult::updateOrCreate(
                    ['job_id' => $row->id, 'image_index' => $index],
                    [
                        'batch_id' => $batch->id,
                        'status'   => ImageGenerationResult::STATUS_FAILED,
                        'model'    => $settings['model'] ?? null,
                        'size'     => $settings['size'] ?? null,
                        'quality'  => $settings['quality'] ?? null,
                        'style'    => $settings['style'] ?? null,
                        'error'    => mb_substr($e->getMessage(), 0, 1000),
                    ]
                );
                $row->last_error = mb_substr($e->getMessage(), 0, 1000);

                if ($e instanceof ImageGenerationException && $e->isRateLimited()) {
                    // Back off one extra interval on 429 before the next call.
                    $this->pause($interval);
                }
                // Local/config failures (status 0) never reached OpenAI —
                // pacing them only burns worker time.
                if ($e instanceof ImageGenerationException && $e->status === 0) {
                    $paceThisImage = false;
                }
            }

            $processed++;
            $elapsed = microtime(true) - $started;
            if ($paceThisImage && $elapsed < $interval) {
                $this->pause($interval - $elapsed);
            }
        }

        $this->finalizeRow($batch, $row);

        return [$processed, true];
    }

    private function finalizeRow(ImageBatch $batch, ImageGenerationJob $row): void
    {
        $completed = ImageGenerationResult::where('job_id', $row->id)
            ->where('status', ImageGenerationResult::STATUS_COMPLETED)
            ->count();
        $failed = max(0, (int) $row->images_requested - $completed);

        if ($failed === 0) {
            $row->update([
                'status'           => ImageGenerationJob::STATUS_COMPLETED,
                'images_completed' => $completed,
                'images_failed'    => 0,
                'last_error'       => null,
                'claimed_at'       => null,
            ]);

            return;
        }

        $attempts = (int) $row->attempts + 1;
        if ($attempts < (int) config('image-batches.row_max_attempts', 3)) {
            $row->update([
                'status'           => ImageGenerationJob::STATUS_RETRYING,
                'attempts'         => $attempts,
                'images_completed' => $completed,
                'images_failed'    => $failed,
                'last_error'       => $row->last_error,
                'claimed_at'       => null,
            ]);

            return;
        }

        // Attempts exhausted — the still-failed slots now count against the batch.
        $row->update([
            'status'           => $completed > 0 ? ImageGenerationJob::STATUS_PARTIAL : ImageGenerationJob::STATUS_FAILED,
            'attempts'         => $attempts,
            'images_completed' => $completed,
            'images_failed'    => $failed,
            'last_error'       => $row->last_error,
            'claimed_at'       => null,
        ]);
        ImageBatch::where('id', $batch->id)->increment('failed_count', $failed);
    }

    /* ── generation ───────────────────────────────────────────────────────── */

    /**
     * @param array<int, array{path: string, name: string}> $references
     */
    private function generateOne(
        OpenAiImageClient $client,
        ImageBatch $batch,
        ImageGenerationJob $row,
        array $references,
        array $settings,
    ): array {
        $model      = (string) ($settings['model'] ?? 'gpt-image-1');
        $size       = (string) ($settings['size'] ?? '1024x1024');
        $quality    = $settings['quality'] ?? null;
        $style      = $settings['style'] ?? null;
        $background = $settings['background'] ?? null;

        $modelConfig = (array) config("image-batches.models.{$model}", []);
        $prompt      = $row->prompt;

        // gpt-image-1 has no style param — fold the configured suffix in.
        $styleSuffix = $style ? ($modelConfig['style_prompts'][$style] ?? null) : null;
        if ($styleSuffix) {
            $prompt = rtrim($prompt, " .") . '. ' . $styleSuffix . '.';
        }

        $useReferences = !empty($references) && !empty($modelConfig['supports_reference_images']);
        $apiStyle      = empty($modelConfig['style_prompts']) ? $style : null; // real param only when not prompt-folded

        if ($useReferences) {
            return $client->edit($model, $prompt, $size, $quality, $background, $references);
        }

        return $client->generate($model, $prompt, $size, $quality, $apiStyle, $background);
    }

    /* ── reference images ─────────────────────────────────────────────────── */

    /** @return array<int, array{path: string, name: string}> */
    private function downloadReferences(ImageBatch $batch): array
    {
        $keys = (array) ($batch->reference_images ?? []);
        if (empty($keys)) {
            return [];
        }

        $dir = storage_path('app/tmp/ai-batches/' . $batch->display_id . '/refs');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $files = [];
        foreach ($keys as $i => $key) {
            try {
                $contents = Storage::disk('s3')->get($key);
                if ($contents === null) {
                    continue;
                }
                $name = basename((string) $key) ?: ('reference_' . ($i + 1) . '.png');
                $path = $dir . '/' . $name;
                file_put_contents($path, $contents);
                $files[] = ['path' => $path, 'name' => $name];
            } catch (\Throwable $e) {
                Log::warning('Image batch reference download failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        return $files;
    }

    /** @param array<int, array{path: string, name: string}> $references */
    private function cleanupReferences(array $references): void
    {
        foreach ($references as $file) {
            @unlink($file['path']);
        }
    }

    /* ── finalize / continue ──────────────────────────────────────────────── */

    private function finalizeOrContinue(ImageBatch $batch): void
    {
        $open = ImageGenerationJob::where('batch_id', $batch->id)
            ->whereIn('status', [
                ImageGenerationJob::STATUS_PENDING,
                ImageGenerationJob::STATUS_RETRYING,
                ImageGenerationJob::STATUS_PROCESSING,
            ])
            ->count();

        if ($open > 0) {
            // Continue as fresh queue work — but ONLY when a row is claimable
            // now (pending, or a retrying row past its cooldown). If everything
            // open is still cooling down, the sweeper re-dispatches later;
            // dispatching here would spin (and on a sync queue, recurse
            // indefinitely inside the original request).
            $cooldown  = (int) config('image-batches.retry_cooldown_seconds', 60);
            $claimable = ImageGenerationJob::where('batch_id', $batch->id)
                ->where(function ($q) use ($cooldown) {
                    $q->where('status', ImageGenerationJob::STATUS_PENDING)
                        ->orWhere(function ($q2) use ($cooldown) {
                            $q2->where('status', ImageGenerationJob::STATUS_RETRYING)
                                ->where('updated_at', '<=', now()->subSeconds($cooldown));
                        });
                })
                ->exists();

            if ($claimable) {
                ImageBatch::where('id', $batch->id)->update(['last_dispatched_at' => now()]);
                static::dispatch($batch->id)->delay(now()->addSeconds(5));
            }

            return;
        }

        // Exactly-once packaging: only the caller whose atomic flip wins
        // dispatches the packer — no double zip under concurrent chains.
        $flipped = ImageBatch::where('id', $batch->id)
            ->where('status', ImageBatch::STATUS_PROCESSING)
            ->update(['status' => ImageBatch::STATUS_PACKAGING]);

        if ($flipped === 1) {
            PackageImageBatchZipJob::dispatch($batch->id);
        }
    }

    private function pause(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        // Tests run with a tiny interval; cap defensive sleeping at 120s.
        usleep((int) (min($seconds, 120) * 1_000_000));
    }
}
