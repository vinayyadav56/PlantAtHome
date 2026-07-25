<?php

namespace Tests\Feature\ContentBatches;

use Illuminate\Support\Facades\Queue;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;

/**
 * retry-failed rebuilds the batch counters from the rows themselves. The
 * earlier "subtract the failed count" form drifted the moment it ran twice
 * (double-click, two tabs): the second call subtracted rows it had already
 * subtracted, so progress reported more work done than existed.
 */
final class RetryFailedContentBatchTest extends ContentBatchTestCase
{
    public function test_retry_failed_recomputes_counters_and_is_idempotent(): void
    {
        Queue::fake();
        $ids   = $this->seedProducts([['A', null], ['B', null], ['C', null]]);
        $batch = $this->makeBatch($ids, [], [
            'status'          => ProductContentBatch::STATUS_COMPLETED_WITH_ERRORS,
            'processed_count' => 3,
            'updated_count'   => 1,
            'skipped_count'   => 1,
            'failed_count'    => 1,
            'completed_at'    => now(),
        ]);

        $rows = ProductContentJob::where('batch_id', $batch->id)->orderBy('id')->get();
        $rows[0]->update(['status' => ProductContentJob::STATUS_COMPLETED]);
        $rows[1]->update(['status' => ProductContentJob::STATUS_SKIPPED]);
        $rows[2]->update(['status' => ProductContentJob::STATUS_FAILED, 'attempts' => 3, 'last_error' => 'boom']);

        $this->postJson("/api/ai-content-batches/{$batch->id}/retry-failed")->assertOk();

        $batch->refresh();
        $this->assertSame(ProductContentBatch::STATUS_PROCESSING, $batch->status);
        $this->assertSame(2, (int) $batch->processed_count, 'the reset row is no longer processed');
        $this->assertSame(0, (int) $batch->failed_count);
        $this->assertNull($batch->completed_at);

        $reset = $rows[2]->fresh();
        $this->assertSame(ProductContentJob::STATUS_PENDING, $reset->status);
        $this->assertSame(0, (int) $reset->attempts);
        $this->assertNull($reset->last_error);

        // A second retry has nothing to reset — it 409s rather than
        // subtracting the same row's counters a second time.
        $this->postJson("/api/ai-content-batches/{$batch->id}/retry-failed")->assertStatus(409);
        $batch->refresh();
        $this->assertSame(2, (int) $batch->processed_count);

        Queue::assertPushed(GenerateProductContentBatchJob::class);
    }

    public function test_retry_failed_rejects_an_active_batch(): void
    {
        $ids   = $this->seedProducts([['A', null]]);
        $batch = $this->makeBatch($ids, [], ['status' => ProductContentBatch::STATUS_PROCESSING]);

        $this->postJson("/api/ai-content-batches/{$batch->id}/retry-failed")->assertStatus(409);
    }

    public function test_cancel_stops_pending_rows(): void
    {
        $ids   = $this->seedProducts([['A', null], ['B', null]]);
        $batch = $this->makeBatch($ids, [], ['status' => ProductContentBatch::STATUS_PROCESSING]);

        $this->postJson("/api/ai-content-batches/{$batch->id}/cancel")->assertOk();

        $this->assertSame(ProductContentBatch::STATUS_CANCELLED, $batch->fresh()->status);
        $this->assertSame(2, ProductContentJob::where('batch_id', $batch->id)
            ->where('status', ProductContentJob::STATUS_SKIPPED)->count());
    }
}
