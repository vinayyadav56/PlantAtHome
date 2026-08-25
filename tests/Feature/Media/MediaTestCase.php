<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Services\Media\MediaService;
use Marvel\Services\Media\MediaVariantGenerator;
use Tests\TestCase;

/**
 * Shared harness for the media suite: sqlite :memory: with the three media
 * tables mirroring 2026_08_26_000001, a faked s3 disk, a faked queue (uploads
 * stay DRAFT until process() is called explicitly), media.env=staging and a
 * fixed CDN base so URL assertions are deterministic.
 */
abstract class MediaTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'media.env' => 'staging',
            'filesystems.disks.s3.url' => 'https://media.test',
        ]);
        DB::purge('sqlite');
        $this->createMediaTables();
        Storage::fake('s3');
        Queue::fake();
    }

    protected function createMediaTables(): void
    {
        Schema::create('media_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('uuid')->unique();
            $t->string('entity_hint', 40)->nullable();
            $t->unsignedBigInteger('live_version_id')->nullable()->index();
            $t->string('origin_env', 10)->default('production');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('media_item_versions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('media_item_id');
            $t->unsignedInteger('version_number');
            $t->string('status', 12)->default('draft');
            $t->string('origin_env', 10);
            $t->string('original_key', 700)->nullable();
            $t->string('external_url', 1000)->nullable();
            $t->json('variants')->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('checksum', 64)->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['media_item_id', 'version_number']);
        });
        Schema::create('media_attachments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('media_item_id');
            $t->string('attachable_type', 120);
            $t->unsignedBigInteger('attachable_id');
            $t->string('role', 30)->default('gallery');
            $t->integer('position')->default(0);
            $t->timestamps();
            $t->unique(['attachable_type', 'attachable_id', 'media_item_id', 'role'], 'media_attachments_unique');
        });
    }

    /** Mirror of the real product_images schema (+ the media_item_id bridge). */
    protected function createProductImagesTable(): void
    {
        Schema::create('product_images', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('media_item_id')->nullable();
            $t->text('url');
            $t->text('thumbnail_url')->nullable();
            $t->string('alt', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_primary')->default(false);
            $t->boolean('in_gallery')->default(true);
            $t->string('source', 30)->nullable();
            $t->string('attribution', 500)->nullable();
            $t->timestamps();
        });
    }

    protected function svc(): MediaService
    {
        return app(MediaService::class);
    }

    /**
     * Item + one version in an arbitrary state, its objects put on the fake
     * disk. LIVE versions also flip the item pointer, like publish() would.
     */
    protected function makeVersion(
        string $status,
        string $originEnv = 'staging',
        array $overrides = [],
        ?MediaItem $item = null,
        int $number = 1
    ): MediaItemVersion {
        $item ??= MediaItem::create(['entity_hint' => 'products', 'origin_env' => $originEnv]);
        $segment = $originEnv === 'production' ? 'p' : 's';
        $key = "media/{$segment}/products/{$item->uuid}/v{$number}/original.jpg";
        $version = new MediaItemVersion();
        $version->forceFill($overrides + [
            'media_item_id' => $item->id,
            'version_number' => $number,
            'status' => $status,
            'origin_env' => $originEnv,
            'original_key' => $key,
            'variants' => [
                'thumbnail' => dirname($key) . '/thumbnail.webp',
                'large' => dirname($key) . '/large.webp',
            ],
        ])->save();
        foreach ($version->allKeys() as $k) {
            Storage::disk('s3')->put($k, 'x');
        }
        if ($status === MediaItemVersion::LIVE) {
            $item->forceFill(['live_version_id' => $version->id])->save();
        }

        return $version;
    }

    /** Variant generation without gd/spatie: writes fixed derivative keys. */
    protected function bindFakeGenerator(): void
    {
        $this->app->instance(MediaVariantGenerator::class, new class extends MediaVariantGenerator {
            public function generate(MediaItemVersion $version): array
            {
                if (!$version->original_key) {
                    return [];
                }
                $prefix = preg_replace('/\/original\.[^.\/]+$/', '', $version->original_key);
                $out = [];
                foreach (['thumbnail', 'large'] as $name) {
                    $key = "{$prefix}/{$name}.webp";
                    Storage::disk('s3')->put($key, $name);
                    $out[$name] = $key;
                }

                return $out;
            }
        });
    }

    protected function uploadItem(array $attrs = ['entity_hint' => 'products'], int $byUserId = 7): MediaItem
    {
        return $this->svc()->upload(
            UploadedFile::fake()->create('leaf.jpg', 4, 'image/jpeg'),
            $attrs,
            $byUserId
        );
    }
}
