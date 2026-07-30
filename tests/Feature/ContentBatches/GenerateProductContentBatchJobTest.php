<?php

namespace Tests\Feature\ContentBatches;

use Illuminate\Support\Facades\DB;
use Marvel\Console\SweepContentBatchesCommand;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;

/**
 * The claim-loop worker's guarantees: money is only spent when there is work to
 * do, generated HTML can't smuggle markup into live copy, a cancel can't be
 * resurrected, and every terminal row is counted exactly once.
 */
final class GenerateProductContentBatchJobTest extends ContentBatchTestCase
{
    public function test_it_generates_a_description_and_appends_categories(): void
    {
        [$productId] = $this->seedProducts([['Areca Palm', null]]);
        $catId = $this->seedCategory('Indoor Plants', 'indoor-plants');

        $this->ai->queue[] = [
            'description'   => '<p>A lush palm.</p>',
            'category_ids'  => [$catId],
        ];

        $batch = $this->makeBatch([$productId]);
        (new GenerateProductContentBatchJob($batch->id))->handle();

        $this->assertSame('<p>A lush palm.</p>', DB::table('products')->where('id', $productId)->value('description'));
        $this->assertSame(1, DB::table('category_product')
            ->where('product_id', $productId)->where('category_id', $catId)->count());

        $batch->refresh();
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, (int) $batch->processed_count);
        $this->assertSame(1, (int) $batch->updated_count);
        $this->assertSame(0, (int) $batch->failed_count);
    }

    /**
     * "Only fill empty descriptions" over an already-described product with no
     * category work left is a no-op — and a no-op must not cost an API call.
     */
    public function test_it_skips_a_row_with_nothing_to_generate_without_calling_the_ai(): void
    {
        [$productId] = $this->seedProducts([['Snake Plant', '<p>Already written.</p>']]);

        $batch = $this->makeBatch([$productId], [
            'generate'     => ['description'],
            'only_missing' => true,
        ]);
        (new GenerateProductContentBatchJob($batch->id))->handle();

        $this->assertSame(0, $this->ai->callCount(), 'a row with nothing to do must not hit the AI');
        $this->assertSame('<p>Already written.</p>', DB::table('products')->where('id', $productId)->value('description'));

        $batch->refresh();
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, (int) $batch->processed_count);
        $this->assertSame(1, (int) $batch->skipped_count);
        $this->assertSame(0, (int) $batch->updated_count);
    }

    /** Category work still counts as work when the description is already filled. */
    public function test_only_missing_still_generates_categories(): void
    {
        [$productId] = $this->seedProducts([['ZZ Plant', '<p>Already written.</p>']]);
        $catId = $this->seedCategory('Low Light', 'low-light');
        $this->ai->queue[] = ['description' => null, 'category_ids' => [$catId]];

        $batch = $this->makeBatch([$productId], ['only_missing' => true]);
        (new GenerateProductContentBatchJob($batch->id))->handle();

        $this->assertSame(1, $this->ai->callCount());
        $this->assertSame('<p>Already written.</p>', DB::table('products')->where('id', $productId)->value('description'));
        $this->assertSame(1, DB::table('category_product')->where('product_id', $productId)->count());
        $this->assertSame(1, (int) $batch->fresh()->updated_count);
    }

    /** Model-authored HTML becomes live storefront copy — script/iframe must not survive. */
    public function test_it_strips_unsafe_markup_from_the_generated_description(): void
    {
        [$productId] = $this->seedProducts([['Monstera', null]]);
        $this->ai->queue[] = [
            'description'  => '<p>Nice plant.</p><script>alert(1)</script><iframe src="x"></iframe><strong>Care</strong>',
            'category_ids' => [],
        ];

        $batch = $this->makeBatch([$productId], ['generate' => ['description']]);
        (new GenerateProductContentBatchJob($batch->id))->handle();

        $saved = (string) DB::table('products')->where('id', $productId)->value('description');
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('<iframe', $saved);
        $this->assertStringContainsString('<p>Nice plant.</p>', $saved);
        $this->assertStringContainsString('<strong>Care</strong>', $saved);
    }

    /** A description that sanitizes down to nothing must not blank the product. */
    public function test_it_does_not_overwrite_a_description_with_empty_sanitized_html(): void
    {
        [$productId] = $this->seedProducts([['Fiddle Leaf', '<p>Existing copy.</p>']]);
        $this->ai->queue[] = ['description' => '<script>alert(1)</script>', 'category_ids' => []];

        $batch = $this->makeBatch([$productId], ['generate' => ['description']]);
        (new GenerateProductContentBatchJob($batch->id))->handle();

        $this->assertSame('<p>Existing copy.</p>', DB::table('products')->where('id', $productId)->value('description'));
        $batch->refresh();
        $this->assertSame(1, (int) $batch->skipped_count);
        $this->assertSame(0, (int) $batch->updated_count);
    }

    /** A row retries up to the cap, then fails once — counted once, not per attempt. */
    public function test_a_failing_row_retries_then_fails_and_is_counted_once(): void
    {
        [$productId] = $this->seedProducts([['Cactus', null]]);
        $this->ai->default = new \RuntimeException('Billing hard limit has been reached.');

        $batch = $this->makeBatch([$productId], ['generate' => ['description']]);
        (new GenerateProductContentBatchJob($batch->id))->handle();

        $row = ProductContentJob::where('batch_id', $batch->id)->first();
        $this->assertSame(ProductContentJob::STATUS_FAILED, $row->status);
        $this->assertSame(3, (int) $row->attempts);
        $this->assertStringContainsString('Billing hard limit', (string) $row->last_error);

        $batch->refresh();
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED_WITH_ERRORS, $batch->status);
        $this->assertSame(1, (int) $batch->processed_count, 'a row that retried is still ONE processed row');
        $this->assertSame(1, (int) $batch->failed_count);
    }

    /** A cancel landing between load and claim must not flip the batch back to processing. */
    public function test_it_does_not_resurrect_a_cancelled_batch(): void
    {
        [$productId] = $this->seedProducts([['Areca Palm', null]]);
        $batch = $this->makeBatch([$productId]);

        // The job loads the batch, then a cancel lands before it claims.
        $job = new GenerateProductContentBatchJob($batch->id);
        ProductContentBatch::where('id', $batch->id)->update([
            'status'       => ProductContentBatch::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
        $job->handle();

        $this->assertSame(ProductContentBatch::STATUS_CANCELLED, $batch->fresh()->status);
        $this->assertSame(0, $this->ai->callCount());
    }

    /** The sweeper's finalize is guarded the same way the worker's is. */
    public function test_the_sweeper_does_not_overwrite_a_cancelled_batch(): void
    {
        [$productId] = $this->seedProducts([['Areca Palm', null]]);
        $batch = $this->makeBatch([$productId], [], [
            'status'             => ProductContentBatch::STATUS_CANCELLED,
            'cancelled_at'       => now(),
            'last_dispatched_at' => now()->subHour(),
        ]);
        ProductContentJob::where('batch_id', $batch->id)
            ->update(['status' => ProductContentJob::STATUS_SKIPPED]);

        $this->artisan(SweepContentBatchesCommand::class)->assertSuccessful();

        $this->assertSame(ProductContentBatch::STATUS_CANCELLED, $batch->fresh()->status);
    }
}
