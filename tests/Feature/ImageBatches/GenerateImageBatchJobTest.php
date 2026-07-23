<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Database\Models\ImageGenerationResult;
use Marvel\Jobs\GenerateImageBatchJob;
use Marvel\Mail\ImageBatchCompleted;

/**
 * The claim-loop generation job end-to-end on the sync queue: pacing aside,
 * a single dispatch drains the batch (self re-dispatch runs inline), packages
 * the zip and finalizes.
 */
final class GenerateImageBatchJobTest extends ImageBatchTestCase
{
    public function test_full_batch_generates_stores_zips_and_emails(): void
    {
        $this->fakeOpenAi();
        $batch = $this->makeBatch([
            ['PLT001', 'Areca Palm', 'A lush areca palm'],
            ['PLT002', 'Snake Plant', 'A tall snake plant'],
        ], imagesPerPlant: 2);

        GenerateImageBatchJob::dispatch($batch->id);

        $batch->refresh();
        $this->assertSame(ImageBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(4, (int) $batch->generated_count);
        $this->assertSame(0, (int) $batch->failed_count);
        $this->assertSame(100, $batch->progress);
        $this->assertNotNull($batch->completed_at);
        $this->assertNotNull($batch->expires_at);

        // Clean file naming + storage layout.
        Storage::disk('s3')->assertExists($batch->storagePrefix() . '/PLT001_Areca_Palm/PLT001_Areca_Palm_1.png');
        Storage::disk('s3')->assertExists($batch->storagePrefix() . '/PLT002_Snake_Plant/PLT002_Snake_Plant_2.png');
        $this->assertSame(
            self::PNG_BYTES,
            Storage::disk('s3')->get($batch->storagePrefix() . '/PLT001_Areca_Palm/PLT001_Areca_Palm_1.png')
        );

        // Zip part + manifests uploaded, recorded on the batch.
        $this->assertNotEmpty($batch->zip_paths);
        Storage::disk('s3')->assertExists($batch->zip_paths[0]['path']);
        Storage::disk('s3')->assertExists($batch->storagePrefix() . '/manifest.json');
        Storage::disk('s3')->assertExists($batch->storagePrefix() . '/manifest.csv');

        $manifest = json_decode((string) Storage::disk('s3')->get($batch->manifest_path), true);
        $this->assertCount(4, $manifest['images']);
        $this->assertSame('IMG' . str_pad((string) ImageGenerationResult::first()->id, 12, '0', STR_PAD_LEFT), $manifest['images'][0]['image_id']);
        $this->assertSame('PLT001_Areca_Palm/PLT001_Areca_Palm_1.png', $manifest['images'][0]['file']);

        // Every row terminal, results carry the settings audit.
        $this->assertSame(0, ImageGenerationJob::whereIn('status', ['pending', 'retrying', 'processing'])->count());
        $this->assertSame('gpt-image-1', ImageGenerationResult::first()->model);

        Mail::assertSent(ImageBatchCompleted::class, fn ($mail) => $mail->hasTo('admin@plantathome.test'));
    }

    public function test_failed_rows_retry_then_finalize_with_errors(): void
    {
        $this->fakeOpenAi(); // FAILME prompts fail
        $batch = $this->makeBatch([
            ['PLT001', 'Areca Palm', 'A lush areca palm'],
            ['PLT002', 'Snake Plant', 'FAILME always'],
        ], imagesPerPlant: 2);

        GenerateImageBatchJob::dispatch($batch->id);

        $batch->refresh();
        $this->assertSame(ImageBatch::STATUS_COMPLETED_WITH_ERRORS, $batch->status);
        $this->assertSame(2, (int) $batch->generated_count);
        $this->assertSame(2, (int) $batch->failed_count);

        $failedRow = ImageGenerationJob::where('plant_code', 'PLT002')->first();
        $this->assertSame(ImageGenerationJob::STATUS_FAILED, $failedRow->status);
        $this->assertSame(3, (int) $failedRow->attempts); // row_max_attempts exhausted
        $this->assertNotNull($failedRow->last_error);

        // Failed slots recorded with stable Image IDs.
        $failedResults = ImageGenerationResult::where('job_id', $failedRow->id)->get();
        $this->assertCount(2, $failedResults);
        $this->assertTrue($failedResults->every(fn ($r) => $r->status === ImageGenerationResult::STATUS_FAILED));

        Mail::assertSent(ImageBatchCompleted::class);
    }

    public function test_completed_slots_are_never_regenerated(): void
    {
        $this->fakeOpenAi();
        $batch = $this->makeBatch([['PLT001', 'Areca Palm', 'A lush areca palm']], imagesPerPlant: 3);
        $row   = ImageGenerationJob::first();

        // Slot 2 already completed (from a previous crashed run).
        $existing = ImageGenerationResult::create([
            'batch_id'    => $batch->id,
            'job_id'      => $row->id,
            'image_index' => 2,
            'status'      => ImageGenerationResult::STATUS_COMPLETED,
            'file_name'   => 'PLT001_Areca_Palm_2.png',
            's3_path'     => 'sentinel/keep.png',
            'public_url'  => 'sentinel-url',
        ]);
        ImageBatch::where('id', $batch->id)->update(['generated_count' => 1]);

        GenerateImageBatchJob::dispatch($batch->id);

        // Only the 2 missing slots hit OpenAI.
        Http::assertSentCount(2);
        $this->assertSame('sentinel/keep.png', $existing->fresh()->s3_path); // untouched, same Image ID
        $this->assertSame(3, (int) $batch->fresh()->generated_count);
        $this->assertSame(ImageBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_cancelled_batch_stops_immediately(): void
    {
        $this->fakeOpenAi();
        $batch = $this->makeBatch([['PLT001', 'Areca Palm', 'A palm']], imagesPerPlant: 2);
        $batch->update(['status' => ImageBatch::STATUS_CANCELLED]);

        (new GenerateImageBatchJob($batch->id))->handle(app(\Marvel\Ai\OpenAiImageClient::class));

        Http::assertSentCount(0);
        $this->assertSame(0, ImageGenerationResult::count());
    }

    public function test_reference_images_route_through_edits_endpoint(): void
    {
        Storage::disk('s3')->put('refs/ref_1.png', 'ref-bytes');
        $this->fakeOpenAi();

        $batch = $this->makeBatch(
            [['PLT001', 'Areca Palm', 'A lush areca palm']],
            ['reference_images' => ['refs/ref_1.png']],
            imagesPerPlant: 1,
        );

        GenerateImageBatchJob::dispatch($batch->id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/images/edits'));
        $this->assertSame(ImageBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_zero_success_batch_finalizes_as_failed(): void
    {
        $this->fakeOpenAi();
        $batch = $this->makeBatch([['PLT001', 'Areca Palm', 'FAILME']], imagesPerPlant: 1);

        GenerateImageBatchJob::dispatch($batch->id);

        $this->assertSame(ImageBatch::STATUS_FAILED, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->zip_paths);
        Mail::assertSent(ImageBatchCompleted::class);
    }
}
