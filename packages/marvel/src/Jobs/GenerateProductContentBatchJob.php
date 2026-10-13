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
use Marvel\Ai\InvalidAiOutput;
use Marvel\Services\MissingPlantContent;
use OpenAI\Exceptions\ErrorException;

/**
 * Self-re-dispatching claim-loop worker for one bulk AI content run — one live
 * queue job per batch, not one per product. Idempotent by atomic row claiming
 * and status-guarded row completion; a crash/timeout resumes at the unfinished
 * tail. Each invocation works to a time budget under the queue timeout and
 * re-dispatches the remainder.
 *
 * Two modes: the listing's manual run (description + categories for picked
 * products) and "missing" (fill only the plant fields MissingPlantContent
 * reports missing, server-selected).
 */
class GenerateProductContentBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    private const FLAGGED = 'flagged for review in an earlier run';

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

        $interval = 60.0 / max(0.1, (float) config('content-batches.rate_per_minute', 30));
        // A time budget, not a row count: a slow AI call (90s HTTP timeout) can't
        // push the worker past $timeout mid-row. 150s covers that call plus
        // finalize; the tail is handed to a fresh dispatch.
        $deadline = microtime(true) + $this->timeout - 150;
        $content  = $batch->isMissingMode() ? app(MissingPlantContent::class) : null;

        while (microtime(true) < $deadline) {
            $batch->refresh();
            if ($batch->status !== ProductContentBatch::STATUS_PROCESSING) {
                return; // cancelled mid-run
            }

            $row = $this->claimNextRow($batch);
            if (!$row) {
                break; // drained (or only cooling retries left)
            }

            // Heartbeat: the sweeper re-dispatches a batch whose
            // last_dispatched_at goes stale — keep it fresh while this job lives.
            ProductContentBatch::where('id', $batch->id)->update(['last_dispatched_at' => now()]);

            $started = microtime(true);
            if ($content) {
                $this->processMissingRow($ai, $content, $batch, $row);
            } else {
                $this->processRow($ai, $batch, $row);
            }

            // Pace: sleep only what is left of the interval after the call.
            $this->pause($interval - (microtime(true) - $started));
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
            $id = $this->nextClaimableId($batch);
            if (!$id) {
                return null;
            }

            $claimed = ProductContentJob::where('id', $id)
                ->whereIn('status', ProductContentJob::CLAIMABLE_STATUSES)
                ->update(['status' => ProductContentJob::STATUS_PROCESSING, 'claimed_at' => now()]);

            if ($claimed === 1) {
                return ProductContentJob::find($id);
            }
        }

        return null;
    }

    /** Pending rows first, then a retrying row whose backoff has passed. */
    private function nextClaimableId(ProductContentBatch $batch): ?int
    {
        $pending = ProductContentJob::where('batch_id', $batch->id)
            ->where('status', ProductContentJob::STATUS_PENDING)
            ->orderBy('id')
            ->value('id');
        if ($pending) {
            return (int) $pending;
        }

        // Few rows are ever retrying at once, so the backoff is checked in PHP.
        $due = ProductContentJob::where('batch_id', $batch->id)
            ->where('status', ProductContentJob::STATUS_RETRYING)
            ->orderBy('id')
            ->get(['id', 'attempts', 'updated_at'])
            ->first(fn ($row) => $this->retryDue($row));

        return $due ? (int) $due->id : null;
    }

    /** Exponential backoff per row: cooldown × 2^(attempts−1), i.e. 30s then 60s. */
    private function retryDue(ProductContentJob $row): bool
    {
        $wait = (int) config('content-batches.retry_cooldown_seconds', 30) * 2 ** max(0, (int) $row->attempts - 1);

        return !$row->updated_at || $row->updated_at->lte(now()->subSeconds($wait));
    }

    /* ── row processing ───────────────────────────────────────────────────── */

    private function processRow($ai, ProductContentBatch $batch, ProductContentJob $row): void
    {
        $options = (array) $batch->options;

        try {
            $product = Product::find($row->product_id);
            if (!$product) {
                $this->finishRow($batch, $row, ProductContentJob::STATUS_SKIPPED, 'skipped_count', [
                    'changes'    => ['description_updated' => false, 'categories_added' => []],
                    'last_error' => 'Product not found',
                ]);
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
                    'changes'    => ['description_updated' => false, 'categories_added' => []],
                    'last_error' => null,
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

                $this->finishRow($batch, $row, ProductContentJob::STATUS_COMPLETED, $didWork ? 'updated_count' : 'skipped_count', [
                    'changes'    => $changes,
                    'last_error' => null,
                ]);
            });
        } catch (\Throwable $e) {
            // The manual run retries every error, as it always has.
            $this->failRow($batch, $row, $e, true);
        }
    }

    /**
     * Missing mode: re-check the plant NOW (it may have been edited since the
     * run started), ask the AI for exactly what is still missing, validate it,
     * and write only into fields that are still empty.
     */
    private function processMissingRow($ai, MissingPlantContent $content, ProductContentBatch $batch, ProductContentJob $row): void
    {
        $changes = (array) $row->changes;
        $before  = array_values((array) ($changes['missing_before'] ?? []));
        $noCall  = fn (?array $review) => [
            'missing_before' => $before,
            'requested'      => [],
            'generated'      => (array) ($changes['generated'] ?? []),
            'review'         => $review ?: null,
            'invalid'        => null,
        ];

        try {
            $plant = $content->find((int) $row->product_id);
            if (!$plant) {
                $this->finishRow($batch, $row, ProductContentJob::STATUS_SKIPPED, 'skipped_count', [
                    'changes'        => $noCall(null),
                    'last_error'     => 'No longer in the plant catalogue',
                    'fields_missing' => 0,
                ]);
                return;
            }

            $missing = $content->missingFields($plant);
            $flags   = $content->reviewFlags([(int) $plant->id])[(int) $plant->id] ?? [];
            // A retried row is pending again, so reviewFlags() no longer sees its own flags: what
            // the AI declined or failed twice last time stays with a person, not asked again.
            $flags   = array_values(array_unique(array_merge($flags, array_keys((array) ($changes['review'] ?? [])))));
            $todo    = $content->todo($missing, $flags);

            // Nothing left for the AI → no paid call. Flagged leftovers still need a human.
            if ($todo === []) {
                $review = [];
                foreach (array_intersect($missing, $flags) as $field) {
                    $review[$field] = $changes['review'][$field] ?? self::FLAGGED;
                }
                $this->finishRow($batch, $row, $review ? ProductContentJob::STATUS_NEEDS_REVIEW : ProductContentJob::STATUS_SKIPPED, 'skipped_count', [
                    'changes'        => $noCall($review),
                    'last_error'     => null,
                    'fields_missing' => count(array_intersect($before, $missing)),
                ]);
                return;
            }

            $started = microtime(true);
            $result  = $ai->generatePlantContent((object) [
                'name'       => (string) $plant->name,
                'fields'     => $todo,
                'context'    => $content->context($plant),
                'vocabulary' => (array) config('content-batches.plant_vocabulary', []),
            ]);
            $aiMs    = (int) round((microtime(true) - $started) * 1000);
            $usage   = (array) ($result['usage'] ?? []);
            $checked = $content->validate($todo, (array) ($result['fields'] ?? []), $result['scientific_name_confidence'] ?? null, (string) $plant->name);
            if (($result['single_plant'] ?? null) === false) {
                // A pack or set: its copy is written; the plant-identity fields do not apply.
                foreach (array_keys($checked['declined']) as $field) {
                    if (!in_array($field, MissingPlantContent::PRODUCT_FIELDS, true)) {
                        $checked['declined'][$field] = MissingPlantContent::NOT_ONE_PLANT;
                    }
                }
            }

            DB::transaction(function () use ($content, $batch, $row, $plant, $changes, $before, $todo, $flags, $checked, $usage, $aiMs) {
                $written = $content->write((int) $plant->id, $checked['values']);
                $after   = $content->missingFields($content->find((int) $plant->id) ?? $plant);
                $invalid = $checked['invalid'];

                // A field the AI already got wrong once (this row's last attempt or an earlier
                // run) and got wrong again goes to review instead of failing in every run:
                // re-asking for a rule it keeps breaking only costs money.
                $twice = [];
                $seen  = array_keys((array) ($changes['invalid'] ?? []));
                ProductContentJob::where('product_id', (int) $plant->id)
                    ->where('id', '!=', $row->id)
                    ->whereNotNull('changes')
                    ->get(['changes'])
                    ->each(function ($r) use (&$seen) {
                        $seen = array_merge($seen, array_keys((array) ($r->changes['invalid'] ?? [])));
                    });
                foreach ($invalid as $field => $why) {
                    if (in_array($field, $seen, true) && in_array($field, $after, true)) {
                        $twice[$field] = "failed validation twice ({$why})";
                        unset($invalid[$field]);
                    }
                }

                // Left for a human: what the AI declined now + earlier flags still empty.
                $review = [];
                foreach ($after as $field) {
                    if (isset($checked['declined'][$field])) {
                        $review[$field] = $checked['declined'][$field];
                    } elseif (isset($twice[$field])) {
                        $review[$field] = $twice[$field];
                    } elseif (in_array($field, $flags, true)) {
                        $review[$field] = $changes['review'][$field] ?? self::FLAGGED;
                    }
                }

                $error = $invalid
                    ? 'Invalid AI output: ' . implode('; ', array_map(fn ($f, $why) => "{$f} {$why}", array_keys($invalid), $invalid))
                    : null;
                $status = $invalid
                    ? ProductContentJob::STATUS_FAILED
                    : ($review ? ProductContentJob::STATUS_NEEDS_REVIEW : ProductContentJob::STATUS_COMPLETED);
                $counter = $invalid ? 'failed_count' : ($written ? 'updated_count' : 'skipped_count');

                $finished = $this->finishRow($batch, $row, $status, $counter, [
                    'changes'           => [
                        'missing_before' => $before,
                        'requested'      => $todo,
                        'generated'      => array_values(array_unique(array_merge((array) ($changes['generated'] ?? []), $written))),
                        'review'         => $review ?: null,
                        'invalid'        => $invalid ?: null,
                    ],
                    'last_error'        => $error,
                    // Invalid output is a failed attempt (it is never retried automatically).
                    'attempts'          => (int) $row->attempts + ($invalid ? 1 : 0),
                    // Re-counted: how many of the fields this row was queued for are still empty.
                    'fields_missing'    => count(array_intersect($before, $after)),
                    'fields_generated'  => (int) $row->fields_generated + count($written),
                    'prompt_tokens'     => (int) $row->prompt_tokens + (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) $row->completion_tokens + (int) ($usage['completion_tokens'] ?? 0),
                    'ai_ms'             => (int) $row->ai_ms + $aiMs,
                ]);
                if ($finished && $error) {
                    $this->recordRowError($batch, (int) $row->product_id, $error);
                }
            });
        } catch (\Throwable $e) {
            // An unusable reply still cost tokens: keep them in the run's total.
            $usage = $e instanceof InvalidAiOutput ? $e->usage : [];
            $this->failRow($batch, $row, $e, $this->isRetryable($e), $usage ? [
                'prompt_tokens'     => (int) $row->prompt_tokens + (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) $row->completion_tokens + (int) ($usage['completion_tokens'] ?? 0),
            ] : []);
        }
    }

    /**
     * Rate limits, 5xx, timeouts, transport errors and DB deadlocks get the
     * row's remaining attempts. Quota/billing, a bad API key and invalid model
     * output fail at once: a retry would fail (or pay) the same way again.
     */
    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof \UnexpectedValueException) {
            return false;
        }

        if ($e instanceof ErrorException) {
            // Decide on OpenAI's structured code, not the message: rate-limit (429) messages
            // link to /account/billing, and those must be retried.
            $code = strtolower((string) $e->getErrorCode());
            if (in_array($code, ['insufficient_quota', 'invalid_api_key', 'account_deactivated', 'billing_hard_limit_reached'], true)
                || strtolower((string) $e->getErrorType()) === 'insufficient_quota') {
                return false;
            }
            // Older error payloads carry no code; these messages are never rate limits.
            return !($code === '' && preg_match('/exceeded your current quota|hard limit has been reached|incorrect api key/i', $e->getMessage()));
        }

        return true; // timeouts, transport errors, 5xx pages, deadlocks — bounded by row_max_attempts
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

    /**
     * Atomically move a row out of `processing` and count it exactly once.
     * Guarded on status = processing: a duplicate execution of a row that is
     * already finished (e.g. re-pended by the sweeper while its first worker
     * was still alive) changes nothing and counts nothing.
     */
    private function finishRow(ProductContentBatch $batch, ProductContentJob $row, string $status, string $counter, array $attributes = []): bool
    {
        if (isset($attributes['changes']) && is_array($attributes['changes'])) {
            $attributes['changes'] = json_encode($attributes['changes']); // query updates skip model casts
        }

        return DB::transaction(function () use ($batch, $row, $status, $counter, $attributes) {
            $done = ProductContentJob::where('id', $row->id)
                ->where('status', ProductContentJob::STATUS_PROCESSING)
                ->update(['status' => $status, 'claimed_at' => null] + $attributes);
            if ($done !== 1) {
                return false;
            }

            ProductContentBatch::where('id', $batch->id)->increment('processed_count');
            ProductContentBatch::where('id', $batch->id)->increment($counter);

            return true;
        });
    }

    private function failRow(ProductContentBatch $batch, ProductContentJob $row, \Throwable $e, bool $retryable, array $extra = []): void
    {
        $message  = mb_substr($e->getMessage(), 0, 1000);
        $attempts = (int) $row->attempts + 1;

        if ($retryable && $attempts < (int) config('content-batches.row_max_attempts', 3)) {
            // Guarded like finishRow: never drag a finished row back to retrying.
            ProductContentJob::where('id', $row->id)
                ->where('status', ProductContentJob::STATUS_PROCESSING)
                ->update([
                    'status'     => ProductContentJob::STATUS_RETRYING,
                    'attempts'   => $attempts,
                    'last_error' => $message,
                    'claimed_at' => null,
                ] + $extra);
            return;
        }

        if ($this->finishRow($batch, $row, ProductContentJob::STATUS_FAILED, 'failed_count', [
            'attempts'   => $attempts,
            'last_error' => $message,
        ] + $extra)) {
            $this->recordRowError($batch, (int) $row->product_id, $message);
        }
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
        $counts = ProductContentJob::where('batch_id', $batch->id)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        if ((int) $counts->only(ProductContentJob::OPEN_STATUSES)->sum() > 0) {
            // Re-dispatch only when a row is claimable now (pending, or a
            // retrying row past its backoff); otherwise the sweeper re-drives it.
            if ($this->nextClaimableId($batch)) {
                ProductContentBatch::where('id', $batch->id)->update(['last_dispatched_at' => now()]);
                static::dispatch($batch->id)->delay(now()->addSeconds(5));
            }
            return;
        }

        // Drained — finalize once (atomic flip out of processing), from the rows.
        ProductContentBatch::where('id', $batch->id)
            ->whereIn('status', [ProductContentBatch::STATUS_PROCESSING, ProductContentBatch::STATUS_PENDING])
            ->update(['status' => $batch->finalStatus(), 'completed_at' => now()]);
    }

    private function pause(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int) (min($seconds, 60) * 1_000_000));
    }
}
