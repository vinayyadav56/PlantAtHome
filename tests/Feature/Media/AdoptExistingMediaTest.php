<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\MediaAttachment;
use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;

/**
 * media:adopt-existing — legacy product_images rows become media items whose
 * v1 LIVE version points at the EXISTING S3 object. Keys are adopted verbatim
 * (urldecoded — the stored URL is percent-encoded, the S3 key is literal), a
 * foreign host becomes external_url, and one unrecognized bucket layout
 * refuses the whole run (the purge-command precedent).
 */
final class AdoptExistingMediaTest extends MediaTestCase
{
    private const BUCKET_HOST = 'https://plantathome-media-prod.s3.ap-south-1.amazonaws.com';
    private const CDN_HOST = 'https://media.plantathome.in';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProductImagesTable();
        config([
            'filesystems.disks.s3.bucket' => 'plantathome-media-prod',
            'filesystems.disks.s3.region' => 'ap-south-1',
            'filesystems.disks.s3.url' => self::CDN_HOST,
        ]);
    }

    private function legacyRow(array $attrs): int
    {
        return (int) DB::table('product_images')->insertGetId($attrs + [
            'product_id' => 1,
            'sort_order' => 0,
            'is_primary' => false,
            'in_gallery' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_bucket_urls_are_adopted_verbatim_and_foreign_urls_become_external(): void
    {
        $primaryId = $this->legacyRow([
            'url' => self::BUCKET_HOST . '/plants/monstera%20deliciosa/1%2C2.jpg',
            'thumbnail_url' => self::CDN_HOST . '/plants/monstera%20deliciosa/thumb.jpg',
            'is_primary' => true,
        ]);
        $foreignId = $this->legacyRow([
            'url' => 'https://inaturalist-open-data.s3.amazonaws.com/photos/123/original.jpg',
            'sort_order' => 1,
        ]);

        $this->artisan('media:adopt-existing', ['--force' => true])->assertExitCode(0);

        $primary = DB::table('product_images')->find($primaryId);
        $this->assertNotNull($primary->media_item_id);
        $adopted = MediaItem::query()->find($primary->media_item_id);
        $this->assertSame('adoption', $adopted->meta['source']);
        $v1 = $adopted->liveVersion;
        $this->assertSame(MediaItemVersion::LIVE, $v1->status);
        $this->assertSame('production', $v1->origin_env);
        // urldecoded, verbatim — %20 and %2C become literal bytes of the key.
        $this->assertSame('plants/monstera deliciosa/1,2.jpg', $v1->original_key);
        $this->assertNull($v1->external_url);
        $this->assertSame(['thumbnail' => 'plants/monstera deliciosa/thumb.jpg'], $v1->variants);
        $this->assertSame('main', MediaAttachment::query()->where('media_item_id', $adopted->id)->value('role'));

        $foreign = DB::table('product_images')->find($foreignId);
        $fv = MediaItem::query()->find($foreign->media_item_id)->liveVersion;
        $this->assertNull($fv->original_key);
        $this->assertSame('https://inaturalist-open-data.s3.amazonaws.com/photos/123/original.jpg', $fv->external_url);
    }

    public function test_an_unrecognized_bucket_layout_refuses_the_whole_run(): void
    {
        $this->legacyRow(['url' => self::BUCKET_HOST . '/plants/ficus/1.jpg']);
        $this->legacyRow(['url' => self::BUCKET_HOST . '/shop-assets/visa.png']);

        $this->artisan('media:adopt-existing', ['--force' => true])->assertExitCode(1);

        $this->assertSame(0, MediaItem::query()->count(), 'the safe-looking rows must not be adopted either');
        $this->assertSame(0, DB::table('product_images')->whereNotNull('media_item_id')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->legacyRow(['url' => self::BUCKET_HOST . '/plants/ficus/1.jpg']);

        $this->artisan('media:adopt-existing', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, MediaItem::query()->count());
        $this->assertSame(0, DB::table('product_images')->whereNotNull('media_item_id')->count());
    }

    public function test_rollback_removes_only_adoption_rows_and_nulls_the_backfill(): void
    {
        $adoptedRowId = $this->legacyRow(['url' => self::BUCKET_HOST . '/plants/ficus/1.jpg']);
        $this->artisan('media:adopt-existing', ['--force' => true])->assertExitCode(0);

        // A real upload item linked into the serving cache must survive.
        $uploadItem = MediaItem::create(['entity_hint' => 'products', 'meta' => ['source' => 'upload']]);
        $uploadVersion = $this->makeVersion(MediaItemVersion::LIVE, 'staging', [], $uploadItem);
        $uploadRowId = $this->legacyRow([
            'url' => self::CDN_HOST . '/' . $uploadVersion->original_key,
            'media_item_id' => $uploadItem->id,
            'sort_order' => 1,
        ]);

        $this->artisan('media:adopt-existing', ['--rollback' => true, '--force' => true])->assertExitCode(0);

        $this->assertNull(DB::table('product_images')->find($adoptedRowId)->media_item_id);
        $this->assertSame([$uploadItem->id], MediaItem::query()->pluck('id')->all());
        $this->assertSame($uploadItem->id, DB::table('product_images')->find($uploadRowId)->media_item_id);
        $this->assertDatabaseHas('media_item_versions', ['id' => $uploadVersion->id]);
    }

    public function test_rollback_refuses_an_adopted_item_someone_built_on(): void
    {
        $this->legacyRow(['url' => self::BUCKET_HOST . '/plants/ficus/1.jpg']);
        $this->artisan('media:adopt-existing', ['--force' => true])->assertExitCode(0);
        $adopted = MediaItem::query()->firstOrFail();
        $this->makeVersion(MediaItemVersion::DRAFT, 'staging', [], $adopted, 2);

        $this->artisan('media:adopt-existing', ['--rollback' => true, '--force' => true])->assertExitCode(1);

        $this->assertDatabaseHas('media_items', ['id' => $adopted->id]);
        $this->assertSame(2, $adopted->versions()->count());
    }

    public function test_rerun_skips_already_adopted_rows(): void
    {
        $this->legacyRow(['url' => self::BUCKET_HOST . '/plants/ficus/1.jpg']);
        $this->artisan('media:adopt-existing', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, MediaItem::query()->count());

        $this->artisan('media:adopt-existing', ['--force' => true])->assertExitCode(0);

        $this->assertSame(1, MediaItem::query()->count(), 'a re-run must adopt nothing new');
        $this->assertSame(1, MediaItemVersion::query()->count());
    }
}
