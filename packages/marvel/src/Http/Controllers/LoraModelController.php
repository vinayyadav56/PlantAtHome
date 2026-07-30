<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Ai\ImageGenerationException;
use Marvel\Ai\ReplicateImageService;
use Marvel\Database\Models\AiLoraModel;
use ZipArchive;

/**
 * Admin-only (SUPER_ADMIN) Replicate LoRA fine-tuning: the admin picks 8–40
 * already-uploaded media images, we download + zip them, push the ZIP to the
 * public media disk, and launch an ostris/flux-dev-lora-trainer run into a
 * private destination model. GET show() lazily refreshes the training status
 * from Replicate; a succeeded run stores the version hash that
 * ai-images/instant then generates with (provider=replicate + lora_model_id).
 *
 * SECURITY: image_urls are fetched SERVER-side, so they are an SSRF surface —
 * every URL must be https on the app's own media host (derived from the s3
 * disk config, never hardcoded), and every download is capped at 10MB and
 * must answer an image/* content-type.
 */
class LoraModelController extends CoreController
{
    private const MIN_IMAGES        = 8;
    private const MAX_IMAGES        = 40;
    private const MAX_IMAGE_BYTES   = 10 * 1024 * 1024; // 10MB per training image
    private const REFRESHABLE       = [AiLoraModel::STATUS_PENDING, AiLoraModel::STATUS_TRAINING];

    /** Replicate training status → our status column. */
    private const STATUS_MAP = [
        'starting'   => AiLoraModel::STATUS_TRAINING,
        'queued'     => AiLoraModel::STATUS_TRAINING,
        'processing' => AiLoraModel::STATUS_TRAINING,
        'succeeded'  => AiLoraModel::STATUS_SUCCEEDED,
        'failed'     => AiLoraModel::STATUS_FAILED,
        'canceled'   => AiLoraModel::STATUS_CANCELED,
    ];

    /** Newest-first list (the admin UI shows in-flight trainings too). */
    public function index()
    {
        return AiLoraModel::orderByDesc('id')->get();
    }

    /**
     * Create + launch a training synchronously (admin tool — the download/zip
     * work is bounded by the 40-image × 10MB caps and the route throttle).
     */
    public function store(Request $request, ReplicateImageService $replicate)
    {
        $request->validate([
            'name'         => 'required|string|max:60',
            'trigger_word' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z0-9]*$/'],
            'image_urls'   => 'required|array|min:' . self::MIN_IMAGES . '|max:' . self::MAX_IMAGES,
            'image_urls.*' => 'required|string|max:2000',
            'captions'     => 'nullable|array',
            'captions.*'   => 'nullable|string|max:500',
            'steps'        => 'nullable|integer|min:100|max:3000',
            'lora_rank'    => 'nullable|integer|min:4|max:64',
        ]);

        $name        = (string) $request->input('name');
        $triggerWord = strtoupper((string) $request->input('trigger_word'));
        $imageUrls   = array_values((array) $request->input('image_urls'));
        $captions    = $request->filled('captions') ? array_values((array) $request->input('captions')) : [];
        $steps       = (int) ($request->input('steps') ?: 1000);
        $loraRank    = (int) ($request->input('lora_rank') ?: 16);

        if (!empty($captions) && count($captions) !== count($imageUrls)) {
            return response()->json(['message' => 'captions must have exactly one entry per image_url.'], 422);
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            return response()->json(['message' => 'name must contain at least one letter or digit.'], 422);
        }

        // SSRF guard: only https URLs on OUR media host (the s3/media disk the
        // instant pipeline serves from) may be fetched server-side.
        $allowedHost = $this->mediaHost();
        if ($allowedHost === '') {
            return response()->json(['message' => 'Media disk URL is not configured — cannot validate training image URLs.'], 422);
        }
        foreach ($imageUrls as $url) {
            if (!$this->isAllowedMediaUrl((string) $url, $allowedHost)) {
                return response()->json([
                    'message' => "Rejected image URL '" . mb_substr((string) $url, 0, 120) . "' — training images must be https URLs on {$allowedHost}.",
                ], 422);
            }
        }

        set_time_limit(300); // 40 downloads + zip + 2 Replicate calls, all bounded below

        try {
            $zip = $this->buildTrainingZip($imageUrls, $captions);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $zipKey = 'ai-lora/training/' . (string) Str::uuid() . '.zip';
        Storage::disk('s3')->put($zipKey, $zip);
        $zipUrl = Storage::disk('s3')->url($zipKey);

        $row = AiLoraModel::create([
            'name'         => $name,
            'trigger_word' => $triggerWord,
            // Filled with the real destination once ensureModel() succeeds.
            'replicate_model' => (string) config('services.replicate.owner', 'vinayyadav55') . '/' . $slug,
            'status'       => AiLoraModel::STATUS_PENDING,
            'steps'        => $steps,
            'lora_rank'    => $loraRank,
            'image_count'  => count($imageUrls),
            'settings'     => [
                'image_urls' => $imageUrls,
                'captions'   => $captions ?: null,
                'zip_key'    => $zipKey,
                'zip_url'    => $zipUrl,
            ],
            'requested_by' => optional($request->user())->id,
        ]);

        try {
            $destination = $replicate->ensureModel(
                (string) config('services.replicate.owner', 'vinayyadav55'),
                $slug,
            );
            $training = $replicate->createTraining(
                $destination,
                $zipUrl,
                $triggerWord,
                $steps,
                $loraRank,
                autocaption: empty($captions),
            );
        } catch (ImageGenerationException $e) {
            $row->update([
                'status' => AiLoraModel::STATUS_FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return response()->json([
                'message' => 'Replicate training could not be started: ' . $e->getMessage(),
                'model'   => $row->fresh(),
            ], 502);
        }

        $row->update([
            'replicate_model'       => $destination,
            'replicate_training_id' => $training['id'],
            'status'                => AiLoraModel::STATUS_TRAINING,
        ]);

        return response()->json([
            'message' => "LoRA training started ({$training['id']}) — poll this model until it succeeds.",
            'model'   => $row->fresh(),
        ], 201);
    }

    /**
     * Admin uploads their OWN training photos (the picker otherwise only
     * offers AI-generated instant images). Each file lands on the public
     * media disk at ai-lora/uploads/YYYY-MM/{uuid}.{ext}, so the returned
     * URLs are on the media host and pass store()'s SSRF allowlist
     * unchanged. Visibility is never set — the bucket policy handles
     * public read (same contract as the instant pipeline).
     */
    public function upload(Request $request)
    {
        $request->validate([
            'images'   => 'required|array|min:1|max:' . self::MAX_IMAGES,
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:' . ((int) config('image-batches.max_file_mb', 10) * 1024),
        ]);

        $month  = now()->format('Y-m');
        $images = [];

        foreach (array_values($request->file('images', [])) as $file) {
            // Client extension when it is one of ours, else derive from the
            // sniffed mime (a mimes-valid file can still be named "photo").
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                $ext = $this->extensionFor(strtolower((string) $file->getMimeType()));
            }

            $key = 'ai-lora/uploads/' . $month . '/' . (string) Str::uuid() . '.' . $ext;
            Storage::disk('s3')->put($key, (string) file_get_contents($file->getRealPath()));

            $images[] = [
                'url'  => Storage::disk('s3')->url($key),
                'name' => $file->getClientOriginalName(),
            ];
        }

        return response()->json(['images' => $images], 201);
    }

    /**
     * Show one model; while it is pending/training, refresh from Replicate and
     * persist the mapped status (succeeded parses output.version's hash).
     */
    public function show($id, ReplicateImageService $replicate)
    {
        $row = AiLoraModel::findOrFail($id);

        if (in_array($row->status, self::REFRESHABLE, true) && $row->replicate_training_id) {
            try {
                $this->applyTrainingState($row, $replicate->getTraining($row->replicate_training_id));
            } catch (\Throwable $e) {
                // A flaky poll must not break the page — serve the stored row.
                Log::warning('LoRA training refresh failed', ['id' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json($row->fresh());
    }

    /** Cancel a running training on Replicate, then locally. */
    public function cancel($id, ReplicateImageService $replicate)
    {
        $row = AiLoraModel::findOrFail($id);

        if (!in_array($row->status, self::REFRESHABLE, true) || !$row->replicate_training_id) {
            return response()->json(['message' => "Model '{$row->name}' is {$row->status} — nothing to cancel."], 409);
        }

        try {
            $replicate->cancelTraining($row->replicate_training_id);
        } catch (ImageGenerationException $e) {
            return response()->json(['message' => 'Replicate cancel failed: ' . $e->getMessage()], 502);
        }

        $row->update(['status' => AiLoraModel::STATUS_CANCELED]);

        return response()->json([
            'message' => "Training for '{$row->name}' canceled.",
            'model'   => $row->fresh(),
        ]);
    }

    /**
     * Delete the DB row only. Deliberately touches NOTHING remote: versions
     * on the Replicate destination model are harmless (and other rows may
     * reference the same destination), and the training zip stays on s3 as
     * the audit trail of what the model was trained on.
     */
    public function destroy($id)
    {
        $row = AiLoraModel::findOrFail($id);

        if (in_array($row->status, self::REFRESHABLE, true)) {
            return response()->json([
                'message' => "Model '{$row->name}' is {$row->status} — cancel the training first, then delete it.",
            ], 409);
        }

        $row->delete();

        return response()->json(['message' => "LoRA model '{$row->name}' deleted."]);
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    /** Map a Replicate training payload onto the row and persist. */
    private function applyTrainingState(AiLoraModel $row, array $training): void
    {
        $status = self::STATUS_MAP[(string) ($training['status'] ?? '')] ?? null;
        if ($status === null) {
            return; // unknown status — keep ours
        }

        $update = ['status' => $status];

        if ($status === AiLoraModel::STATUS_SUCCEEDED) {
            // output.version is "owner/name:versionhash" — the hash is what
            // POST /v1/predictions wants.
            $version = (string) data_get($training, 'output.version', '');
            if (str_contains($version, ':')) {
                $update['replicate_version'] = substr($version, strrpos($version, ':') + 1);
            } elseif ($version !== '') {
                $update['replicate_version'] = $version;
            }
        }

        if ($status === AiLoraModel::STATUS_FAILED) {
            $error           = $training['error'] ?? null;
            $update['error'] = mb_substr(is_string($error) && $error !== '' ? $error : 'Training failed on Replicate.', 0, 1000);
        }

        $row->update($update);
    }

    /**
     * Download every image and zip them as img_001.<ext> (+ img_001.txt when
     * captions were provided). Throws RuntimeException with a client-safe
     * message on any rejected download.
     *
     * @param string[] $imageUrls
     * @param string[] $captions
     * @return string raw zip bytes
     */
    private function buildTrainingZip(array $imageUrls, array $captions): string
    {
        $dir = storage_path('app/tmp/ai-lora');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $zipPath = $dir . '/' . uniqid('training_', true) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the training ZIP.');
        }

        try {
            foreach ($imageUrls as $i => $url) {
                $response = Http::timeout(30)->connectTimeout(10)->get($url);
                if ($response->failed()) {
                    throw new \RuntimeException(sprintf('Image %d could not be downloaded (HTTP %d).', $i + 1, $response->status()));
                }

                $contentType = strtolower((string) $response->header('Content-Type'));
                if (!str_starts_with($contentType, 'image/')) {
                    throw new \RuntimeException(sprintf("Image %d is not an image (content-type '%s').", $i + 1, $contentType ?: 'none'));
                }

                $bytes = $response->body();
                if ($bytes === '') {
                    throw new \RuntimeException(sprintf('Image %d was empty.', $i + 1));
                }
                if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                    throw new \RuntimeException(sprintf('Image %d exceeds the 10MB limit.', $i + 1));
                }

                $stem = sprintf('img_%03d', $i + 1);
                $zip->addFromString($stem . '.' . $this->extensionFor($contentType), $bytes);

                $caption = trim((string) ($captions[$i] ?? ''));
                if ($caption !== '') {
                    $zip->addFromString($stem . '.txt', $caption);
                }
            }

            $zip->close();

            return (string) file_get_contents($zipPath);
        } catch (\Throwable $e) {
            // ZipArchive discards an unclosed archive; surface the real error.
            @$zip->close();
            throw $e;
        } finally {
            @unlink($zipPath);
        }
    }

    private function extensionFor(string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
            str_contains($contentType, 'webp') => 'webp',
            default => 'png',
        };
    }

    /** Host of the public media/s3 disk URL — config-derived, never hardcoded. */
    private function mediaHost(): string
    {
        $host = strtolower((string) parse_url((string) config('filesystems.disks.s3.url'), PHP_URL_HOST));
        if ($host !== '') {
            return $host;
        }

        // AWS_URL is optional — the SDK still derives the public URL the
        // instant pipeline serves from (bucket.s3.region.amazonaws.com).
        try {
            return strtolower((string) parse_url(Storage::disk('s3')->url('probe'), PHP_URL_HOST));
        } catch (\Throwable) {
            return '';
        }
    }

    private function isAllowedMediaUrl(string $url, string $allowedHost): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === $allowedHost
            && !isset($parts['port']) // a non-standard port is not our media host
            && !isset($parts['user']) && !isset($parts['pass']);
    }
}
