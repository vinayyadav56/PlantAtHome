<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\File as HttpFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationResult;
use ZipArchive;

/**
 * Packages a drained batch: multi-part ZIPs (bounded temp disk; PNGs are
 * STOREd not compressed) + manifest.json/csv, then finalizes the batch status
 * and emails the uploader. Idempotent — a re-run overwrites the same keys.
 */
class PackageImageBatchZipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 900;

    // Not readonly — DB-queue deserialization.
    public int $batchId;

    public function __construct(int $batchId)
    {
        $this->batchId = $batchId;
        $this->onQueue((string) config('image-batches.queue', 'images'));
    }

    /** @return int[] */
    public function backoff(): array
    {
        return [60, 300, 600];
    }

    public function handle(): void
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch || $batch->status !== ImageBatch::STATUS_PACKAGING) {
            return;
        }

        $tmpDir = storage_path('app/tmp/ai-batches/' . $batch->display_id . '/zip');

        try {
            $completed = ImageGenerationResult::where('batch_id', $batch->id)
                ->where('status', ImageGenerationResult::STATUS_COMPLETED)
                ->count();

            $zipParts     = [];
            $manifestPath = null;

            if ($completed > 0) {
                $manifestPath = $this->uploadManifest($batch);
                $zipParts     = $this->buildZipParts($batch, $tmpDir, $manifestPath);
            }

            $hasErrors = ((int) $batch->failed_count) > 0
                || ((int) $batch->row_error_count) > 0
                || ((int) $batch->cancelled_count) > 0;

            $batch->update([
                'zip_paths'     => $zipParts ?: null,
                'manifest_path' => $manifestPath,
                'zip_size'      => $zipParts ? array_sum(array_column($zipParts, 'bytes')) : null,
                'zip_error'     => null,
                'packaged_at'   => now(),
                'completed_at'  => now(),
                'expires_at'    => now()->addDays((int) config('image-batches.retention_days', 30)),
                'status'        => $completed === 0
                    ? ImageBatch::STATUS_FAILED
                    : ($hasErrors ? ImageBatch::STATUS_COMPLETED_WITH_ERRORS : ImageBatch::STATUS_COMPLETED),
            ]);

            $this->notify($batch->fresh());
        } finally {
            $this->deleteDirectory($tmpDir);
        }
    }

    /**
     * Packaging failed after all tries: finalize with the error so the batch
     * (whose images are individually downloadable per-row) never hangs.
     */
    public function failed(\Throwable $e): void
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch || $batch->status !== ImageBatch::STATUS_PACKAGING) {
            return;
        }

        $batch->update([
            'zip_error'    => mb_substr($e->getMessage(), 0, 1000),
            'completed_at' => now(),
            'expires_at'   => now()->addDays((int) config('image-batches.retention_days', 30)),
            'status'       => ImageBatch::STATUS_COMPLETED_WITH_ERRORS,
        ]);
        $this->notify($batch->fresh());
    }

    /* ── zip parts ────────────────────────────────────────────────────────── */

    /** @return array<int, array{part: int, path: string, bytes: int}> */
    private function buildZipParts(ImageBatch $batch, string $tmpDir, ?string $manifestPath): array
    {
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $maxPartBytes = (int) config('image-batches.zip.max_part_bytes', 1_500_000_000);
        // One random suffix per packaging run — public URLs stay unguessable.
        $suffix = strtolower(Str::random(8));

        $parts       = [];
        $partNumber  = 0;
        $zip         = null;
        $zipFile     = null;
        $partBytes   = 0;
        $entryIndex  = 0;

        $openPart = function () use (&$zip, &$zipFile, &$partNumber, &$partBytes, &$entryIndex, $tmpDir, $batch, $manifestPath) {
            $partNumber++;
            $zipFile = $tmpDir . '/' . $batch->display_id . '_part' . $partNumber . '.zip';
            $zip     = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Could not create zip part {$partNumber}.");
            }
            $partBytes  = 0;
            $entryIndex = 0;

            // The manifest rides in part 1's root for offline use.
            if ($partNumber === 1 && $manifestPath) {
                $manifest = Storage::disk('s3')->get($manifestPath);
                if ($manifest !== null) {
                    $zip->addFromString('manifest.json', $manifest);
                    $entryIndex++;
                }
            }
        };

        $closeAndUpload = function () use (&$zip, &$zipFile, &$partNumber, $batch, $suffix, &$parts) {
            if (!$zip) {
                return;
            }
            $zip->close();
            $zip = null;

            $key = $batch->storagePrefix() . '/' . $batch->display_id . '_' . $suffix . '_part' . $partNumber . '.zip';
            $stored = Storage::disk('s3')->putFileAs(
                dirname($key),
                new HttpFile($zipFile),
                basename($key)
            );
            if ($stored === false) {
                throw new \RuntimeException("Could not upload zip part {$partNumber} to storage.");
            }
            $parts[] = ['part' => $partNumber, 'path' => $key, 'bytes' => (int) filesize($zipFile)];
            @unlink($zipFile);
        };

        $openPart();

        // job_id then image_index keeps each plant's folder contiguous in a part.
        ImageGenerationResult::where('batch_id', $batch->id)
            ->where('status', ImageGenerationResult::STATUS_COMPLETED)
            ->orderBy('job_id')
            ->orderBy('image_index')
            ->with('job:id,folder_name')
            ->chunkById(200, function ($results) use (&$zip, &$partBytes, &$entryIndex, $tmpDir, $maxPartBytes, $openPart, $closeAndUpload, $batch) {
                foreach ($results as $result) {
                    $bytes = (int) ($result->bytes ?? 0);
                    if ($partBytes > 0 && $partBytes + $bytes > $maxPartBytes) {
                        $closeAndUpload();
                        $openPart();
                    }

                    $stream = Storage::disk('s3')->readStream($result->s3_path);
                    if ($stream === false || $stream === null) {
                        continue; // pruned/missing object — manifest still records it
                    }
                    $local = $tmpDir . '/entry_' . $entryIndex . '.png';
                    $out   = fopen($local, 'w');
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    $folder = $result->job->folder_name ?? 'images';
                    $entry  = $batch->fileStructure() === 'flat'
                        ? $result->file_name
                        : $folder . '/' . $result->file_name;
                    $zip->addFile($local, $entry);
                    // PNGs are already compressed — STORE keeps close() fast.
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);

                    $partBytes += max($bytes, (int) filesize($local));
                    $entryIndex++;
                }
                // Flush this chunk's entries into the archive so their temp
                // files can be reclaimed before the next chunk.
                $file = $zip->filename;
                $zip->close();
                $zip = new ZipArchive();
                $zip->open($file);
                array_map('unlink', glob($tmpDir . '/entry_*.png') ?: []);
            });

        $closeAndUpload();

        return $parts;
    }

    /* ── manifest ─────────────────────────────────────────────────────────── */

    private function uploadManifest(ImageBatch $batch): string
    {
        $images = [];
        ImageGenerationResult::where('batch_id', $batch->id)
            ->orderBy('job_id')
            ->orderBy('image_index')
            ->with('job:id,plant_code,plant_name,folder_name')
            ->chunkById(500, function ($results) use (&$images, $batch) {
                foreach ($results as $r) {
                    $images[] = [
                        'image_id'   => $r->display_id,
                        'plant_code' => $r->job->plant_code ?? null,
                        'plant_name' => $r->job->plant_name ?? null,
                        'file'       => $r->file_name
                            ? ($batch->fileStructure() === 'flat'
                                ? $r->file_name
                                : (($r->job->folder_name ?? '') . '/' . $r->file_name))
                            : null,
                        'url'        => $r->public_url,
                        'status'     => $r->status,
                        'model'      => $r->model,
                        'size'       => $r->size,
                        'quality'    => $r->quality,
                        'error'      => $r->error,
                    ];
                }
            });

        $manifest = [
            'batch' => [
                'display_id'   => $batch->display_id,
                'uploaded_by'  => $batch->uploaded_by,
                'notify_email' => $batch->notify_email,
                'settings'     => $batch->settings,
                'created_at'   => optional($batch->created_at)->toIso8601String(),
                'counts'       => [
                    'total_rows'      => $batch->total_rows,
                    'valid_rows'      => $batch->valid_rows,
                    'row_error_count' => $batch->row_error_count,
                    'total_images'    => $batch->total_images,
                    'generated'       => $batch->generated_count,
                    'failed'          => $batch->failed_count,
                    'cancelled'       => $batch->cancelled_count,
                ],
            ],
            'images'     => $images,
            'row_errors' => $batch->row_errors ?? [],
        ];

        $jsonKey = $batch->storagePrefix() . '/manifest.json';
        Storage::disk('s3')->put($jsonKey, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // CSV twin for spreadsheet folk.
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['image_id', 'plant_code', 'plant_name', 'file', 'url', 'status', 'model', 'size', 'quality', 'error']);
        foreach ($images as $img) {
            fputcsv($csv, [
                $img['image_id'], $img['plant_code'], $img['plant_name'], $img['file'],
                $img['url'], $img['status'], $img['model'], $img['size'], $img['quality'], $img['error'],
            ]);
        }
        rewind($csv);
        Storage::disk('s3')->put($batch->storagePrefix() . '/manifest.csv', stream_get_contents($csv) ?: '');
        fclose($csv);

        return $jsonKey;
    }

    /* ── notification ─────────────────────────────────────────────────────── */

    private function notify(ImageBatch $batch): void
    {
        $to = $batch->notify_email;
        if (!$to) {
            return;
        }

        try {
            // SendGrid HTTPS when configured (Railway blackholes SMTP), else default.
            $mailer = config('mail.mailers.sendgrid.key') ? 'sendgrid' : config('mail.default');

            Mail::mailer($mailer)->to($to)->send(new \Marvel\Mail\ImageBatchCompleted($batch));
        } catch (\Throwable $e) {
            // Mail must never fail the packaging job.
            Log::warning('Image batch completion email failed', [
                'batch_id' => $batch->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
