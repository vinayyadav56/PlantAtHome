<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Services\Media\MediaUrlService;
use Tests\TestCase;

/**
 * URL derivation rules — pure config+string logic, no DB, no S3. Models are
 * built unsaved with setRelation, so every case is exactly the inputs named.
 */
final class MediaUrlServiceTest extends TestCase
{
    private MediaUrlService $urls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urls = new MediaUrlService();
        config([
            'filesystems.disks.s3.url' => 'https://cdn.plantathome.in/',
            'filesystems.disks.s3.bucket' => 'plantathome-media-prod',
            'filesystems.disks.s3.region' => 'ap-south-1',
            'media.env' => 'staging',
        ]);
    }

    private function version(array $attrs): MediaItemVersion
    {
        return (new MediaItemVersion())->forceFill($attrs);
    }

    public function test_base_prefers_the_configured_cdn_url_and_trims_the_slash(): void
    {
        $this->assertSame('https://cdn.plantathome.in', $this->urls->base());
    }

    public function test_base_falls_back_to_the_raw_s3_host(): void
    {
        config(['filesystems.disks.s3.url' => null]);
        $this->assertSame(
            'https://plantathome-media-prod.s3.ap-south-1.amazonaws.com',
            $this->urls->base()
        );
    }

    public function test_url_serves_the_variant_and_falls_back_to_the_original(): void
    {
        $v = $this->version([
            'original_key' => 'media/s/products/u1/v1/original.jpg',
            'variants' => ['large' => 'media/s/products/u1/v1/large.webp'],
        ]);
        $this->assertSame('https://cdn.plantathome.in/media/s/products/u1/v1/large.webp', $this->urls->url($v, 'large'));
        // A variant that was never generated (still processing) → original.
        $this->assertSame('https://cdn.plantathome.in/media/s/products/u1/v1/original.jpg', $this->urls->url($v, 'thumbnail'));
        $this->assertSame('https://cdn.plantathome.in/media/s/products/u1/v1/original.jpg', $this->urls->url($v));
    }

    public function test_url_falls_back_to_external_url_for_adopted_foreign_images(): void
    {
        $v = $this->version(['original_key' => null, 'external_url' => 'https://inaturalist.org/photos/9/large.jpg']);
        $this->assertSame('https://inaturalist.org/photos/9/large.jpg', $this->urls->url($v));
        $this->assertSame('https://inaturalist.org/photos/9/large.jpg', $this->urls->url($v, 'thumbnail'));

        $this->assertNull($this->urls->url($this->version(['original_key' => null, 'external_url' => null])));
    }

    public function test_attachment_payload_is_the_exact_legacy_shape(): void
    {
        $item = (new MediaItem())->forceFill(['id' => 42]);
        $item->setRelation('liveVersion', $this->version([
            'original_key' => 'media/p/products/u1/v3/original.jpg',
            'variants' => [
                'thumbnail' => 'media/p/products/u1/v3/thumbnail.webp',
                'large' => 'media/p/products/u1/v3/large.webp',
            ],
        ]));

        $this->assertSame([
            'id' => 42,
            'original' => 'https://cdn.plantathome.in/media/p/products/u1/v3/large.webp',
            'thumbnail' => 'https://cdn.plantathome.in/media/p/products/u1/v3/thumbnail.webp',
        ], $this->urls->attachmentPayload($item));

        // No large variant yet → original stands in; no thumbnail → original again.
        $item->setRelation('liveVersion', $this->version(['original_key' => 'media/p/products/u1/v4/original.jpg']));
        $payload = $this->urls->attachmentPayload($item);
        $this->assertSame($payload['original'], $payload['thumbnail']);

        // No live version → no payload.
        $item->setRelation('liveVersion', null);
        $this->assertNull($this->urls->attachmentPayload($item));
    }

    public function test_storage_key_carries_the_env_segment_hint_uuid_and_version(): void
    {
        $item = (new MediaItem())->forceFill(['uuid' => 'aaaa-bbbb', 'entity_hint' => 'products']);
        $this->assertSame('media/s/products/aaaa-bbbb/v3/original.jpg', $this->urls->storageKey($item, 3, 'original.jpg'));

        config(['media.env' => 'production']);
        $this->assertSame('media/p/products/aaaa-bbbb/v1/original.png', $this->urls->storageKey($item, 1, 'original.png'));

        // Unknown env falls back to the staging segment; a missing hint to misc.
        config(['media.env' => 'local']);
        $bare = (new MediaItem())->forceFill(['uuid' => 'cccc']);
        $this->assertSame('media/s/misc/cccc/v1/f.jpg', $this->urls->storageKey($bare, 1, 'f.jpg'));
    }

    public function test_keys_are_percent_encoded_per_segment_with_slashes_preserved(): void
    {
        $v = $this->version(['original_key' => 'plants/monstera deliciosa/photo,1 (final).jpg']);
        $this->assertSame(
            'https://cdn.plantathome.in/plants/monstera%20deliciosa/photo%2C1%20%28final%29.jpg',
            $this->urls->url($v)
        );
    }
}
