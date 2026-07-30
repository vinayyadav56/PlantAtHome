<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;

/**
 * Self-re-dispatching claim-loop worker for one bulk AI content run — one live
 * queue job per batch, not one per product. Idempotent by atomic row claiming;
 * a crash/timeout resumes at the unfinished tail. Each invocation caps its work
 * to stay under the queue timeout and re-dispatches the remainder.
 */
class GenerateProductContentBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    // Not readonly: DB-queue jobs are restored via reflection and readonly props
    // break deserialization.
    public int $batchId;

    public function __construct(int $batchId)
    {
        $this->batchId = $batchId;
        $this->onQueue((string) config('content-batches.queue', 'content'));
    }

    /** @return int[] per-attempt backoff seconds */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $batch = ProductContentBatch::find($this->batchId);
        if (!$batch || !in_array($batch->status, [ProductContentBatch::STATUS_PENDING, ProductContentBatch::STATUS_PROCESSING], true)) {
            return; // cancelled / already done / gone
        }

        // Resolve the AI provider once. Marvel\Ai\Base throws if useAi is off —
        // fail the whole batch cleanly rather than churn every row.
        try {
            $ai = app('ai');
        } catch (\Throwable $e) {
            $this->recordRowError($batch, 0, 'AI is disabled or misconfigured: ' . mb_substr($e->getMessage(), 0, 200));
            $batch->update(['status' => ProductContentBatch::STATUS_FAILED, 'completed_at' => now()]);
            return;
        }

        // Atomically claim the batch — abort if a cancel/terminal status landed
        // since we loaded it above (an unguarded update by primary key would
        // resurrect a cancelled batch back to processing).
        $claimed = ProductContentBatch::where('id', $batch->id)
            ->whereIn('status', [ProductContentBatch::STATUS_PENDING, ProductContentBatch::STATUS_PROCESSING])
            ->update([
                'status'             => ProductContentBatch::STATUS_PROCESSING,
                'last_dispatched_at' => now(),
            ]);
        if (!$claimed) {
            return; // cancelled or already finalized in the meantime
        }
        $batch->refresh();

        $interval  = 60.0 / max(0.1, (float) config('content-batches.rate_per_minute', 30));
        // Leave 60s headroom for the last call + finalize, then hand off the tail.
        $capacity  = max(1, (int) floor(($this->timeout - 60) / max(1.0, $interval)));
        $processed = 0;

        while ($processed < $capacity) {
            $batch->refresh();
            if ($batch->status !== ProductContentBatch::STATUS_PROCESSING) {
                return; // cancelled mid-run
            }

            $row = $this->claimNextRow($batch);
            if (!$row) {
                break; // drained (or only cooling retries left)
            }

            $this->processRow($ai, $batch, $row);
            $processed++;

            if ($interval > 0) {
                $this->pause($interval);
            }
        }

        $this->finalizeOrContinue($batch);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateProductContentBatchJob failed', [
            'batch_id' => $this->batchId,
            'error'    => mb_substr($e->getMessage(), 0, 500),
        ]);
    }

    /* ── claiming ─────────────────────────────────────────────────────────── */

    private function claimNextRow(ProductContentBatch $batch): ?ProductContentJob
    {
        for ($i = 0; $i < 25; $i++) {
            $candidate = ProductContentJob::where('batch_id', $batch->id)
                ->where('status', ProductContentJob::STATUS_PENDING)
                ->orderBy('id')
                ->first();

            if (!$candidate) {
                $cooldown  = (int) config('content-batches.retry_cooldown_seconds', 30);
                $candidate = ProductContentJob::where('batch_id', $batch->id)
                    ->where('status', ProductContentJob::STATUS_RETRYING)
                    ->where('updated_at', '<=', now()->subSeconds($cooldown))
                    ->orderBy('id')
                    ->first();
            }
            if (!$candidate) {
                return null;
            }

            $claimed = ProductContentJob::where('id', $candidate->id)
                ->whereIn('status', ProductContentJob::CLAIMABLE_STATUSES)
                ->update(['status' => ProductContentJob::STATUS_PROCESSING, 'claimed_at' => now()]);

            if ($claimed === 1) {
                return $candidate->fresh();
            }
        }

        return null;
    }

    /* ── row processing ───────────────────────────────────────────────────── */

    private function processRow($ai, ProductContentBatch $batch, ProductContentJob $row): void
    {
        $options = (array) $batch->options;

        try {
            $product = Product::find($row->product_id);
            if (!$product) {
                $this->finishRow($batch, $row, ProductContentJob::STATUS_SKIPPED, 'skipped_count', [
                    'description_updated' => false,
                    'categories_added'    => [],
                ], 'Product not found');
                return;
            }

            $generate     = (array) ($options['generate'] ?? ['description', 'categories']);
            $wantDesc     = in_array('description', $generate, true);
            $wantCats     = in_array('categories', $generate, true);
            $onlyMissing  = (bool) ($options['only_missing'] ?? false);
            $categoryMode = ($options['category_mode'] ?? 'append') === 'replace' ? 'replace' : 'append';

            // A description is needed unless "only fill empty" is on and this
            // product already has one.
            $hasDescription = trim(strip_tags((string) $product->description)) !== '';
            $descNeeded     = $wantDesc && (!$onlyMissing || !$hasDescription);

            $candidates = [];
            if ($wantCats) {
                $candidates = Category::query()
                    ->when($product->type_id, fn ($q) => $q->where('type_id', $product->type_id))
                    ->limit(400)
                    ->get(['id', 'name', 'slug'])
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])
                    ->all();
            }
            $wantCatsEffective = $wantCats && !empty($candidates);

            // Nothing to generate (e.g. only-missing + already described, no
            // categories) — finish WITHOUT a paid AI call.
            if (!$descNeeded && !$wantCatsEffective) {
                $this->finishRow($batch, $row, ProductContentJob::STATUS_COMPLETED, 'skipped_count', [
                    'description_updated' => false,
                    'categories_added'    => [],
                ]);
                return;
            }

            $req = (object) [
                'name'             => $product->name,
                'prompt'           => null,
                'language'         => $options['language'] ?? 'en',
                'tone'             => $options['tone'] ?? 'professional',
                'length'           => $options['length'] ?? 'medium',
                'want_description' => $descNeeded,
                'want_categories'  => $wantCatsEffective,
                'categories'       => $candidates,
            ];

            $result = $ai->generateProductContent($req);

            // Apply every write + finalize atomically so a mid-flight crash can't
            // leave the row completed-but-uncounted (it rolls back to processing
            // and the sweeper re-drives it; the writes are overwrite-idempotent).
            DB::transaction(function () use ($batch, $row, $product, $result, $descNeeded, $wantCatsEffective, $categoryMode) {
                $changes = ['description_updated' => false, 'categories_added' => []];
                $didWork = false;

                if ($descNeeded && !empty($result['description'])) {
                    $safeHtml = static::sanitizeDescription((string) $result['description']);
                    if (trim(strip_tags($safeHtml)) !== '') {
                        // Writes the plain base `description` column (read-time
                        // overlay handles other locales).
                        $product->update(['description' => $safeHtml]);
                        $changes['description_updated'] = true;
                        $didWork = true;
                    }
                }

                if ($wantCatsEffective && !empty($result['category_ids'])) {
                    if ($categoryMode === 'replace') {
                        $product->categories()->sync($result['category_ids']);
                        $changes['categories_added'] = array_values($result['category_ids']);
                        $didWork = true;
                    } else {
                        $existing = $product->categories()->pluck('categories.id')->all();
                        $newIds   = array_values(array_diff($result['category_ids'], $existing));
                        if (!empty($newIds)) {
                            $product->categories()->attach($newIds);
                            $changes['categories_added'] = $newIds;
                            $didWork = true;
                        }
                    }
                }

                $row->update([
                    'status'     => ProductContentJob::STATUS_COMPLETED,
                    'changes'    => $changes,
                    'last_error' => null,
                    'claimed_at' => null,
                ]);
                ProductContentBatch::where('id', $batch->id)->increment('processed_count');
                ProductContentBatch::where('id', $batch->id)->increment($didWork ? 'updated_count' : 'skipped_count');
            });
        } catch (\Throwable $e) {
            $this->failRow($batch, $row, $e);
        }
    }

    /**
     * Allowlist-sanitize model-authored HTML before it becomes live storefront
     * copy. Three separate hazards, in order:
     *  1. `strip_tags` keeps the CONTENTS of what it strips, so a `<script>`
     *     block would survive as its own body text ("alert(1)") and overwrite
     *     good copy — script/style blocks must be removed whole, first.
     *  2. `strip_tags` also keeps ATTRIBUTES of allowed tags, so `<p onclick=…>`
     *     would pass straight through — every allowed tag is emitted bare.
     *  3. No `<a>`: a generated description has no reason to link out, and
     *     allowing it re-opens `href="javascript:…"`.
     */
    public static function sanitizeDescription(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*#is', '', $html); // unclosed
        $html = strip_tags($html, '<p><ul><ol><li><strong><em><b><i><br>');

        return (string) preg_replace('#<\s*([a-z]+)\b[^>]*?(/?)\s*>#i', '<$1$2>', $html);
    }

    /** Atomically mark a row terminal + bump processed_count and one outcome counter. */
    private function finishRow(
        ProductContentBatch $batch,
        ProductContentJob $row,
        string $status,
        string $counter,
        array $changes,
        ?string $error = null
    ): void {
        DB::transaction(function () use ($batch, $row, $status, $counter, $changes, $error) {
            $row->update([
                'status'     => $status,
                'changes'    => $changes,
                'last_error' => $error,
                'claimed_at' => null,
            ]);
            ProductContentBatch::where('id', $batch->id)->increment('processed_count');
            ProductContentBatch::where('id', $batch->id)->increment($counter);
        });
    }

    private function failRow(ProductContentBatch $batch, ProductContentJob $row, \Throwable $e): void
    {
        $message  = mb_substr($e->getMessage(), 0, 1000);
        $attempts = (int) $row->attempts + 1;

        if ($attempts < (int) config('content-batches.row_max_attempts', 3)) {
            $row->update([
                'status'     => ProductContentJob::STATUS_RETRYING,
                'attempts'   => $attempts,
                'last_error' => $message,
                'claimed_at' => null,
            ]);
            return;
        }

        DB::transaction(function () use ($batch, $row, $message, $attempts) {
            $row->update([
                'status'     => ProductContentJob::STATUS_FAILED,
                'attempts'   => $attempts,
                'last_error' => $message,
                'claimed_at' => null,
            ]);
            ProductContentBatch::where('id', $batch->id)->increment('processed_count');
            ProductContentBatch::where('id', $batch->id)->increment('failed_count');
        });
        $this->recordRowError($batch, (int) $row->product_id, $message);
    }

    /** Keep a capped sample of row errors on the batch for the UI. */
    private function recordRowError(ProductContentBatch $batch, int $productId, string $message): void
    {
        $errors = (array) ($batch->fresh()->row_errors ?? []);
        if (count($errors) < 25) {
            $errors[] = ['product_id' => $productId, 'error' => $message];
            $batch->update(['row_errors' => $errors]);
        }
    }

    /* ── finalize / continue ──────────────────────────────────────────────── */

    private function finalizeOrContinue(ProductContentBatch $batch): void
    {
        $open = ProductContentJob::where('batch_id', $batch->id)
            ->whereIn('status', [
                ProductContentJob::STATUS_PENDING,
                ProductContentJob::STATUS_RETRYING,
                ProductContentJob::STATUS_PROCESSING,
            ])
            ->count();

        if ($open > 0) {
            // Re-dispatch only when a row is claimable now (pending, or a
            // retrying row past cooldown); otherwise the sweeper re-drives it.
            $cooldown  = (int) config('content-batches.retry_cooldown_seconds', 30);
            $claimable = ProductContentJob::where('batch_id', $batch->id)
                ->where(function ($q) use ($cooldown) {
                    $q->where('status', ProductContentJob::STATUS_PENDING)
                        ->orWhere(function ($q2) use ($cooldown) {
                            $q2->where('status', ProductContentJob::STATUS_RETRYING)
                                ->where('updated_at', '<=', now()->subSeconds($cooldown));
                        });
                })
                ->exists();

            if ($claimable) {
                $batch->update(['last_dispatched_at' => now()]);
                static::dispatch($batch->id)->delay(now()->addSeconds(5));
            }
            return;
        }

        // Drained — finalize once (atomic flip out of processing).
        $failed  = (int) $batch->fresh()->failed_count;
        $final   = $failed > 0 ? ProductContentBatch::STATUS_COMPLETED_WITH_ERRORS : ProductContentBatch::STATUS_COMPLETED;

        ProductContentBatch::where('id', $batch->id)
            ->whereIn('status', [ProductContentBatch::STATUS_PROCESSING, ProductContentBatch::STATUS_PENDING])
            ->update(['status' => $final, 'completed_at' => now()]);
    }

    private function pause(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int) (min($seconds, 60) * 1_000_000));
    }
}
