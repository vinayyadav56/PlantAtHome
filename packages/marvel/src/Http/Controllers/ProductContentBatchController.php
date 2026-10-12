<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;
use Marvel\Services\MissingPlantContent;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductContentBatchController extends CoreController
{
    /** Held while a missing-content run is being created (start or retry) — one active run at a time. */
    private const MISSING_RUN_LOCK = 'ai-content:missing-run';

    /** Dropdown options + limits for the bulk-generate UI (config-driven). */
    public function capabilities()
    {
        return response()->json([
            'generate'       => ['description', 'categories'],
            'lengths'        => ['short', 'medium', 'long'],
            'tones'          => ['professional', 'friendly', 'playful', 'luxury', 'minimal', 'technical'],
            'languages'      => ['en'],
            'category_modes' => ['append', 'replace'],
            'limits'         => [
                'max_rows'           => (int) config('content-batches.max_rows', 2000),
                'cost_per_product'   => (float) config('content-batches.cost_per_product', 0.002),
                'max_estimated_cost' => (float) config('content-batches.max_estimated_cost', 50),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $limit = min(100, max(1, (int) ($request->limit ?? 15)));

        return ProductContentBatch::query()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($limit);
    }

    public function show($id)
    {
        $batch = ProductContentBatch::findOrFail($id);

        return response()->json($batch->toArray() + ['summary' => $batch->summary()]);
    }

    /** Rows, optionally filtered by status (?status=failed,needs_review), with the product slug for edit links. */
    public function rows(Request $request, $id)
    {
        $batch    = ProductContentBatch::findOrFail($id);
        $limit    = min(200, max(1, (int) ($request->limit ?? 30)));
        $statuses = array_values(array_intersect(
            array_map('trim', explode(',', implode(',', (array) $request->query('status', '')))),
            [
                ProductContentJob::STATUS_PENDING, ProductContentJob::STATUS_RETRYING, ProductContentJob::STATUS_PROCESSING,
                ProductContentJob::STATUS_COMPLETED, ProductContentJob::STATUS_NEEDS_REVIEW, ProductContentJob::STATUS_FAILED,
                ProductContentJob::STATUS_SKIPPED,
            ]
        ));

        return ProductContentJob::query()
            ->leftJoin('products', 'products.id', '=', 'product_content_jobs.product_id')
            ->where('product_content_jobs.batch_id', $batch->id)
            ->when($statuses, fn ($q) => $q->whereIn('product_content_jobs.status', $statuses))
            ->orderBy('product_content_jobs.id')
            ->select('product_content_jobs.*', 'products.slug as product_slug')
            ->paginate($limit);
    }

    /** Stats strip for "Generate Missing AI Content" (no AI calls). */
    public function missingStats(MissingPlantContent $content)
    {
        return response()->json($content->stats());
    }

    /** Plants left for a human (flagged in any run), with the reasons and edit-link slugs. */
    public function missingReview(MissingPlantContent $content)
    {
        return response()->json($content->reviewList());
    }

    /**
     * Start a missing-content run over the whole plant catalogue. The server
     * picks the plants (client product ids are ignored); `limit` serves the
     * "try 25 first" trial. Capped by max_rows and the cost cap.
     */
    public function startMissing(Request $request, MissingPlantContent $content)
    {
        $max  = (int) config('content-batches.max_rows', 2000);
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', "max:{$max}"]]);

        // Same switch Marvel\Ai\Base enforces: refuse up front rather than queue a run
        // the job would fail at once.
        if (!data_get(\Marvel\Database\Models\Settings::first(), 'options.useAi', false)) {
            return response()->json(['message' => 'AI is turned off in Settings. Turn it on to generate content.'], 422);
        }

        return $this->withMissingRunLock(function () use ($request, $content, $data, $max) {
            $costPer = (float) config('content-batches.cost_per_product', 0.002);
            $byCost  = $costPer > 0 ? (int) floor((float) config('content-batches.max_estimated_cost', 50) / $costPer + 1e-9) : $max;
            $cap     = max(1, min((int) ($data['limit'] ?? $max), $max, $byCost));

            $eligible = 0;
            $queue    = [];
            $content->eachPlant(function ($plant, array $missing, array $todo) use (&$eligible, &$queue, $cap) {
                if ($todo === []) {
                    return;
                }
                $eligible++;
                if (count($queue) < $cap) {
                    $queue[] = [(int) $plant->id, (string) $plant->name, $todo];
                }
            });

            if ($eligible === 0) {
                return response()->json(['message' => 'All plants already have the required content. Nothing needs to be generated.'], 422);
            }

            $byField = array_fill_keys(MissingPlantContent::FIELDS, 0);
            foreach ($queue as [, , $todo]) {
                foreach ($todo as $field) {
                    $byField[$field]++;
                }
            }

            $batch = DB::transaction(function () use ($request, $queue, $eligible, $byField, $costPer) {
                $batch = ProductContentBatch::create([
                    'created_by'     => optional($request->user())->id,
                    'options'        => [
                        'mode'                   => ProductContentBatch::MODE_MISSING,
                        'fields'                 => MissingPlantContent::FIELDS,
                        'eligible'               => $eligible,
                        'queued'                 => count($queue),
                        'fields_missing_initial' => array_sum($byField),
                        'missing_by_field'       => $byField,
                    ],
                    'status'         => ProductContentBatch::STATUS_PENDING,
                    'total_rows'     => count($queue),
                    'estimated_cost' => round(count($queue) * $costPer, 2),
                ]);

                $now = now();
                foreach (array_chunk($queue, 500) as $chunk) {
                    ProductContentJob::insert(array_map(fn ($q) => [
                        'batch_id'       => $batch->id,
                        'product_id'     => $q[0],
                        'product_name'   => $q[1],
                        'status'         => ProductContentJob::STATUS_PENDING,
                        'attempts'       => 0,
                        'fields_missing' => count($q[2]),
                        'changes'        => json_encode(['missing_before' => $q[2]]),
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ], $chunk));
                }

                return $batch;
            });

            GenerateProductContentBatchJob::dispatch($batch->id);

            $batch = $batch->fresh();

            return response()->json($batch->toArray() + [
                'summary'  => $batch->summary(),
                'eligible' => $eligible,
                'queued'   => count($queue),
            ], 201);
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'generate'      => ['nullable', 'array'],
            'generate.*'    => ['in:description,categories'],
            'length'        => ['nullable', 'string', 'max:20'],
            'tone'          => ['nullable', 'string', 'max:40'],
            'language'      => ['nullable', 'string', 'max:16'],
            'category_mode' => ['nullable', 'in:append,replace'],
            'only_missing'  => ['nullable', 'boolean'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $max = (int) config('content-batches.max_rows', 2000);
        if (count($ids) > $max) {
            throw new HttpException(422, "Too many products — the limit is {$max} per run.");
        }

        // Snapshot valid products (base name for display).
        $products = Product::whereIn('id', $ids)->get(['id', 'name', 'type_id']);
        if ($products->isEmpty()) {
            throw new HttpException(422, 'No valid products were selected.');
        }

        $generate = !empty($data['generate']) ? array_values(array_unique($data['generate'])) : ['description', 'categories'];
        $options  = [
            'generate'      => $generate,
            'length'        => $data['length'] ?? 'medium',
            'tone'          => $data['tone'] ?? 'professional',
            'language'      => $data['language'] ?? 'en',
            'category_mode' => $data['category_mode'] ?? 'append',
            'only_missing'  => (bool) ($data['only_missing'] ?? false),
        ];

        $estimatedCost = round($products->count() * (float) config('content-batches.cost_per_product', 0.002), 2);
        $costCap       = (float) config('content-batches.max_estimated_cost', 50);
        if ($estimatedCost > $costCap) {
            throw new HttpException(422, "Estimated cost \${$estimatedCost} exceeds the \${$costCap} cap — select fewer products.");
        }

        $batch = DB::transaction(function () use ($products, $options, $estimatedCost, $request) {
            $batch = ProductContentBatch::create([
                'created_by'     => optional($request->user())->id,
                'options'        => $options,
                'status'         => ProductContentBatch::STATUS_PENDING,
                'total_rows'     => $products->count(),
                'estimated_cost' => $estimatedCost,
            ]);

            $now  = now();
            $rows = $products->map(fn ($p) => [
                'batch_id'     => $batch->id,
                'product_id'   => $p->id,
                'product_name' => $p->name,
                'status'       => ProductContentJob::STATUS_PENDING,
                'attempts'     => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                ProductContentJob::insert($chunk);
            }

            return $batch;
        });

        GenerateProductContentBatchJob::dispatch($batch->id);

        return response()->json($batch->fresh(), 201);
    }

    public function cancel($id)
    {
        $batch = ProductContentBatch::findOrFail($id);
        if (!$batch->isActive()) {
            throw new HttpException(409, 'This run is not active.');
        }
        $batch->update([
            'status'       => ProductContentBatch::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
        ProductContentJob::where('batch_id', $batch->id)
            ->whereIn('status', [ProductContentJob::STATUS_PENDING, ProductContentJob::STATUS_RETRYING])
            ->update(['status' => ProductContentJob::STATUS_SKIPPED]);

        return response()->json($batch->fresh());
    }

    public function retryFailed($id)
    {
        $batch = ProductContentBatch::findOrFail($id);
        if (!in_array($batch->status, ProductContentBatch::RETRYABLE_STATUSES, true)) {
            throw new HttpException(409, 'Only completed-with-errors or failed runs can be retried.');
        }

        // Re-activating a missing-content run must not overlap another one.
        return $batch->isMissingMode()
            ? $this->withMissingRunLock(fn () => $this->resetFailedRows($batch))
            : $this->resetFailedRows($batch);
    }

    private function resetFailedRows(ProductContentBatch $batch)
    {
        $reset = ProductContentJob::where('batch_id', $batch->id)
            ->where('status', ProductContentJob::STATUS_FAILED)
            ->update(['status' => ProductContentJob::STATUS_PENDING, 'attempts' => 0, 'last_error' => null]);
        if ($reset < 1) {
            throw new HttpException(409, 'There are no failed rows to retry.');
        }

        // Recompute counters from the actual row states — idempotent, so two
        // concurrent retries converge instead of double-subtracting (the batch is
        // terminal here, so no worker is mutating rows concurrently).
        $counts = ProductContentJob::where('batch_id', $batch->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        // Everything that isn't open is processed (needs_review included).
        $processed = (int) $counts->sum() - (int) $counts->only(ProductContentJob::OPEN_STATUSES)->sum();

        $batch->update([
            'processed_count' => $processed,
            'failed_count'    => (int) ($counts[ProductContentJob::STATUS_FAILED] ?? 0),
            'row_errors'      => null,
            'completed_at'    => null,
            'status'          => ProductContentBatch::STATUS_PROCESSING,
        ]);

        GenerateProductContentBatchJob::dispatch($batch->id);

        return response()->json($batch->fresh());
    }

    /**
     * Run $fn while holding the missing-run lock and while no other
     * missing-content run is active; otherwise 409 (with the active run's id,
     * so the admin panel can attach to it).
     */
    private function withMissingRunLock(callable $fn)
    {
        $lock = Cache::lock(self::MISSING_RUN_LOCK, 120);
        if (!$lock->get()) {
            return response()->json(['message' => 'A missing-content run is being started.'], 409);
        }

        try {
            if ($active = ProductContentBatch::activeMissingRun()) {
                return response()->json([
                    'message'  => 'A missing-content run is already in progress.',
                    'batch_id' => $active->id,
                ], 409);
            }

            return $fn();
        } finally {
            $lock->release();
        }
    }
}
