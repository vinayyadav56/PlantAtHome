<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Exceptions\MarvelBadRequestException;
use Marvel\Jobs\ProcessMediaVersionJob;

/**
 * The version state machine end to end: upload → process → approve → publish
 * → new version → rollback → retire. Publish/rollback/retire are DB pointer
 * flips — every assertion that an S3 object SURVIVES them is the contract
 * that makes the shared bucket and immutable CloudFront caching safe.
 */
final class MediaLifecycleTest extends MediaTestCase
{
    public function test_upload_creates_item_with_an_immutable_v1_draft(): void
    {
        $item = $this->uploadItem();
        $this->assertNotEmpty($item->uuid);
        $this->assertSame('staging', $item->origin_env);

        $v1 = $item->versions()->first();
        $this->assertSame(1, $v1->version_number);
        $this->assertSame(MediaItemVersion::DRAFT, $v1->status);
        $this->assertSame('staging', $v1->origin_env);
        $this->assertSame("media/s/products/{$item->uuid}/v1/original.jpg", $v1->original_key);
        $this->assertSame(7, $v1->uploaded_by);
        Storage::disk('s3')->assertExists($v1->original_key);
        Queue::assertPushedOn(config('media.queue'), ProcessMediaVersionJob::class);
    }

    public function test_process_moves_draft_to_ready_with_variants(): void
    {
        $this->bindFakeGenerator();
        $item = $this->uploadItem();
        $v1 = $item->versions()->first();

        $this->svc()->process($v1);

        $v1->refresh();
        $this->assertSame(MediaItemVersion::READY, $v1->status);
        $this->assertSame(
            ['thumbnail', 'large'],
            array_keys($v1->variants)
        );
        foreach ($v1->variants as $key) {
            Storage::disk('s3')->assertExists($key);
        }

        // Replayed job after the state change is a no-op, not an exception.
        $this->svc()->process($v1);
        $this->assertSame(MediaItemVersion::READY, $v1->refresh()->status);
    }

    public function test_first_publish_flips_the_pointer_and_retires_nothing(): void
    {
        $this->bindFakeGenerator();
        $item = $this->uploadItem();
        $v1 = $item->versions()->first();
        $this->svc()->process($v1);

        // READY + byUserId = approve+publish in one gesture.
        $this->svc()->publish($v1->refresh(), 9);

        $v1->refresh();
        $this->assertSame(MediaItemVersion::LIVE, $v1->status);
        $this->assertSame(9, $v1->approved_by);
        $this->assertNotNull($v1->published_at);
        $this->assertSame($v1->id, $item->refresh()->live_version_id);
        $this->assertSame(0, MediaItemVersion::query()->where('status', MediaItemVersion::RETIRED)->count());
    }

    public function test_second_version_publish_retires_v1_without_touching_its_objects(): void
    {
        [$item, $v1, $v2] = $this->publishedPair();

        $this->assertSame(MediaItemVersion::RETIRED, $v1->refresh()->status);
        $this->assertNotNull($v1->retired_at);
        $this->assertSame(MediaItemVersion::LIVE, $v2->refresh()->status);
        $this->assertSame($v2->id, $item->refresh()->live_version_id);
        foreach (array_merge($v1->allKeys(), $v2->allKeys()) as $key) {
            Storage::disk('s3')->assertExists($key);
        }
    }

    public function test_rollback_restores_v1_and_retires_v2(): void
    {
        [$item, $v1, $v2] = $this->publishedPair();

        $this->svc()->rollback($item->refresh(), $v1->refresh());

        $this->assertSame(MediaItemVersion::LIVE, $v1->refresh()->status);
        $this->assertNull($v1->retired_at);
        $this->assertSame(MediaItemVersion::RETIRED, $v2->refresh()->status);
        $this->assertSame($v1->id, $item->refresh()->live_version_id);
    }

    public function test_retire_clears_the_pointer(): void
    {
        $v1 = $this->makeVersion(MediaItemVersion::LIVE);
        $item = $v1->item;

        $this->svc()->retire($item);
        $this->assertNull($item->refresh()->live_version_id);
        $this->assertSame(MediaItemVersion::RETIRED, $v1->refresh()->status);
        Storage::disk('s3')->assertExists($v1->original_key);

        // Retiring an item with no live version is a silent no-op.
        $this->svc()->retire($item);
        $this->assertNull($item->refresh()->live_version_id);
    }

    public function test_illegal_service_transitions_throw(): void
    {
        $svc = $this->svc();
        $attempts = [
            'draft cannot go live' => fn () => $svc->publish($this->makeVersion(MediaItemVersion::DRAFT)),
            'draft cannot be approved' => fn () => $svc->approve($this->makeVersion(MediaItemVersion::DRAFT), 1),
            'processing cannot go live' => fn () => $svc->publish($this->makeVersion(MediaItemVersion::PROCESSING)),
            'rejected cannot go live' => fn () => $svc->publish($this->makeVersion(MediaItemVersion::REJECTED), 1),
            'retired cannot be rejected' => fn () => $svc->reject($this->makeVersion(MediaItemVersion::RETIRED), 1),
            'live cannot be re-published' => fn () => $svc->publish($this->makeVersion(MediaItemVersion::LIVE)),
        ];
        foreach ($attempts as $why => $attempt) {
            try {
                $attempt();
                $this->fail("expected MarvelBadRequestException: {$why}");
            } catch (MarvelBadRequestException $e) {
                $this->assertStringContainsString('transition', $e->getMessage(), $why);
            }
        }
    }

    public function test_transition_matrix_edges(): void
    {
        $mk = fn (string $s) => (new MediaItemVersion())->forceFill(['status' => $s]);
        $this->assertFalse($mk(MediaItemVersion::DRAFT)->canTransitionTo(MediaItemVersion::LIVE));
        $this->assertFalse($mk(MediaItemVersion::LIVE)->canTransitionTo(MediaItemVersion::DRAFT));
        $this->assertFalse($mk(MediaItemVersion::REJECTED)->canTransitionTo(MediaItemVersion::READY));
        $this->assertFalse($mk(MediaItemVersion::RETIRED)->canTransitionTo(MediaItemVersion::RETIRED));
        $this->assertFalse($mk(MediaItemVersion::READY)->canTransitionTo(MediaItemVersion::LIVE));
        $this->assertTrue($mk(MediaItemVersion::RETIRED)->canTransitionTo(MediaItemVersion::LIVE));
        $this->assertTrue($mk(MediaItemVersion::LIVE)->canTransitionTo(MediaItemVersion::RETIRED));
        $this->assertTrue($mk(MediaItemVersion::READY)->canTransitionTo(MediaItemVersion::APPROVED));
    }

    public function test_rollback_guards(): void
    {
        $v1 = $this->makeVersion(MediaItemVersion::RETIRED, 'staging', ['retired_at' => now()]);
        $item = $v1->item;
        $foreign = $this->makeVersion(MediaItemVersion::RETIRED, 'staging', ['retired_at' => now()]);

        try {
            $this->svc()->rollback($item, $foreign);
            $this->fail('a foreign version must not be rollback-able onto this item');
        } catch (MarvelBadRequestException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }

        $live = $this->makeVersion(MediaItemVersion::LIVE, 'staging', [], $item, 2);
        try {
            $this->svc()->rollback($item, $live);
            $this->fail('only RETIRED versions are rollback targets');
        } catch (MarvelBadRequestException $e) {
            $this->assertStringContainsString('retired', $e->getMessage());
        }

        $v1->forceFill(['purged_at' => now()])->save();
        try {
            $this->svc()->rollback($item, $v1);
            $this->fail('a purged version has no objects left to serve');
        } catch (MarvelBadRequestException $e) {
            $this->assertStringContainsString('un-purged', $e->getMessage());
        }
    }

    public function test_put_new_refuses_an_existing_key(): void
    {
        $this->svc()->putNew('media/s/misc/u/v1/original.jpg', 'first');
        Storage::disk('s3')->assertExists('media/s/misc/u/v1/original.jpg');

        $this->expectException(MarvelBadRequestException::class);
        $this->svc()->putNew('media/s/misc/u/v1/original.jpg', 'second');
    }

    /** item with v1 retired-by-v2-publish, both built through the real flow. */
    private function publishedPair(): array
    {
        $this->bindFakeGenerator();
        $item = $this->uploadItem();
        $v1 = $item->versions()->first();
        $this->svc()->process($v1);
        $this->svc()->publish($v1->refresh(), 9);

        $v2 = $this->svc()->createVersion($item, \Illuminate\Http\UploadedFile::fake()->create('leaf2.jpg', 4, 'image/jpeg'), 7);
        $this->assertSame(2, $v2->version_number);
        $this->assertSame(MediaItemVersion::DRAFT, $v2->status);
        $this->svc()->process($v2);
        $this->svc()->publish($v2->refresh(), 9);

        return [$item, $v1, $v2];
    }
}
