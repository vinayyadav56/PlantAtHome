<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductContentBatchController extends CoreController
{
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
        return ProductContentBatch::findOrFail($id);
    }

    public function rows(Request $request, $id)
    {
        $batch = ProductContentBatch::findOrFail($id);
        $limit = min(200, max(1, (int) ($request->limit ?? 30)));

        return $batch->jobs()->orderBy('id')->paginate($limit);
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
        $processed = (int) (($counts[ProductContentJob::STATUS_COMPLETED] ?? 0)
            + ($counts[ProductContentJob::STATUS_SKIPPED] ?? 0)
            + ($counts[ProductContentJob::STATUS_FAILED] ?? 0));

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
}
