<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\AiLoraModel;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Database\Models\InstantImage;
use Marvel\Imports\ImageBatchSheetImport;
use Marvel\Imports\RowLimitExceededException;
use Marvel\Ai\ReplicateImageService;
use Marvel\Jobs\GenerateImageBatchJob;
use Marvel\Jobs\GenerateInstantImageJob;

/**
 * Admin-only (SUPER_ADMIN) background AI image generation: Excel upload →
 * queued generation → progress → ZIP download center. Every option the UI
 * shows comes from GET capabilities (config-driven — nothing hardcoded).
 */
class ImageBatchController extends CoreController
{
    /** Option lists + limits for the admin UI — a straight config dump. */
    public function capabilities()
    {
        $models = [];
        foreach ((array) config('image-batches.models', []) as $id => $model) {
            $models[] = [
                'id'                        => $id,
                'label'                     => $model['label'] ?? $id,
                'sizes'                     => array_values($model['sizes'] ?? []),
                'qualities'                 => array_values($model['qualities'] ?? []),
                'styles'                    => array_values($model['styles'] ?? []),
                'backgrounds'               => array_values($model['backgrounds'] ?? []),
                'supports_reference_images' => (bool) ($model['supports_reference_images'] ?? false),
                'cost_per_image'            => $model['cost_per_image'] ?? [],
            ];
        }

        return response()->json([
            'models' => $models,
            // Instant-generator provider matrix: which backends are usable
            // (env-token-gated) + the Replicate/Flux option lists.
            'providers' => [
                'openai' => [
                    'configured' => (bool) config('shop.openai.secret_Key'),
                ],
                'replicate' => [
                    'configured'    => (bool) config('services.replicate.api_token'),
                    'model'         => (string) config('services.replicate.model', 'black-forest-labs/flux-dev'),
                    'aspect_ratios' => ReplicateImageService::ASPECT_RATIOS,
                    // Fine-tuned styles — ALL rows (the UI shows training
                    // progress too). DB only, never a Replicate call here.
                    // Table-guarded: capabilities must survive a pre-migrate boot.
                    'lora'          => [
                        'models' => Schema::hasTable('ai_lora_models')
                            ? AiLoraModel::orderByDesc('id')->get()->map(fn ($m) => [
                                'id'           => $m->id,
                                'name'         => $m->name,
                                'trigger_word' => $m->trigger_word,
                                'status'       => $m->status,
                                'ready'        => $m->isReady(),
                            ])->values()->all()
                            : [],
                    ],
                ],
            ],
            'limits' => [
                'max_rows'             => (int) config('image-batches.max_rows'),
                'max_images_per_plant' => (int) config('image-batches.max_images_per_plant'),
                'max_reference_images' => (int) config('image-batches.max_reference_images'),
                'max_file_mb'          => (int) config('image-batches.max_file_mb'),
                'rate_per_minute'      => (float) config('image-batches.rate_per_minute'),
                'retention_days'       => (int) config('image-batches.retention_days'),
                'max_estimated_cost'   => (float) config('image-batches.max_estimated_cost'),
            ],
        ]);
    }

    /** Create a batch: validate settings against config, parse the sheet, dispatch. */
    public function store(Request $request)
    {
        $models = (array) config('image-batches.models', []);

        // Provider: default openai (unchanged behavior for every existing
        // caller that never sends this field). lora_model_id only makes sense
        // on the replicate path, so it implies provider=replicate — but an
        // explicit conflicting provider is rejected rather than silently
        // overridden.
        $providerInput = $request->input('provider');
        $hasLora       = $request->filled('lora_model_id');
        if ($hasLora && $providerInput !== null && $providerInput !== 'replicate') {
            return response()->json(['message' => "lora_model_id requires provider 'replicate'."], 422);
        }
        $provider = ($providerInput === 'replicate' || $hasLora) ? 'replicate' : 'openai';

        $request->validate([
            'file'               => 'required|file',
            'provider'           => 'nullable|in:openai,replicate',
            'lora_model_id'      => 'nullable|integer|exists:ai_lora_models,id',
            // model/size/quality are OpenAI-shaped (catalogue-validated below);
            // Flux has no quality tiers and resolves its own model, so they're
            // only required on the openai path.
            'model'              => $provider === 'openai' ? 'required|string' : 'nullable|string',
            'size'               => $provider === 'openai' ? 'required|string' : 'nullable|string',
            'quality'            => $provider === 'openai' ? 'required|string' : 'nullable|string',
            'style'              => 'nullable|string',
            'background'         => 'nullable|string',
            // Replicate/Flux-only knobs — ignored on the OpenAI path (mirrors instantStore).
            'aspect_ratio'       => 'nullable|in:' . implode(',', ReplicateImageService::ASPECT_RATIOS),
            'guidance'           => 'nullable|numeric|min:0|max:20',
            'steps'              => 'nullable|integer|min:1|max:50',
            'seed'               => 'nullable|integer|min:0',
            'images_per_plant'   => 'required|integer|min:1|max:' . (int) config('image-batches.max_images_per_plant', 10),
            'notify_email'       => 'nullable|email',
            'output_folder'      => 'nullable|string|max:80',
            'file_structure'     => 'nullable|in:folders,flat',
            'reference_images'   => 'nullable|array|max:' . (int) config('image-batches.max_reference_images', 4),
            'reference_images.*' => 'file|mimes:png,jpg,jpeg,webp|max:' . ((int) config('image-batches.max_file_mb', 10) * 1024),
        ]);

        $references = [];
        if ($provider === 'replicate') {
            // Fine-tuned style: generate on the trained LoRA version instead
            // of the base flux model. Only a trained (ready) model may be referenced.
            $lora = null;
            if ($hasLora) {
                $lora = AiLoraModel::find((int) $request->input('lora_model_id'));
                if (!$lora || !$lora->isReady()) {
                    return response()->json([
                        'message' => 'That LoRA model is not ready yet — it must finish training (status succeeded) before it can generate.',
                    ], 422);
                }
            }

            // Flux is text-to-image only — reference images are silently
            // ignored here (never uploaded), same as instantStore's replicate branch.
            $settingsBase = [
                'provider'     => 'replicate',
                'model'        => $lora ? $lora->replicate_model : (string) config('services.replicate.model', 'black-forest-labs/flux-dev'),
                'aspect_ratio' => (string) ($request->input('aspect_ratio') ?: '1:1'),
            ];
            foreach (['guidance', 'steps', 'seed'] as $opt) {
                if ($request->filled($opt)) {
                    $settingsBase[$opt] = $request->input($opt);
                }
            }
            if ($lora) {
                $settingsBase['lora_model_id'] = $lora->id;
                $settingsBase['lora_version']  = $lora->replicate_version;
                $settingsBase['trigger_word']  = $lora->trigger_word;
            }

            $unitCost = (float) config('image-batches.replicate_cost_per_image', 0.04);
        } else {
            // Settings must exist in the capability matrix — the config is the contract.
            $modelId = (string) $request->input('model');
            $model   = $models[$modelId] ?? null;
            if (!$model) {
                return response()->json(['message' => "Unknown model '{$modelId}'."], 422);
            }
            $size       = (string) $request->input('size');
            $quality    = (string) $request->input('quality');
            $style      = $request->input('style') ?: null;
            $background = $request->input('background') ?: null;
            foreach ([
                ['size', $size, $model['sizes'] ?? []],
                ['quality', $quality, $model['qualities'] ?? []],
                ['style', $style, $model['styles'] ?? []],
                ['background', $background, $model['backgrounds'] ?? []],
            ] as [$field, $value, $allowed]) {
                if ($value !== null && !in_array($value, $allowed, true)) {
                    return response()->json(['message' => "Invalid {$field} '{$value}' for model '{$modelId}'."], 422);
                }
            }

            $references = $request->file('reference_images', []);
            if (!empty($references) && empty($model['supports_reference_images'])) {
                return response()->json(['message' => "Model '{$modelId}' does not support reference images."], 422);
            }

            $unitCost     = (float) (($model['cost_per_image'][$quality][$size] ?? 0));
            $settingsBase = [
                'provider'   => 'openai',
                'model'      => $modelId,
                'size'       => $size,
                'quality'    => $quality,
                'style'      => $style,
                'background' => $background,
            ];
        }

        $sheet = $request->file('file');
        $ext   = strtolower($sheet->getClientOriginalExtension() ?: 'xlsx');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            return response()->json(['message' => 'Only .xlsx, .xls or .csv files are allowed.'], 422);
        }

        $imagesPerPlant = (int) $request->input('images_per_plant');

        // Optional custom S3/ZIP directory. Sanitized to a slug and unique
        // among batches whose files still exist — two live batches sharing a
        // folder would overwrite each other's images.
        $outputFolder = null;
        if ($request->filled('output_folder')) {
            $outputFolder = strtolower(trim((string) preg_replace(
                ['/\s+/', '/[^A-Za-z0-9_\-]/', '/[-_]{2,}/'],
                ['-', '', '-'],
                (string) $request->input('output_folder')
            ), '-_'));
            if ($outputFolder === '') {
                $outputFolder = null;
            } elseif (
                ImageBatch::whereNull('files_pruned_at')
                    ->where('settings->output_folder', $outputFolder)
                    ->exists()
            ) {
                return response()->json([
                    'message' => "Output folder '{$outputFolder}' is already used by another batch — pick a different name.",
                ], 422);
            }
        }

        // Original sheet on the PRIVATE disk (audit/re-parse only).
        $originalPath = $sheet->storeAs('ai-image-batches', 'batch-' . time() . '-' . uniqid() . '.' . $ext, 'local');

        $batch = ImageBatch::create([
            'uploaded_by'      => optional($request->user())->id,
            'notify_email'     => $request->input('notify_email') ?: optional($request->user())->email,
            'original_file'    => $originalPath,
            'settings'         => array_merge($settingsBase, [
                'output_folder'  => $outputFolder,
                'file_structure' => $request->input('file_structure') === 'flat' ? 'flat' : 'folders',
            ]),
            'images_per_plant' => $imagesPerPlant,
            'status'           => ImageBatch::STATUS_PENDING,
        ]);

        // Reference images live on s3 (containers are ephemeral; a redeploy
        // mid-batch must not lose them).
        $refKeys = [];
        foreach (array_values($references) as $i => $ref) {
            $refExt = strtolower($ref->getClientOriginalExtension() ?: 'png');
            $key    = $batch->storagePrefix() . '/_reference/ref_' . ($i + 1) . '.' . $refExt;
            Storage::disk('s3')->put($key, file_get_contents($ref->getRealPath()));
            $refKeys[] = $key;
        }
        if ($refKeys) {
            $batch->update(['reference_images' => $refKeys]);
        }

        $import = new ImageBatchSheetImport(
            $batch->id,
            $imagesPerPlant,
            (int) config('image-batches.max_rows', 2000),
        );

        try {
            Excel::import($import, $sheet);
        } catch (RowLimitExceededException $e) {
            $this->discardBatch($batch, $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->discardBatch($batch, 'Could not read the sheet: ' . $e->getMessage());

            return response()->json(['message' => 'Could not read the sheet.', 'error' => $e->getMessage()], 422);
        }

        if ($import->rowCount === 0) {
            $this->discardBatch($batch, 'No valid rows found.');

            return response()->json([
                'message' => 'No valid rows found — the sheet needs plant_code, plant_name and prompt columns.',
                'errors'  => $import->errors,
            ], 422);
        }

        $totalImages   = $import->rowCount * $imagesPerPlant;
        $estimatedCost = round($totalImages * $unitCost, 4);
        $costCap       = (float) config('image-batches.max_estimated_cost', 200);

        if ($costCap > 0 && $estimatedCost > $costCap) {
            $this->discardBatch($batch, "Estimated cost \${$estimatedCost} exceeds the \${$costCap} cap.");

            return response()->json([
                'message'        => sprintf(
                    'Estimated cost $%.2f exceeds the $%.2f per-batch cap. Reduce rows, images per plant, or quality.',
                    $estimatedCost,
                    $costCap
                ),
                'estimated_cost' => $estimatedCost,
                'max_cost'       => $costCap,
                'total_images'   => $totalImages,
            ], 422);
        }

        $batch->update([
            'total_rows'      => $import->rowCount + $import->errorCount,
            'valid_rows'      => $import->rowCount,
            'row_error_count' => $import->errorCount,
            'row_errors'      => $import->errors,
            'total_images'    => $totalImages,
            'estimated_cost'  => $estimatedCost,
        ]);

        GenerateImageBatchJob::dispatch($batch->id);

        return response()->json([
            'message' => sprintf(
                'Batch %s queued: %d plants × %d image(s) (est. $%.2f).%s',
                $batch->display_id,
                $import->rowCount,
                $imagesPerPlant,
                $estimatedCost,
                $import->errorCount ? " {$import->errorCount} row(s) skipped." : ''
            ),
            'batch' => $batch->fresh(),
        ], 201);
    }

    /** Paginated batch history (audit + download center). */
    public function index(Request $request)
    {
        $limit = (int) ($request->limit ?? 15);
        $query = ImageBatch::with('uploader:id,name,email')->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $query->paginate($limit);
    }

    public function show($id)
    {
        $batch = ImageBatch::with('uploader:id,name,email')->findOrFail($id);

        return response()->json($this->withUrls($batch));
    }

    /** Paginated per-plant rows with their image results. */
    public function rows(Request $request, $id)
    {
        $batch = ImageBatch::findOrFail($id);
        $limit = (int) ($request->limit ?? 25);

        $query = ImageGenerationJob::where('batch_id', $batch->id)
            ->with(['results' => fn ($q) => $q->orderBy('image_index')])
            ->orderBy('row_number');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $query->paginate($limit);
    }

    /** Stop a running batch. Rows not yet started are cancelled; in-flight finishes its current image. */
    public function cancel($id)
    {
        $batch = ImageBatch::findOrFail($id);
        if (!in_array($batch->status, [ImageBatch::STATUS_PENDING, ImageBatch::STATUS_PROCESSING], true)) {
            return response()->json(['message' => "Batch {$batch->display_id} is {$batch->status} — nothing to cancel."], 409);
        }

        DB::transaction(function () use ($batch) {
            $remaining = (int) ImageGenerationJob::where('batch_id', $batch->id)
                ->whereIn('status', ImageGenerationJob::CLAIMABLE_STATUSES)
                ->sum(DB::raw('images_requested - images_completed'));

            ImageGenerationJob::where('batch_id', $batch->id)
                ->whereIn('status', ImageGenerationJob::CLAIMABLE_STATUSES)
                ->update(['status' => ImageGenerationJob::STATUS_CANCELLED]);

            ImageBatch::where('id', $batch->id)->update([
                'status'       => ImageBatch::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
            if ($remaining > 0) {
                ImageBatch::where('id', $batch->id)->increment('cancelled_count', $remaining);
            }
        });

        return response()->json([
            'message' => "Batch {$batch->display_id} cancelled.",
            'batch'   => $batch->fresh(),
        ]);
    }

    /** Regenerate only the failed image slots of a finished batch. */
    public function retryFailed($id)
    {
        $batch = ImageBatch::findOrFail($id);
        if (!in_array($batch->status, ImageBatch::RETRYABLE_STATUSES, true)) {
            return response()->json(['message' => "Batch {$batch->display_id} is {$batch->status} — retry applies to failed batches only."], 409);
        }

        $resettable = ImageGenerationJob::where('batch_id', $batch->id)
            ->whereIn('status', ImageGenerationJob::RETRYABLE_STATUSES);
        $failedImages = (int) $resettable->clone()->sum('images_failed');

        if ($failedImages === 0 && !$batch->zip_error) {
            return response()->json(['message' => 'Nothing to retry — no failed images recorded.'], 409);
        }

        DB::transaction(function () use ($batch, $resettable, $failedImages) {
            $resettable->update([
                'status'        => ImageGenerationJob::STATUS_PENDING,
                'attempts'      => 0,
                'images_failed' => 0,
                'claimed_at'    => null,
            ]);

            ImageBatch::where('id', $batch->id)->update([
                'status'       => ImageBatch::STATUS_PROCESSING,
                'failed_count' => DB::raw('CASE WHEN failed_count >= ' . $failedImages . ' THEN failed_count - ' . $failedImages . ' ELSE 0 END'),
                'completed_at' => null,
                'packaged_at'  => null,
                'zip_error'    => null,
            ]);
        });

        GenerateImageBatchJob::dispatch($batch->id);

        return response()->json([
            'message' => "Batch {$batch->display_id}: retrying {$failedImages} failed image(s).",
            'batch'   => $batch->fresh(),
        ]);
    }

    /** Download center payload: public part URLs + manifest. */
    public function download($id)
    {
        $batch = ImageBatch::findOrFail($id);
        $parts = (array) ($batch->zip_paths ?? []);
        if (empty($parts)) {
            return response()->json(['message' => 'No ZIP available for this batch yet.'], 404);
        }

        return response()->json([
            'batch_id' => $batch->display_id,
            'parts'    => array_map(fn ($p) => [
                'part'  => $p['part'],
                'url'   => Storage::disk('s3')->url($p['path']),
                'bytes' => $p['bytes'] ?? null,
            ], $parts),
            'manifest_url' => $batch->manifest_path ? Storage::disk('s3')->url($batch->manifest_path) : null,
        ]);
    }

    /** Manual prune: delete the files now, keep the audit row. */
    public function destroy($id)
    {
        $batch = ImageBatch::findOrFail($id);
        if ($batch->isActive()) {
            return response()->json(['message' => 'Cancel the batch before deleting its files.'], 409);
        }

        Storage::disk('s3')->deleteDirectory($batch->storagePrefix());
        if ($batch->original_file) {
            Storage::disk('local')->delete($batch->original_file);
        }
        $batch->update(['files_pruned_at' => now()]);

        return response()->json(['message' => "Batch {$batch->display_id} files deleted (audit record kept)."]);
    }

    /* ── instant generation (old image-service flow, consolidated) ────────── */

    /**
     * Queue one instant preview generation. Deliberately LENIENT on options
     * (the legacy UI sent DALL·E-era values): anything not in the model's
     * capability list falls back to a sane default instead of 422ing.
     */
    public function instantStore(Request $request)
    {
        $request->validate([
            'prompt'             => 'required|string|max:4000',
            'provider'           => 'nullable|in:openai,replicate',
            // Replicate/Flux-only knobs — ignored on the OpenAI path.
            'aspect_ratio'       => 'nullable|in:' . implode(',', ReplicateImageService::ASPECT_RATIOS),
            'guidance'           => 'nullable|numeric|min:0|max:20',
            'steps'              => 'nullable|integer|min:1|max:50',
            'seed'               => 'nullable|integer|min:0',
            'lora_model_id'      => 'nullable|integer|exists:ai_lora_models,id',
            'reference_images'   => 'nullable|array|max:' . (int) config('image-batches.max_reference_images', 4),
            'reference_images.*' => 'file|mimes:png,jpg,jpeg,webp|max:' . ((int) config('image-batches.max_file_mb', 10) * 1024),
        ]);

        // Second provider: Replicate (Flux). Provider + its options ride the
        // existing free-form `settings` json column — no schema change. Flux is
        // text-to-image here, so reference images are deliberately not uploaded.
        if ($request->input('provider') === 'replicate') {
            $settings = [
                'provider'     => 'replicate',
                'model'        => (string) config('services.replicate.model', 'black-forest-labs/flux-dev'),
                'aspect_ratio' => (string) ($request->input('aspect_ratio') ?: '1:1'),
            ];
            foreach (['guidance', 'steps', 'seed'] as $opt) {
                if ($request->filled($opt)) {
                    $settings[$opt] = $request->input($opt);
                }
            }

            // Fine-tuned style: generate on the trained LoRA version instead
            // of the base flux model. Only a trained model may be referenced.
            if ($request->filled('lora_model_id')) {
                $lora = AiLoraModel::find((int) $request->input('lora_model_id'));
                if (!$lora || !$lora->isReady()) {
                    return response()->json([
                        'message' => 'That LoRA model is not ready yet — it must finish training (status succeeded) before it can generate.',
                    ], 422);
                }
                $settings['lora_model_id'] = $lora->id;
                $settings['lora_version']  = $lora->replicate_version;
                $settings['trigger_word']  = $lora->trigger_word;
                $settings['model']         = $lora->replicate_model;
            }

            $row = InstantImage::create([
                'requested_by' => optional($request->user())->id,
                'prompt'       => (string) $request->input('prompt'),
                'settings'     => $settings,
                'status'       => InstantImage::STATUS_PENDING,
            ]);

            GenerateInstantImageJob::dispatch($row->id);

            return response()->json(['id' => $row->id, 'status' => $row->status, 'provider' => 'replicate'], 201);
        }

        $models  = (array) config('image-batches.models', []);
        $modelId = (string) $request->input('model', 'gpt-image-1');
        if (!isset($models[$modelId])) {
            $modelId = (string) array_key_first($models);
        }
        $model = $models[$modelId];

        $pick = function (?string $value, array $allowed, ?string $fallback) {
            return ($value !== null && in_array($value, $allowed, true)) ? $value : $fallback;
        };

        // Accept both `size` and the legacy `resolution` param name.
        $size    = $pick($request->input('size', $request->input('resolution')), $model['sizes'] ?? [], ($model['sizes'] ?? [])[0] ?? '1024x1024');
        $quality = $pick($request->input('quality'), $model['qualities'] ?? [], in_array('high', $model['qualities'] ?? [], true) ? 'high' : (($model['qualities'] ?? [])[0] ?? null));
        $style   = $pick($request->input('style'), $model['styles'] ?? [], null);
        $bg      = $pick($request->input('background'), $model['backgrounds'] ?? [], null);

        $row = InstantImage::create([
            'requested_by' => optional($request->user())->id,
            'prompt'       => (string) $request->input('prompt'),
            'settings'     => [
                'provider'   => 'openai',
                'model'      => $modelId,
                'size'       => $size,
                'quality'    => $quality,
                'style'      => $style,
                'background' => $bg,
            ],
            'status'       => InstantImage::STATUS_PENDING,
        ]);

        $references = $request->file('reference_images', []);
        if (!empty($references) && !empty($model['supports_reference_images'])) {
            $keys = [];
            foreach (array_values($references) as $i => $ref) {
                $ext = strtolower($ref->getClientOriginalExtension() ?: 'png');
                $key = 'ai-instant/' . now()->format('Y-m') . '/instant_' . $row->id . '/_reference/ref_' . ($i + 1) . '.' . $ext;
                Storage::disk('s3')->put($key, file_get_contents($ref->getRealPath()));
                $keys[] = $key;
            }
            $row->update(['reference_images' => $keys]);
        }

        GenerateInstantImageJob::dispatch($row->id);

        return response()->json(['id' => $row->id, 'status' => $row->status, 'provider' => 'openai'], 201);
    }

    public function instantShow($id)
    {
        $row = InstantImage::findOrFail($id);

        return response()->json([
            'id'             => $row->id,
            'status'         => $row->status,
            'url'            => $row->public_url,
            'revised_prompt' => $row->revised_prompt,
            'error'          => $row->error,
            'provider'       => $row->settings['provider'] ?? 'openai',
            'model'          => $row->settings['model'] ?? null,
            'created_at'     => optional($row->created_at)->toIso8601String(),
        ]);
    }

    /** Recent instant generations (history strip in the admin). */
    public function instantIndex(Request $request)
    {
        $limit = min(50, (int) ($request->limit ?? 12));

        return InstantImage::orderByDesc('id')->limit($limit)->get()->map(fn ($row) => [
            'id'         => $row->id,
            'status'     => $row->status,
            'url'        => $row->public_url,
            'prompt'     => mb_substr($row->prompt, 0, 160),
            'provider'   => $row->settings['provider'] ?? 'openai',
            'created_at' => optional($row->created_at)->toIso8601String(),
        ]);
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    private function withUrls(ImageBatch $batch): array
    {
        $data             = $batch->toArray();
        $data['zip_urls'] = array_map(fn ($p) => [
            'part'  => $p['part'],
            'url'   => Storage::disk('s3')->url($p['path']),
            'bytes' => $p['bytes'] ?? null,
        ], (array) ($batch->zip_paths ?? []));
        $data['manifest_url'] = $batch->manifest_path ? Storage::disk('s3')->url($batch->manifest_path) : null;

        return $data;
    }

    /** A rejected upload leaves a failed audit row (no job rows, no queue work). */
    private function discardBatch(ImageBatch $batch, string $reason): void
    {
        ImageGenerationJob::where('batch_id', $batch->id)->delete();
        $batch->update([
            'status'     => ImageBatch::STATUS_FAILED,
            'row_errors' => [['line' => 0, 'error' => $reason]],
        ]);
    }
}
