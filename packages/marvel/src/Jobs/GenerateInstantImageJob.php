<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Ai\OpenAiImageClient;
use Marvel\Database\Models\InstantImage;

/**
 * One instant admin generation on the high-priority `images-instant` queue —
 * a preview never waits behind a multi-hour batch. No pacing (instants are
 * rare, human-triggered, and throttled at the route).
 */
class GenerateInstantImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 320; // one long OpenAI call + s3 upload

    // Not readonly — DB-queue deserialization.
    public int $instantId;

    public function __construct(int $instantId)
    {
        $this->instantId = $instantId;
        $this->onQueue((string) config('image-batches.instant_queue', 'images-instant'));
    }

    /** @return int[] */
    public function backoff(): array
    {
        return [15];
    }

    public function handle(OpenAiImageClient $client): void
    {
        // Atomic claim — a retried delivery of an already-finished row is a no-op.
        $claimed = InstantImage::where('id', $this->instantId)
            ->whereIn('status', [InstantImage::STATUS_PENDING, InstantImage::STATUS_PROCESSING])
            ->update(['status' => InstantImage::STATUS_PROCESSING]);
        if ($claimed === 0) {
            return;
        }

        $row = InstantImage::find($this->instantId);
        if (!$row) {
            return;
        }

        $settings   = (array) $row->settings;
        $model      = (string) ($settings['model'] ?? 'gpt-image-1');
        $size       = (string) ($settings['size'] ?? '1024x1024');
        $quality    = $settings['quality'] ?? null;
        $style      = $settings['style'] ?? null;
        $background = $settings['background'] ?? null;

        $modelConfig = (array) config("image-batches.models.{$model}", []);
        $prompt      = (string) $row->prompt;

        // Prompt-folded styles (gpt-image-1 has no style param).
        $styleSuffix = $style ? ($modelConfig['style_prompts'][$style] ?? null) : null;
        if ($styleSuffix) {
            $prompt = rtrim($prompt, ' .') . '. ' . $styleSuffix . '.';
        }
        $apiStyle = empty($modelConfig['style_prompts']) ? $style : null;

        $references = $this->downloadReferences($row);

        try {
            $useReferences = !empty($references) && !empty($modelConfig['supports_reference_images']);
            $image = $useReferences
                ? $client->edit($model, $prompt, $size, $quality, $background, $references)
                : $client->generate($model, $prompt, $size, $quality, $apiStyle, $background);

            $path = 'ai-instant/' . now()->format('Y-m') . '/instant_' . $row->id . '.png';
            // Never set visibility — bucket policy handles it.
            Storage::disk('s3')->put($path, $image['bytes']);

            $row->update([
                'status'         => InstantImage::STATUS_COMPLETED,
                's3_path'        => $path,
                'public_url'     => Storage::disk('s3')->url($path),
                'bytes'          => strlen($image['bytes']),
                'revised_prompt' => $image['revised_prompt'],
                'error'          => null,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => InstantImage::STATUS_FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);
        } finally {
            foreach ($references as $file) {
                @unlink($file['path']);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        InstantImage::where('id', $this->instantId)
            ->whereIn('status', [InstantImage::STATUS_PENDING, InstantImage::STATUS_PROCESSING])
            ->update([
                'status' => InstantImage::STATUS_FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);
        Log::warning('GenerateInstantImageJob failed', ['id' => $this->instantId, 'error' => $e->getMessage()]);
    }

    /** @return array<int, array{path: string, name: string}> */
    private function downloadReferences(InstantImage $row): array
    {
        $keys = (array) ($row->reference_images ?? []);
        if (empty($keys)) {
            return [];
        }

        $dir = storage_path('app/tmp/ai-instant/' . $row->id);
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
                Log::warning('Instant image reference download failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        return $files;
    }
}
