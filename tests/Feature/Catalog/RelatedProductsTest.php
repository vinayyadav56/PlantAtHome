<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Repositories\ProductRepository;
use Tests\TestCase;

/**
 * "Frequently bought together" and "You may also like" read ProductRepository::fetchRelated.
 * Until 2026-10-14 it only matched "shares a category", so draft and test rows came first.
 * It now returns only what a shopper can buy, from the same categories, nearest in price first.
 * In-memory sqlite with the ProductListRatingsPayloadTest table idiom.
 */
final class RelatedProductsTest extends TestCase
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
            $t->boolean('in_stock')->default(true);
            $t->integer('quantity')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
        // The repository's product lookup reads the site settings row.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => '{}', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('types')->insert(['id' => 1, 'name' => 'Plants', 'slug' => 'plants']);
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Indoor', 'slug' => 'indoor'],
            ['id' => 2, 'name' => 'Outdoor', 'slug' => 'outdoor'],
        ]);
        $rows = [
            [1, 'Monstera (anchor)', 'monstera', 500, 1, []],
            [2, 'Near below', 'near-below', 480, 1, []],
            [3, 'Nearest above', 'nearest-above', 510, 1, []],
            [4, 'Pricey', 'pricey', 900, 1, []],
            [5, 'Draft', 'draft', 500, 1, ['status' => 'draft']],
            [6, 'Out of stock', 'oos', 500, 1, ['in_stock' => false]],
            [7, 'Unlisted', 'unlisted', 500, 1, ['listing_enabled' => false]],
            [8, 'Unpriced', 'unpriced', 0, 1, ['min_price' => 0, 'max_price' => 0]],
            [9, 'Other category', 'other', 500, 2, []],
        ];
        foreach ($rows as [$id, $name, $slug, $price, $category, $extra]) {
            DB::table('products')->insert(array_merge([
                'id' => $id, 'name' => $name, 'slug' => $slug, 'type_id' => 1,
                'price' => $price, 'min_price' => $price, 'max_price' => $price,
            ], $extra));
            DB::table('category_product')->insert(['product_id' => $id, 'category_id' => $category]);
        }
    }

    public function test_related_products_are_buyable_same_category_and_nearest_in_price(): void
    {
        $ids = app(ProductRepository::class)->fetchRelated('monstera', 10, 'en')->pluck('id')->all();

        $this->assertSame([3, 2, 4], $ids, 'nearest price first; drafts, out of stock, unlisted, unpriced, other categories and the product itself are left out');
    }
}
