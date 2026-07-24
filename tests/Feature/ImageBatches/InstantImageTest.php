<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\InstantImage;
use Marvel\Jobs\GenerateInstantImageJob;

/** Instant (single-image) generation — the consolidated Node-service flow. */
final class InstantImageTest extends ImageBatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('packages/marvel/database/migrations/2026_07_24_100000_create_instant_images_table.php'))->up();
    }

    public function test_instant_create_normalizes_legacy_options_and_dispatches(): void
    {
        Queue::fake();
        $this->admin();

        // Legacy UI values: `resolution` param name, DALL·E-era quality/background.
        $res = $this->postJson('/api/ai-images/instant', [
            'prompt'     => 'A golden pothos in a white pot',
            'resolution' => '1024x1024',
            'quality'    => 'hd',        // not a gpt-image-1 tier → falls back to high
            'style'      => 'natural',
            'background' => 'white',     // not a gpt-image-1 background → dropped
        ])->assertStatus(201);

        $row = InstantImage::findOrFail($res->json('id'));
        $this->assertSame('pending', $row->status);
        $this->assertSame('gpt-image-1', $row->settings['model']);
        $this->assertSame('1024x1024', $row->settings['size']);
        $this->assertSame('high', $row->settings['quality']);
        $this->assertSame('natural', $row->settings['style']);
        $this->assertNull($row->settings['background']);

        Queue::assertPushed(GenerateInstantImageJob::class, fn ($job) => $job->queue === 'images-instant');
    }

    public function test_instant_job_generates_and_stores(): void
    {
        $this->fakeOpenAi();
        $this->admin();

        $res = $this->postJson('/api/ai-images/instant', [
            'prompt' => 'A tall snake plant',
            'size'   => '1024x1024',
            'quality' => 'low',
        ])->assertStatus(201); // sync queue → job ran inline

        $id   = $res->json('id');
        $show = $this->getJson("/api/ai-images/instant/{$id}")->assertOk();
        $this->assertSame('completed', $show->json('status'));
        $this->assertNotNull($show->json('url'));

        $row = InstantImage::findOrFail($id);
        Storage::disk('s3')->assertExists($row->s3_path);
        $this->assertSame(self::PNG_BYTES, Storage::disk('s3')->get($row->s3_path));

        // History strip.
        $list = $this->getJson('/api/ai-images/instant?limit=5')->assertOk();
        $this->assertSame($id, $list->json('0.id'));
    }

    public function test_instant_failure_records_error(): void
    {
        $this->fakeOpenAi();
        $this->admin();

        $res  = $this->postJson('/api/ai-images/instant', ['prompt' => 'FAILME'])->assertStatus(201);
        $show = $this->getJson('/api/ai-images/instant/' . $res->json('id'))->assertOk();
        $this->assertSame('failed', $show->json('status'));
        $this->assertStringContainsString('Prompt rejected', $show->json('error'));
    }

    public function test_instant_reference_images_use_the_edits_endpoint(): void
    {
        $this->fakeOpenAi();
        $this->admin();

        $res = $this->postJson('/api/ai-images/instant', [
            'prompt'           => 'A monstera like the reference',
            'reference_images' => [UploadedFile::fake()->image('ref.png')],
        ])->assertStatus(201);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/images/edits'));
        $row = InstantImage::findOrFail($res->json('id'));
        $this->assertSame('completed', $row->status);
        $this->assertCount(1, $row->reference_images);
    }
}
