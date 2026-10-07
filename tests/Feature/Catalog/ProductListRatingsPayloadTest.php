<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Resources\ProductResource;
use Tests\TestCase;

/**
 * The listing card has a rating row, but the LIST resource dropped `ratings` /
 * `total_reviews` even though fetchProducts loads the aggregates for every page —
 * so stars never showed on a listing. They are emitted now, but ONLY from the
 * aggregates: the accessors fall back to a query per row, and this resource is
 * also used by FlashSaleResource without the aggregates. Letting the fallback
 * run there would be 2 × N queries on the flash-sale endpoint.
 *
 * In-memory sqlite with the ProductFilterFacetsTest table idiom.
 */
final class ProductListRatingsPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->string('status')->default('publish');
            $t->string('visibility')->default('visibility_public');
            $t->string('product_type')->default('simple');
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('min_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->integer('quantity')->default(1);
            $t->integer('sold_quantity')->default(0);
            $t->boolean('in_flash_sale')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->integer('rating');
            $t->timestamps();
            $t->softDeletes();
        });
        foreach (['types', 'shops'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name')->nullable();
                $t->string('slug')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('plant_attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('scientific_name')->nullable();
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
        // Slim tags/categories on the list payload (card badges) — BelongsToMany pivots.
        foreach (['tags', 'categories'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name');
                $t->string('slug');
                $t->string('language')->default('en');
                $t->timestamps();
                $t->softDeletes();
            });
        }
        Schema::create('product_tag', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('tag_id');
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
        // The city rollup rows overlayCityPrices reads (variation_option_id 0).
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city');
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->decimal('min_price')->nullable();
            $t->decimal('display_price')->nullable();
            $t->integer('stock')->nullable();
            $t->integer('stock_override')->nullable();
            $t->integer('vendor_count')->default(0);
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
        });
        // The overlay news up AvailabilityService → PricingService, which reads
        // settings in its constructor; empty = no vendorPricing options.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });

        DB::table('products')->insert(['id' => 1, 'name' => 'Monstera', 'slug' => 'monstera', 'price' => 899, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reviews')->insert([
            ['product_id' => 1, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => 1, 'rating' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('tags')->insert(['id' => 1, 'name' => 'Bestseller', 'slug' => 'bestseller']);
        DB::table('product_tag')->insert(['product_id' => 1, 'tag_id' => 1]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Indoor Plants', 'slug' => 'indoor-plants']);
        DB::table('category_product')->insert(['product_id' => 1, 'category_id' => 1]);
    }

    private function payload(Product $product): array
    {
        // resolve(), not toArray(): `when(false)` leaves a MissingValue that only the
        // resource's resolve/filter step strips — the same step every response goes through.
        return (new ProductResource($product))->resolve(Request::create('/products', 'GET'));
    }

    /** The real listing overlay (private on the controller), on an already-loaded page. */
    private function overlay($products, string $city): void
    {
        $controller = $this->app->make(ProductController::class);
        $method = new \ReflectionMethod($controller, 'overlayCityPrices');
        $method->setAccessible(true);
        $method->invoke($controller, $products, $city);
    }

    public function test_tags_and_categories_are_emitted_slim_only_when_loaded(): void
    {
        $product = Product::query()
            ->with(['type', 'shop', 'plantAttribute', 'tags:id,name,slug', 'categories:id,name,slug'])
            ->findOrFail(1);

        DB::enableQueryLog();
        $data = $this->payload($product);
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($q) => str_contains($q, 'tags') || str_contains($q, 'categor'));

        $this->assertSame([['id' => 1, 'name' => 'Bestseller', 'slug' => 'bestseller']], $data['tags']);
        $this->assertSame([['id' => 1, 'name' => 'Indoor Plants', 'slug' => 'indoor-plants']], $data['categories']);
        $this->assertCount(0, $queries, 'slim relations come from the page eager load, never a per-row query');

        $bare = Product::query()->with(['type', 'shop', 'plantAttribute'])->findOrFail(1);
        DB::flushQueryLog();
        $data = $this->payload($bare);
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($q) => str_contains($q, 'tags') || str_contains($q, 'categor'));

        $this->assertArrayNotHasKey('tags', $data);
        $this->assertArrayNotHasKey('categories', $data);
        $this->assertCount(0, $queries, 'FlashSaleResource reuses this resource without them — it must not fan out');
    }

    public function test_city_local_is_null_without_the_overlay_and_follows_has_local_with_it(): void
    {
        DB::table('products')->insert([
            ['id' => 2, 'name' => 'Unpriced', 'slug' => 'unpriced', 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Courier only', 'slug' => 'courier-only', 'price' => 599, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('product_city_availability')->insert([
            ['product_id' => 1, 'city' => 'bengaluru', 'variation_option_id' => 0, 'min_price' => 499, 'display_price' => 499, 'vendor_count' => 2, 'has_local' => true],
            // priced at 0 → the overlay skips the row, so "unknown" must survive, never "courier"
            ['product_id' => 2, 'city' => 'bengaluru', 'variation_option_id' => 0, 'min_price' => 0, 'display_price' => 0, 'vendor_count' => 1, 'has_local' => true],
            ['product_id' => 3, 'city' => 'bengaluru', 'variation_option_id' => 0, 'min_price' => 599, 'display_price' => 599, 'vendor_count' => 1, 'has_local' => false],
        ]);
        $page = Product::query()->with(['type', 'shop', 'plantAttribute'])->whereIn('id', [1, 2, 3])->orderBy('id')->get();

        $before = $this->payload($page[0]);
        $this->assertArrayHasKey('city_local', $before);
        $this->assertNull($before['city_local'], 'no city in scope = unknown');

        $this->overlay($page, 'Bangalore'); // alias → the bengaluru rollup rows

        $this->assertTrue($this->payload($page[0])['city_local']);
        $this->assertNull($this->payload($page[1])['city_local']);
        $this->assertFalse($this->payload($page[2])['city_local']);
        $this->assertSame(499.0, (float) $this->payload($page[0])['price'], 'the overlay that set city_local also priced the card');
    }

    public function test_the_list_emits_ratings_from_the_loaded_aggregates(): void
    {
        $product = Product::query()
            ->with(['type', 'shop', 'plantAttribute'])
            ->withCount('reviews')->withAvg('reviews', 'rating')
            ->findOrFail(1);

        DB::enableQueryLog();
        $data = $this->payload($product);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'reviews'));

        $this->assertSame(4.5, $data['ratings']);
        $this->assertSame(2, $data['total_reviews']);
        $this->assertCount(0, $queries, 'the aggregates must be used, never a per-row review query');
    }

    public function test_without_the_aggregates_the_keys_are_absent_and_no_review_query_runs(): void
    {
        $product = Product::query()->with(['type', 'shop', 'plantAttribute'])->findOrFail(1);

        DB::enableQueryLog();
        $data = $this->payload($product);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'reviews'));

        $this->assertArrayNotHasKey('ratings', $data);
        $this->assertArrayNotHasKey('total_reviews', $data);
        $this->assertCount(0, $queries, 'FlashSaleResource reuses this resource without aggregates — it must not fan out');
    }
}
