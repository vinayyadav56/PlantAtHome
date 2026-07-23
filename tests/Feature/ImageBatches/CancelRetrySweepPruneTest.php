<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Database\Models\ImageGenerationResult;
use Marvel\Jobs\GenerateImageBatchJob;
use Marvel\Jobs\PackageImageBatchZipJob;

/** Cancel + retry-failed endpoints, the sweeper, and retention pruning. */
final class CancelRetrySweepPruneTest extends ImageBatchTestCase
{
    public function test_cancel_flips_pending_rows_and_counts_remaining_images(): void
    {
        $this->admin();
        $batch = $this->makeBatch([
            ['PLT001', 'Areca Palm', 'Prompt'],
            ['PLT002', 'Snake Plant', 'Prompt'],
        ], ['status' => ImageBatch::STATUS_PROCESSING], imagesPerPlant: 2);

        $this->postJson("/api/ai-image-batches/{$batch->id}/cancel")->assertOk();

        $batch->refresh();
        $this->assertSame(ImageBatch::STATUS_CANCELLED, $batch->status);
        $this->assertSame(4, (int) $batch->cancelled_count);
        $this->assertNotNull($batch->cancelled_at);
        $this->assertSame(2, ImageGenerationJob::where('status', ImageGenerationJob::STATUS_CANCELLED)->count());

        // Cancelling again → 409; retry on cancelled → 409.
        $this->postJson("/api/ai-image-batches/{$batch->id}/cancel")->assertStatus(409);
        $this->postJson("/api/ai-image-batches/{$batch->id}/retry-failed")->assertStatus(409);
    }

    public function test_retry_failed_resets_only_failed_rows_and_regenerates_only_failed_slots(): void
    {
        $this->admin();
        $this->fakeOpenAi();

        // A finished batch: PLT001 fully ok, PLT002 failed both slots.
        $batch = $this->makeBatch([
            ['PLT001', 'Areca Palm', 'A lush areca palm'],
            ['PLT002', 'Snake Plant', 'FAILME always'],
        ], imagesPerPlant: 2);
        GenerateImageBatchJob::dispatch($batch->id);
        $batch->refresh();
        $this->assertSame(ImageBatch::STATUS_COMPLETED_WITH_ERRORS, $batch->status);
        $failedResultIds = ImageGenerationResult::where('status', 'failed')->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $failedResultIds);

        // The plant is "fixed" — clear the poison marker, then retry.
        ImageGenerationJob::where('plant_code', 'PLT002')->update(['prompt' => 'A tall snake plant']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['b64_json' => base64_encode(self::PNG_BYTES), 'revised_prompt' => null]],
            ], 200),
        ]);

        $this->postJson("/api/ai-image-batches/{$batch->id}/retry-failed")->assertOk();

        $batch->refresh();
        $this->assertSame(ImageBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(4, (int) $batch->generated_count);
        $this->assertSame(0, (int) $batch->failed_count);

        // Only the 2 failed slots were regenerated…
        Http::assertSentCount(2);
        // …and they flipped IN PLACE — same Image IDs, now completed.
        $flipped = ImageGenerationResult::whereIn('id', $failedResultIds)->get();
        $this->assertTrue($flipped->every(fn ($r) => $r->status === ImageGenerationResult::STATUS_COMPLETED));
    }

    public function test_sweeper_repends_stalled_rows_and_redispatches_orphans(): void
    {
        Queue::fake();
        $batch = $this->makeBatch(
            [['PLT001', 'Areca Palm', 'Prompt']],
            ['status' => ImageBatch::STATUS_PROCESSING, 'last_dispatched_at' => now()->subMinutes(30)],
            imagesPerPlant: 1,
        );
        ImageGenerationJob::query()->update([
            'status'     => ImageGenerationJob::STATUS_PROCESSING,
            'claimed_at' => now()->subMinutes(45),
        ]);

        Artisan::call('images:sweep-batches');

        $this->assertSame(ImageGenerationJob::STATUS_PENDING, ImageGenerationJob::first()->status);
        Queue::assertPushed(GenerateImageBatchJob::class);
    }

    public function test_sweeper_catches_missed_finalize_exactly_once(): void
    {
        Queue::fake();
        $batch = $this->makeBatch(
            [['PLT001', 'Areca Palm', 'Prompt']],
            ['status' => ImageBatch::STATUS_PROCESSING],
            imagesPerPlant: 1,
        );
        ImageGenerationJob::query()->update(['status' => ImageGenerationJob::STATUS_COMPLETED]);

        Artisan::call('images:sweep-batches');
        Artisan::call('images:sweep-batches'); // second run must not double-dispatch

        $this->assertSame(ImageBatch::STATUS_PACKAGING, $batch->fresh()->status);
        Queue::assertPushed(PackageImageBatchZipJob::class, 1);
    }

    public function test_prune_deletes_files_but_keeps_audit_rows(): void
    {
        $this->fakeOpenAi();
        $batch = $this->makeBatch([['PLT001', 'Areca Palm', 'A palm']], imagesPerPlant: 1);
        GenerateImageBatchJob::dispatch($batch->id);
        $batch->refresh();
        $imagePath = $batch->storagePrefix() . '/PLT001_Areca_Palm/PLT001_Areca_Palm_1.png';
        Storage::disk('s3')->assertExists($imagePath);

        // Not yet expired → untouched.
        Artisan::call('images:prune-batches');
        Storage::disk('s3')->assertExists($imagePath);

        $batch->update(['expires_at' => now()->subDay()]);
        Artisan::call('images:prune-batches');

        Storage::disk('s3')->assertMissing($imagePath);
        Storage::disk('s3')->assertMissing($batch->zip_paths[0]['path']);
        $this->assertNotNull($batch->fresh()->files_pruned_at);
        // Audit survives: batch + rows + results.
        $this->assertSame(1, ImageBatch::count());
        $this->assertSame(1, ImageGenerationJob::count());
        $this->assertSame(1, ImageGenerationResult::count());
    }
}
