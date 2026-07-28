<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\AiLoraModel;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Jobs\GenerateImageBatchJob;

/** POST /api/ai-image-batches + capabilities: validation, parsing, audit, dispatch. */
final class CreateImageBatchTest extends ImageBatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('packages/marvel/database/migrations/2026_07_27_100000_create_ai_lora_models_table.php'))->up();
    }

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

    /** provider=replicate variant of createBatch() — no model/size/quality needed. */
    private function replicateBatch(array $overrides = [], ?UploadedFile $file = null)
    {
        return $this->postJson('/api/ai-image-batches', array_merge([
            'file'             => $file ?? $this->sheet([
                ['PLT001', 'Areca Palm', 'A lush areca palm in a white pot'],
                ['PLT002', 'Snake Plant', 'A tall snake plant'],
            ]),
            'provider'         => 'replicate',
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

        $res = $this->createBatch()->assertStatus(201);

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

    public function test_custom_output_folder_and_flat_structure(): void
    {
        Queue::fake();
        $this->admin();

        // Custom folder is slugified + stored; flat structure recorded.
        $this->createBatch(['output_folder' => 'Monsoon Plants 2026!', 'file_structure' => 'flat'])
            ->assertStatus(201);
        $batch = ImageBatch::first();
        $this->assertSame('monsoon-plants-2026', $batch->settings['output_folder']);
        $this->assertSame('flat', $batch->settings['file_structure']);
        $this->assertStringEndsWith('/monsoon-plants-2026', $batch->storagePrefix());
        $this->assertSame('flat', $batch->fileStructure());

        // Duplicate live folder is rejected.
        $this->createBatch(['output_folder' => 'monsoon plants 2026'])
            ->assertStatus(422);

        // Default: BATCHxxxxxx prefix + per-plant folders.
        $this->createBatch()->assertStatus(201);
        $default = ImageBatch::orderByDesc('id')->first();
        $this->assertStringEndsWith($default->display_id, $default->storagePrefix());
        $this->assertSame('folders', $default->fileStructure());
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

    /* ── provider=replicate (Flux) ────────────────────────────────────────── */

    public function test_replicate_batch_persists_settings_and_uses_replicate_cost(): void
    {
        Queue::fake();
        config(['services.replicate.model' => 'black-forest-labs/flux-dev']);
        $this->admin();

        $res = $this->replicateBatch(['aspect_ratio' => '4:3'])->assertStatus(201);

        $batch = ImageBatch::first();
        $this->assertSame('replicate', $batch->settings['provider']);
        $this->assertSame('black-forest-labs/flux-dev', $batch->settings['model']);
        $this->assertSame('4:3', $batch->settings['aspect_ratio']);
        $this->assertArrayNotHasKey('lora_model_id', $batch->settings);
        $this->assertSame(4, (int) $batch->total_images); // 2 rows x 2 images
        $this->assertEqualsWithDelta(4 * 0.04, $batch->estimated_cost, 0.0001);

        $this->assertSame('BATCH' . str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT), $res->json('batch.display_id'));
        Queue::assertPushed(GenerateImageBatchJob::class, fn ($job) => $job->queue === 'images');
    }

    public function test_replicate_batch_defaults_aspect_ratio_to_1_1(): void
    {
        Queue::fake();
        $this->admin();

        $this->replicateBatch()->assertStatus(201);

        $this->assertSame('1:1', ImageBatch::first()->settings['aspect_ratio']);
    }

    public function test_replicate_batch_with_lora_persists_lora_fields_and_resolved_model(): void
    {
        Queue::fake();
        $lora = AiLoraModel::create([
            'name'              => 'Forest Style',
            'trigger_word'      => 'PAHSTYLE',
            'replicate_model'   => 'test-owner/forest-style',
            'replicate_version' => 'ver-hash-1',
            'status'            => AiLoraModel::STATUS_SUCCEEDED,
        ]);
        $this->admin();

        $this->replicateBatch(['lora_model_id' => $lora->id])->assertStatus(201);

        $batch = ImageBatch::first();
        $this->assertSame('replicate', $batch->settings['provider']);
        // The LoRA's own destination model wins over the base flux model.
        $this->assertSame('test-owner/forest-style', $batch->settings['model']);
        $this->assertSame($lora->id, $batch->settings['lora_model_id']);
        $this->assertSame('ver-hash-1', $batch->settings['lora_version']);
        $this->assertSame('PAHSTYLE', $batch->settings['trigger_word']);
    }

    public function test_replicate_batch_accepts_lora_model_id_without_an_explicit_provider(): void
    {
        Queue::fake();
        $lora = AiLoraModel::create([
            'name'              => 'Forest Style',
            'trigger_word'      => 'PAHSTYLE',
            'replicate_model'   => 'test-owner/forest-style',
            'replicate_version' => 'ver-hash-1',
            'status'            => AiLoraModel::STATUS_SUCCEEDED,
        ]);
        $this->admin();

        // No `provider` key at all — lora_model_id alone implies replicate.
        $this->postJson('/api/ai-image-batches', [
            'file'             => $this->sheet([['PLT001', 'Areca Palm', 'A lush areca palm']]),
            'lora_model_id'    => $lora->id,
            'images_per_plant' => 1,
        ])->assertStatus(201);

        $this->assertSame('replicate', ImageBatch::first()->settings['provider']);
    }

    public function test_replicate_batch_rejects_a_not_ready_lora_model(): void
    {
        Queue::fake();
        $lora = AiLoraModel::create([
            'name'            => 'Still Training',
            'trigger_word'    => 'PAHSTYLE',
            'replicate_model' => 'test-owner/still-training',
            'status'          => AiLoraModel::STATUS_TRAINING, // no replicate_version yet
        ]);
        $this->admin();

        $res = $this->replicateBatch(['lora_model_id' => $lora->id])->assertStatus(422);
        $this->assertStringContainsString('not ready yet', $res->json('message'));

        $this->assertSame(0, ImageBatch::count());
        Queue::assertNothingPushed();
    }

    public function test_lora_model_id_with_an_explicit_openai_provider_is_rejected(): void
    {
        Queue::fake();
        $lora = AiLoraModel::create([
            'name'              => 'Forest Style',
            'trigger_word'      => 'PAHSTYLE',
            'replicate_model'   => 'test-owner/forest-style',
            'replicate_version' => 'ver-hash-1',
            'status'            => AiLoraModel::STATUS_SUCCEEDED,
        ]);
        $this->admin();

        $res = $this->createBatch(['provider' => 'openai', 'lora_model_id' => $lora->id])->assertStatus(422);
        $this->assertStringContainsString("requires provider 'replicate'", $res->json('message'));

        $this->assertSame(0, ImageBatch::count());
        Queue::assertNothingPushed();
    }

    public function test_replicate_batch_ignores_reference_images_without_erroring(): void
    {
        Queue::fake();
        $this->admin();

        $this->replicateBatch([
            'reference_images' => [UploadedFile::fake()->image('ref.png')],
        ])->assertStatus(201);

        $batch = ImageBatch::first();
        $this->assertEmpty($batch->reference_images);
    }
}
