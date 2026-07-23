<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Jobs\GenerateImageBatchJob;

/** POST /api/ai-image-batches + capabilities: validation, parsing, audit, dispatch. */
final class CreateImageBatchTest extends ImageBatchTestCase
{
    private function createBatch(array $overrides = [], ?UploadedFile $file = null)
    {
        return $this->postJson('/api/ai-image-batches', array_merge([
            'file'             => $file ?? $this->sheet([
                ['PLT001', 'Areca Palm', 'A lush areca palm in a white pot'],
                ['PLT002', 'Snake Plant', 'A tall snake plant'],
            ]),
            'model'            => 'gpt-image-1',
            'size'             => '1024x1024',
            'quality'          => 'low',
            'style'            => 'natural',
            'background'       => 'auto',
            'images_per_plant' => 2,
        ], $overrides));
    }

    public function test_capabilities_is_a_config_dump(): void
    {
        $this->admin();

        $res = $this->getJson('/api/ai-image-batches/capabilities')->assertOk();

        $models = collect($res->json('models'));
        $gpt    = $models->firstWhere('id', 'gpt-image-1');
        $this->assertNotNull($gpt);
        $this->assertSame(['1536x1024', '1024x1536', '1024x1024', 'auto'], $gpt['sizes']);
        $this->assertSame(['low', 'medium', 'high', 'auto'], $gpt['qualities']);
        $this->assertTrue($gpt['supports_reference_images']);
        $this->assertFalse($models->firstWhere('id', 'dall-e-3')['supports_reference_images']);
        $this->assertSame(200.0, (float) $res->json('limits.max_estimated_cost'));
        $this->assertArrayHasKey('max_rows', $res->json('limits'));
    }

    public function test_happy_path_creates_rows_and_dispatches_on_images_queue(): void
    {
        Queue::fake();
        $user = $this->admin();

        $res = $this->createBatch();
        if ($res->status() !== 201) {
            fwrite(STDERR, "\nDEBUG create body: " . json_encode($res->json()) . "\n");
        }
        $res->assertStatus(201);

        $batch = ImageBatch::first();
        $this->assertSame('BATCH' . str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT), $res->json('batch.display_id'));
        $this->assertSame($user->id, $batch->uploaded_by);
        $this->assertSame($user->email, $batch->notify_email); // defaults to uploader
        $this->assertSame(2, $batch->valid_rows);
        $this->assertSame(4, $batch->total_images);
        $this->assertEqualsWithDelta(4 * 0.011, $batch->estimated_cost, 0.0001);
        $this->assertSame('gpt-image-1', $batch->settings['model']);

        $rows = ImageGenerationJob::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('PLT001_Areca_Palm', $rows[0]->folder_name);
        $this->assertSame(2, (int) $rows[0]->images_requested);

        Queue::assertPushed(GenerateImageBatchJob::class, fn ($job) => $job->queue === 'images');
        Storage::disk('local')->assertExists($batch->original_file);
    }

    public function test_invalid_rows_are_recorded_with_line_numbers(): void
    {
        Queue::fake();
        $this->admin();

        $this->createBatch([], $this->sheet([
            ['PLT001', 'Areca Palm', 'Good prompt'],
            ['', 'No Code', 'Prompt here'],       // line 3: missing plant_code
            ['PLT003', 'No Prompt', ''],          // line 4: missing prompt
        ]))->assertStatus(201);

        $batch = ImageBatch::first();
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(2, $batch->row_error_count);
        $lines = array_column($batch->row_errors, 'line');
        $this->assertSame([3, 4], $lines);
    }

    public function test_folder_name_is_sanitized_and_duplicates_do_not_collide(): void
    {
        Queue::fake();
        $this->admin();

        $this->createBatch([], $this->sheet([
            ['PLT 001!', 'Areca / Palm', 'Prompt one'],
            ['PLT 001!', 'Areca / Palm', 'Prompt two'],
        ]))->assertStatus(201);

        $folders = ImageGenerationJob::orderBy('id')->pluck('folder_name')->all();
        $this->assertSame('PLT_001_Areca_Palm', $folders[0]);
        $this->assertNotSame($folders[0], $folders[1]);
        $this->assertStringStartsWith('PLT_001_Areca_Palm_r', $folders[1]);
    }

    public function test_rejects_unknown_options_and_refs_on_unsupported_model(): void
    {
        Queue::fake();
        $this->admin();

        $this->createBatch(['model' => 'not-a-model'])->assertStatus(422);
        $this->createBatch(['size' => '999x999'])->assertStatus(422);
        $this->createBatch(['quality' => 'ultra'])->assertStatus(422);
        $this->createBatch(['model' => 'dall-e-3', 'size' => '1024x1024', 'quality' => 'hd', 'style' => 'vivid', 'background' => null, 'reference_images' => [UploadedFile::fake()->image('ref.png')]])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Model 'dall-e-3' does not support reference images."]);

        $this->assertSame(0, ImageGenerationJob::count());
        Queue::assertNothingPushed();
    }

    public function test_cost_cap_rejects_and_leaves_failed_audit_row(): void
    {
        Queue::fake();
        config(['image-batches.max_estimated_cost' => 0.02]);
        $this->admin();

        $res = $this->createBatch()->assertStatus(422);
        $this->assertEqualsWithDelta(0.044, (float) $res->json('estimated_cost'), 0.0001); // 4 imgs × $0.011

        $batch = ImageBatch::first();
        $this->assertSame(ImageBatch::STATUS_FAILED, $batch->status);
        $this->assertSame(0, ImageGenerationJob::count()); // job rows discarded
        Queue::assertNothingPushed();
    }

    public function test_row_limit_rejects_the_upload(): void
    {
        Queue::fake();
        config(['image-batches.max_rows' => 1]);
        $this->admin();

        $this->createBatch()->assertStatus(422);
        $this->assertSame(0, ImageGenerationJob::count());
        Queue::assertNothingPushed();
    }

    public function test_reference_images_are_stored_on_s3(): void
    {
        Queue::fake();
        $this->admin();

        $this->createBatch([
            'reference_images' => [
                UploadedFile::fake()->image('ref-a.png'),
                UploadedFile::fake()->image('ref-b.jpg'),
            ],
        ])->assertStatus(201);

        $batch = ImageBatch::first();
        $this->assertCount(2, $batch->reference_images);
        foreach ($batch->reference_images as $key) {
            Storage::disk('s3')->assertExists($key);
            $this->assertStringContainsString($batch->display_id . '/_reference/', $key);
        }
    }

    public function test_list_show_and_rows_endpoints(): void
    {
        Queue::fake();
        $this->admin();
        $this->createBatch()->assertStatus(201);

        $list = $this->getJson('/api/ai-image-batches')->assertOk();
        $this->assertSame(1, $list->json('total'));
        $this->assertArrayHasKey('progress', $list->json('data.0'));

        $id = $list->json('data.0.id');
        $this->getJson("/api/ai-image-batches/{$id}")->assertOk()->assertJsonPath('valid_rows', 2);
        $rows = $this->getJson("/api/ai-image-batches/{$id}/rows")->assertOk();
        $this->assertSame(2, $rows->json('total'));
        $this->assertSame('PLT001', $rows->json('data.0.plant_code'));

        // No zip yet → download 404s.
        $this->getJson("/api/ai-image-batches/{$id}/download")->assertStatus(404);
    }
}
