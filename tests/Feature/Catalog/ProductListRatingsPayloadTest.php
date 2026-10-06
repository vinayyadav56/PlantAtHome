<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
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

        DB::table('products')->insert(['id' => 1, 'name' => 'Monstera', 'slug' => 'monstera', 'price' => 899, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reviews')->insert([
            ['product_id' => 1, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => 1, 'rating' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function payload(Product $product): array
    {
        // resolve(), not toArray(): `when(false)` leaves a MissingValue that only the
        // resource's resolve/filter step strips — the same step every response goes through.
        return (new ProductResource($product))->resolve(Request::create('/products', 'GET'));
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
