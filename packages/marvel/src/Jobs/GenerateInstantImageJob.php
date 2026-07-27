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
use Marvel\Ai\ReplicateImageService;
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

        $settings = (array) $row->settings;

        // Second provider: Replicate (Flux). Same claim, same storage path,
        // same terminal statuses — only the generation call differs.
        if (($settings['provider'] ?? 'openai') === 'replicate') {
            $this->handleReplicate($row, $settings);

            return;
        }

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

    /**
     * Replicate (Flux) generation — mirrors the OpenAI success/failure
     * conventions exactly: same S3 key scheme (`ai-instant/YYYY-MM/instant_{id}.png`),
     * same row fields, same truncated error persistence. Flux ignores
     * reference images (text-to-image only).
     */
    private function handleReplicate(InstantImage $row, array $settings): void
    {
        try {
            $service = app(ReplicateImageService::class);
            $prompt  = (string) $row->prompt;
            $options = [
                'aspect_ratio'        => $settings['aspect_ratio'] ?? '1:1',
                'guidance'            => $settings['guidance'] ?? null,
                'num_inference_steps' => $settings['steps'] ?? null,
                'seed'                => $settings['seed'] ?? null,
            ];

            // Fine-tuned style: generate on the trained LoRA version, and make
            // sure the trigger word leads the prompt (that's what activates
            // the trained concept) unless the admin already typed it.
            $loraVersion = (string) ($settings['lora_version'] ?? '');
            if ($loraVersion !== '') {
                $trigger = (string) ($settings['trigger_word'] ?? '');
                if ($trigger !== '' && mb_stripos($prompt, $trigger) === false) {
                    $prompt = $trigger . ', ' . $prompt;
                }
                $image = $service->generateWithVersion($loraVersion, $prompt, $options);
            } else {
                $image = $service->generate($prompt, $options);
            }

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
