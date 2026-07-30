<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\AiLoraModel;
use Marvel\Database\Models\InstantImage;

/**
 * Replicate LoRA fine-tuning: training creation (download → zip → trainer),
 * the SSRF media-host guard, status refresh mapping, the capabilities block,
 * and lora-styled instant generation. Replicate + media downloads are faked
 * at the HTTP layer (the service is plain Laravel Http).
 */
final class LoraModelTest extends ImageBatchTestCase
{
    private const MEDIA_HOST = 'media.plantathome.test';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '2026_07_24_100000_create_instant_images_table.php',
            '2026_07_27_100000_create_ai_lora_models_table.php',
        ] as $migration) {
            (require base_path('packages/marvel/database/migrations/' . $migration))->up();
        }

        config([
            'services.replicate.api_token'       => 'test-replicate-token',
            'services.replicate.owner'           => 'test-owner',
            'services.replicate.trainer_version' => null,
            // The SSRF guard derives the allowed host from the media disk URL.
            'filesystems.disks.s3.url'           => 'https://' . self::MEDIA_HOST,
        ]);
    }

    /** @return string[] eight valid media-host image URLs */
    private function mediaUrls(int $count = 8): array
    {
        return array_map(
            fn ($i) => 'https://' . self::MEDIA_HOST . "/uploads/plant-{$i}.png",
            range(1, $count)
        );
    }

    private function fakeReplicateTraining(): void
    {
        Http::fake([
            // Trainer version lookup (exact URL — no wildcard).
            'api.replicate.com/v1/models/ostris/flux-dev-lora-trainer' => Http::response([
                'latest_version' => ['id' => 'trainer-ver-1'],
            ]),
            // Launch training.
            'api.replicate.com/v1/models/ostris/flux-dev-lora-trainer/versions/*' => Http::response([
                'id'     => 'train-123',
                'status' => 'starting',
            ], 201),
            // Destination model creation.
            'api.replicate.com/v1/models' => Http::response(['name' => 'forest-style'], 201),
            // Training image downloads from our media host.
            self::MEDIA_HOST . '/*' => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);
    }

    /** @return string[] entry names inside the zip stored on the (faked) s3 disk */
    private function storedZipEntries(): array
    {
        $files = Storage::disk('s3')->allFiles('ai-lora/training');
        $this->assertCount(1, $files, 'exactly one training zip should be stored');

        $path = tempnam(sys_get_temp_dir(), 'lora-zip-');
        file_put_contents($path, Storage::disk('s3')->get($files[0]));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($path);
        sort($names);

        return $names;
    }

    public function test_store_builds_zip_and_starts_training(): void
    {
        $this->fakeReplicateTraining();
        $admin = $this->admin();

        $res = $this->postJson('/api/ai-images/lora-models', [
            'name'         => 'Forest Style',
            'trigger_word' => 'PahStyle', // uppercased server-side
            'image_urls'   => $this->mediaUrls(),
        ])->assertStatus(201);

        $row = AiLoraModel::findOrFail($res->json('model.id'));
        $this->assertSame(AiLoraModel::STATUS_TRAINING, $row->status);
        $this->assertSame('train-123', $row->replicate_training_id);
        $this->assertSame('test-owner/forest-style', $row->replicate_model);
        $this->assertSame('PAHSTYLE', $row->trigger_word);
        $this->assertSame(8, $row->image_count);
        $this->assertSame(1000, $row->steps);
        $this->assertSame(16, $row->lora_rank);
        $this->assertSame($admin->id, $row->requested_by);
        $this->assertNotEmpty($row->settings['zip_url']);
        $this->assertCount(8, $row->settings['image_urls']);

        // No captions → 8 images, zero .txt sidecars.
        $entries = $this->storedZipEntries();
        $this->assertCount(8, $entries);
        $this->assertContains('img_001.png', $entries);
        $this->assertContains('img_008.png', $entries);

        // Destination model created, then the training launched against the
        // resolved trainer version with autocaption ON (no captions given).
        Http::assertSent(fn ($request) => $request->url() === 'https://api.replicate.com/v1/models'
            && $request['owner'] === 'test-owner'
            && $request['name'] === 'forest-style'
            && $request['visibility'] === 'private');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/versions/trainer-ver-1/trainings')
            && $request['destination'] === 'test-owner/forest-style'
            && $request['input']['trigger_word'] === 'PAHSTYLE'
            && $request['input']['autocaption'] === true
            && $request['input']['autocaption_prefix'] === 'PAHSTYLE, '
            && $request['input']['steps'] === 1000
            && $request['input']['lora_rank'] === 16
            && str_ends_with((string) $request['input']['input_images'], '.zip'));
    }

    public function test_store_with_captions_disables_autocaption_and_adds_sidecars(): void
    {
        $this->fakeReplicateTraining();
        $this->admin();

        $this->postJson('/api/ai-images/lora-models', [
            'name'         => 'Captioned Style',
            'trigger_word' => 'PAHSTYLE',
            'image_urls'   => $this->mediaUrls(),
            'captions'     => array_map(fn ($i) => "a plant photo {$i}", range(1, 8)),
        ])->assertStatus(201);

        $entries = $this->storedZipEntries();
        $this->assertCount(16, $entries); // 8 images + 8 caption files
        $this->assertContains('img_001.txt', $entries);
        $this->assertContains('img_008.txt', $entries);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/trainings')
            && $request['input']['autocaption'] === false);
    }

    public function test_store_rejects_urls_off_the_media_host(): void
    {
        Http::fake();
        $this->admin();

        $urls    = $this->mediaUrls();
        $urls[3] = 'https://evil.example.com/steal.png'; // SSRF attempt

        $this->postJson('/api/ai-images/lora-models', [
            'name'         => 'Bad Style',
            'trigger_word' => 'PAHSTYLE',
            'image_urls'   => $urls,
        ])->assertStatus(422);

        $this->assertSame(0, AiLoraModel::count());
        Http::assertNothingSent(); // rejected before ANY server-side fetch

        // http:// on the right host is rejected too.
        $urls    = $this->mediaUrls();
        $urls[0] = 'http://' . self::MEDIA_HOST . '/uploads/plant-1.png';
        $this->postJson('/api/ai-images/lora-models', [
            'name'         => 'Bad Style 2',
            'trigger_word' => 'PAHSTYLE',
            'image_urls'   => $urls,
        ])->assertStatus(422);
    }

    public function test_store_persists_failed_row_when_replicate_errors(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/ostris/flux-dev-lora-trainer' => Http::response([
                'latest_version' => ['id' => 'trainer-ver-1'],
            ]),
            'api.replicate.com/v1/models/ostris/flux-dev-lora-trainer/versions/*' => Http::response([
                'detail' => 'Insufficient credit.',
            ], 402),
            'api.replicate.com/v1/models' => Http::response(['name' => 'broke-style'], 201),
            self::MEDIA_HOST . '/*'       => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);
        $this->admin();

        $this->postJson('/api/ai-images/lora-models', [
            'name'         => 'Broke Style',
            'trigger_word' => 'PAHSTYLE',
            'image_urls'   => $this->mediaUrls(),
        ])->assertStatus(502);

        $row = AiLoraModel::firstOrFail();
        $this->assertSame(AiLoraModel::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('Insufficient credit', $row->error);
    }

    public function test_show_refreshes_and_parses_the_trained_version(): void
    {
        $row = AiLoraModel::create([
            'name'                  => 'Forest Style',
            'trigger_word'          => 'PAHSTYLE',
            'replicate_model'       => 'test-owner/forest-style',
            'replicate_training_id' => 'train-123',
            'status'                => AiLoraModel::STATUS_TRAINING,
        ]);
        Http::fake([
            'api.replicate.com/v1/trainings/train-123' => Http::response([
                'id'     => 'train-123',
                'status' => 'succeeded',
                'output' => ['version' => 'test-owner/forest-style:abc123hash', 'weights' => 'https://replicate.delivery/w.tar'],
            ]),
        ]);
        $this->admin();

        $res = $this->getJson('/api/ai-images/lora-models/' . $row->id)->assertOk();
        $this->assertSame(AiLoraModel::STATUS_SUCCEEDED, $res->json('status'));
        $this->assertSame('abc123hash', $res->json('replicate_version'));
        $this->assertTrue($row->fresh()->isReady());
    }

    public function test_instant_store_rejects_an_unready_lora_model(): void
    {
        $row = AiLoraModel::create([
            'name'            => 'Still Training',
            'trigger_word'    => 'PAHSTYLE',
            'replicate_model' => 'test-owner/still-training',
            'status'          => AiLoraModel::STATUS_TRAINING,
        ]);
        $this->admin();

        $this->postJson('/api/ai-images/instant', [
            'prompt'        => 'A monstera on a shelf',
            'provider'      => 'replicate',
            'lora_model_id' => $row->id,
        ])->assertStatus(422);

        $this->assertSame(0, InstantImage::count());
    }

    public function test_instant_generates_with_a_trained_lora_and_trigger_prefix(): void
    {
        $row = AiLoraModel::create([
            'name'              => 'Forest Style',
            'trigger_word'      => 'PAHSTYLE',
            'replicate_model'   => 'test-owner/forest-style',
            'replicate_version' => 'ver-hash-1',
            'status'            => AiLoraModel::STATUS_SUCCEEDED,
        ]);
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response([
                'id'     => 'pred-1',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/out/img.png'],
            ], 201),
            'replicate.delivery/*' => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);
        $this->admin();

        $res = $this->postJson('/api/ai-images/instant', [
            'prompt'        => 'A monstera on a shelf',
            'provider'      => 'replicate',
            'lora_model_id' => $row->id,
        ])->assertStatus(201); // sync queue → job ran inline

        $instant = InstantImage::findOrFail($res->json('id'));
        $this->assertSame('completed', $instant->status);
        $this->assertSame($row->id, $instant->settings['lora_model_id']);
        $this->assertSame('ver-hash-1', $instant->settings['lora_version']);
        $this->assertSame('test-owner/forest-style', $instant->settings['model']);
        Storage::disk('s3')->assertExists($instant->s3_path);

        // Version-pinned prediction, trigger word prepended exactly once.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/predictions')
            && $request['version'] === 'ver-hash-1'
            && $request['input']['prompt'] === 'PAHSTYLE, A monstera on a shelf');
    }

    public function test_capabilities_exposes_all_lora_models_with_readiness(): void
    {
        AiLoraModel::create([
            'name'            => 'Old Ready',
            'trigger_word'    => 'PAHSTYLE',
            'replicate_model' => 'test-owner/old-ready',
            'replicate_version' => 'ver-1',
            'status'          => AiLoraModel::STATUS_SUCCEEDED,
        ]);
        AiLoraModel::create([
            'name'            => 'New Training',
            'trigger_word'    => 'PAHNEW',
            'replicate_model' => 'test-owner/new-training',
            'status'          => AiLoraModel::STATUS_TRAINING,
        ]);
        $this->admin();

        $models = $this->getJson('/api/ai-image-batches/capabilities')
            ->assertOk()
            ->json('providers.replicate.lora.models');

        $this->assertCount(2, $models); // ALL rows — the UI shows progress too
        // Newest first.
        $this->assertSame('New Training', $models[0]['name']);
        $this->assertFalse($models[0]['ready']);
        $this->assertSame('PAHNEW', $models[0]['trigger_word']);
        $this->assertSame('Old Ready', $models[1]['name']);
        $this->assertTrue($models[1]['ready']);
        $this->assertSame(AiLoraModel::STATUS_SUCCEEDED, $models[1]['status']);
    }

    /* ── own-photo uploads ────────────────────────────────────────────────── */

    /** Re-fake s3 with a media-host url so ->url() matches production shape. */
    private function fakeMediaDisk(): void
    {
        Storage::fake('s3', ['url' => 'https://' . self::MEDIA_HOST]);
    }

    public function test_upload_stores_own_photos_and_returns_urls_in_order(): void
    {
        $this->fakeMediaDisk();
        $this->admin();

        $res = $this->postJson('/api/ai-images/lora-uploads', [
            'images' => [
                UploadedFile::fake()->image('front porch.jpg'),
                UploadedFile::fake()->image('leaf-closeup.png'),
                UploadedFile::fake()->image('pot.webp'),
            ],
        ])->assertStatus(201);

        $images = $res->json('images');
        $this->assertCount(3, $images);
        // Original client filenames, in upload order.
        $this->assertSame(
            ['front porch.jpg', 'leaf-closeup.png', 'pot.webp'],
            array_column($images, 'name')
        );

        // Stored under ai-lora/uploads/YYYY-MM/{uuid}.{ext} on the media disk.
        $month  = now()->format('Y-m');
        $stored = Storage::disk('s3')->allFiles('ai-lora/uploads');
        $this->assertCount(3, $stored);
        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression(
                '#^ai-lora/uploads/' . $month . '/[0-9a-f-]{36}\.(png|jpg|webp)$#',
                $path
            );
        }

        // URLs are https on OUR media host and keep the client extension.
        foreach ($images as $image) {
            $this->assertStringStartsWith('https://' . self::MEDIA_HOST . '/ai-lora/uploads/' . $month . '/', $image['url']);
        }
        $this->assertStringEndsWith('.jpg', $images[0]['url']);
        $this->assertStringEndsWith('.png', $images[1]['url']);
        $this->assertStringEndsWith('.webp', $images[2]['url']);
    }

    public function test_upload_rejects_a_non_image_file(): void
    {
        $this->fakeMediaDisk();
        $this->admin();

        $this->postJson('/api/ai-images/lora-uploads', [
            'images' => [
                UploadedFile::fake()->image('real.png'),
                UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['images.1']);

        // The whole request fails — nothing was stored, not even the valid file.
        $this->assertSame([], Storage::disk('s3')->allFiles('ai-lora/uploads'));
    }

    public function test_uploaded_urls_pass_the_store_ssrf_allowlist(): void
    {
        $this->fakeMediaDisk();
        $this->fakeReplicateTraining();
        $this->admin();

        $urls = $this->postJson('/api/ai-images/lora-uploads', [
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("own-photo-{$i}.jpg"), range(1, 8)),
        ])->assertStatus(201)->json('images.*.url');

        // The uploaded URLs feed store() UNCHANGED and clear its media-host guard.
        $this->postJson('/api/ai-images/lora-models', [
            'name'         => 'Own Photos Style',
            'trigger_word' => 'PAHOWN',
            'image_urls'   => $urls,
        ])->assertStatus(201);

        $row = AiLoraModel::firstOrFail();
        $this->assertSame(AiLoraModel::STATUS_TRAINING, $row->status);
        $this->assertSame(8, $row->image_count);
    }

    /* ── delete ───────────────────────────────────────────────────────────── */

    public function test_destroy_deletes_a_succeeded_row(): void
    {
        $row = AiLoraModel::create([
            'name'              => 'Done Style',
            'trigger_word'      => 'PAHDONE',
            'replicate_model'   => 'test-owner/done-style',
            'replicate_version' => 'ver-1',
            'status'            => AiLoraModel::STATUS_SUCCEEDED,
        ]);
        Http::fake();
        $this->admin();

        $this->deleteJson('/api/ai-images/lora-models/' . $row->id)
            ->assertOk()
            ->assertJsonPath('message', "LoRA model 'Done Style' deleted.");

        $this->assertSame(0, AiLoraModel::count());
        Http::assertNothingSent(); // row-only delete — Replicate is never called
    }

    public function test_destroy_refuses_while_training(): void
    {
        $row = AiLoraModel::create([
            'name'                  => 'In Flight',
            'trigger_word'          => 'PAHFLY',
            'replicate_model'       => 'test-owner/in-flight',
            'replicate_training_id' => 'train-123',
            'status'                => AiLoraModel::STATUS_TRAINING,
        ]);
        $this->admin();

        $res = $this->deleteJson('/api/ai-images/lora-models/' . $row->id)->assertStatus(409);
        $this->assertStringContainsString('cancel the training first', $res->json('message'));
        $this->assertSame(1, AiLoraModel::count());

        // Pending rows are protected the same way.
        $row->update(['status' => AiLoraModel::STATUS_PENDING]);
        $this->deleteJson('/api/ai-images/lora-models/' . $row->id)->assertStatus(409);
        $this->assertSame(1, AiLoraModel::count());
    }
}
