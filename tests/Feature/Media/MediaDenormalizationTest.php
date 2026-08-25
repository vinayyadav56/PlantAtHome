<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductImage;

/**
 * The legacy serving cache stays byte-compatible: publish/attach/detach/
 * reorder rebuild product_images and products.image/gallery through the
 * existing syncImageColumns(), media-backed rows carry media_item_id, and
 * legacy rows (media_item_id null) are never MediaService's to delete.
 */
final class MediaDenormalizationTest extends MediaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Overlay off: Product name reads must not consult translation tables.
        config(['translation.enabled' => false]);
        $this->createProductImagesTable();
        // Only the columns Product::create + syncImageColumns actually touch
        // (real products is far heavier). products_meta exists because kodeine
        // Metable SELECTs it on every non-quiet Product save.
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->json('image')->nullable();
            $t->json('gallery')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->nullable();
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Monstera Deliciosa', 'slug' => 'monstera-deliciosa']);
    }

    public function test_attach_main_and_publish_builds_the_legacy_columns(): void
    {
        $product = $this->product();
        $v = $this->makeVersion(MediaItemVersion::APPROVED);
        $item = $v->item;

        $this->svc()->attach($item, $product, 'main');
        // Not live yet — no serving row may exist.
        $this->assertSame(0, ProductImage::query()->count());

        $this->svc()->publish($v);

        $row = ProductImage::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($item->id, $row->media_item_id);
        $this->assertTrue($row->is_primary);
        $this->assertTrue($row->in_gallery);
        $expectedUrl = 'https://media.test/' . $v->variants['large'];
        $expectedThumb = 'https://media.test/' . $v->variants['thumbnail'];
        $this->assertSame($expectedUrl, $row->url);
        $this->assertSame($expectedThumb, $row->thumbnail_url);

        $product->refresh();
        $this->assertSame(['id', 'original', 'thumbnail'], array_keys($product->image));
        $this->assertSame(
            ['id' => $row->id, 'original' => $expectedUrl, 'thumbnail' => $expectedThumb],
            $product->image
        );
        $this->assertCount(1, $product->gallery);
        $this->assertSame(['id', 'original', 'thumbnail'], array_keys($product->gallery[0]));
    }

    public function test_reorder_moves_sort_order_and_creates_zero_versions(): void
    {
        $product = $this->product();
        $va = $this->makeVersion(MediaItemVersion::APPROVED);
        $vb = $this->makeVersion(MediaItemVersion::APPROVED);
        $this->svc()->attach($va->item, $product, 'gallery');
        $this->svc()->attach($vb->item, $product, 'gallery');
        $this->svc()->publish($va);
        $this->svc()->publish($vb);

        $order = fn () => ProductImage::query()->orderBy('sort_order')->pluck('media_item_id')->all();
        $this->assertSame([$va->media_item_id, $vb->media_item_id], $order());
        $versionsBefore = MediaItemVersion::query()->count();

        $this->svc()->reorder($product, 'gallery', [$vb->media_item_id, $va->media_item_id]);

        $this->assertSame([$vb->media_item_id, $va->media_item_id], $order());
        $this->assertSame(0, (int) $this->attachmentPosition($vb->media_item_id));
        $this->assertSame(1, (int) $this->attachmentPosition($va->media_item_id));
        $this->assertSame($versionsBefore, MediaItemVersion::query()->count(), 'reordering must create no versions');

        // The rebuilt gallery follows the new order; the image stays the first entry.
        $gallery = $product->refresh()->gallery;
        $this->assertSame(
            ['https://media.test/' . $vb->variants['large'], 'https://media.test/' . $va->variants['large']],
            array_column($gallery, 'original')
        );
    }

    public function test_detach_removes_only_the_media_backed_row(): void
    {
        $product = $this->product();
        ProductImage::create([
            'product_id' => $product->id,
            'url' => 'https://media.test/plants/monstera-deliciosa/1.jpg',
            'in_gallery' => true,
            'sort_order' => 0,
            'source' => 'inaturalist',
        ]);
        $v = $this->makeVersion(MediaItemVersion::APPROVED);
        $this->svc()->attach($v->item, $product, 'gallery');
        $this->svc()->publish($v);
        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());

        $this->svc()->detach($v->item, $product);

        $rows = ProductImage::query()->where('product_id', $product->id)->get();
        $this->assertCount(1, $rows, 'the legacy row must survive the media detach');
        $this->assertNull($rows->first()->media_item_id);
        $this->assertSame(
            ['https://media.test/plants/monstera-deliciosa/1.jpg'],
            array_column($product->refresh()->gallery, 'original')
        );
    }

    public function test_retiring_the_item_drops_its_serving_row(): void
    {
        $product = $this->product();
        $v = $this->makeVersion(MediaItemVersion::APPROVED);
        $this->svc()->attach($v->item, $product, 'main');
        $this->svc()->publish($v);
        $this->assertNotNull($product->refresh()->image);

        $this->svc()->retire($v->item->refresh());

        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertNull($product->refresh()->image);
        $this->assertNull($product->gallery);
    }

    private function attachmentPosition(int $mediaItemId): int
    {
        return (int) MediaItem::query()->find($mediaItemId)
            ->attachments()->value('position');
    }
}
