<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * marvel:recompute-city-availability — the orphan-flush guarantee. When a
 * product's vendor inventory disappears entirely (vendor deleted / data wipe),
 * its product_city_availability rows are ORPHANS: the city-first storefront
 * would keep strict-scoping those cities to a stale product list ("different
 * plants per city with zero vendors in the DB"). The command must therefore
 * recompute the union of products with inventory AND products still present
 * in the projection, so orphans get deleted.
 */
final class CityAvailabilityRecomputeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        \Marvel\Database\Models\VendorProductPrice::resetReviewStatics();

        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert([
            ['options' => json_encode([]), 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('products', function (Blueprint $t) {
            // Master Catalog membership + listing switch. Defaulted TRUE in stubs, not FALSE:
            // production starts empty by design, but a fixture that had to opt every product in
            // would make each existing test assert the new gate instead of what it was written for.
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->decimal('cost_price', 12, 2)->nullable();
            $t->decimal('vendor_selling_price', 12, 2)->nullable();
            $t->boolean('is_available')->default(true);
            $t->boolean('track_stock')->default(false);
            $t->integer('stock_qty')->default(0);
            $t->integer('reserved_qty')->default(0);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->string('review_status')->default('approved');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // AvailabilityService::excludeHeldVendors() joins vendor_product_prices →
        // shop to drop suppliers whose KYC is on hold. Without this table the
        // recompute cannot run at all here, even though production is fine.
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('approval_status')->nullable();
            $t->timestamps();
        });

        // Per-variant projection (2026_08_05_000400): variation_option_id 0 is the
        // product-level rollup row.
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city');
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
            $t->decimal('min_price', 12, 2)->nullable();
            $t->decimal('display_price', 14, 2)->nullable();
            $t->integer('stock')->nullable();
            $t->integer('stock_override')->nullable();
            $t->integer('vendor_count')->default(0);
            $t->timestamp('updated_at')->nullable();
        });

        DB::table('products')->insert([
            ['id' => 11, 'name' => 'Areca Palm', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'name' => 'Snake Plant', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ORPHANS: no vendor_product_prices rows exist at all (post vendor wipe),
        // yet the projection still claims city availability.
        DB::table('product_city_availability')->insert([
            ['product_id' => 11, 'city' => 'delhi', 'has_local' => 1, 'has_courier' => 1, 'vendor_count' => 1],
            ['product_id' => 11, 'city' => 'rewari', 'has_local' => 1, 'has_courier' => 0, 'vendor_count' => 1],
            ['product_id' => 22, 'city' => 'mumbai', 'has_local' => 0, 'has_courier' => 1, 'vendor_count' => 1],
        ]);
    }

    public function test_orphaned_projection_rows_are_flushed(): void
    {
        $this->assertSame(3, DB::table('product_city_availability')->count());

        $this->artisan('marvel:recompute-city-availability')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('product_city_availability')->count(),
            'projection rows for products with zero vendor inventory must be deleted'
        );
    }

    public function test_single_product_option_flushes_that_orphan_only(): void
    {
        $this->artisan('marvel:recompute-city-availability', ['--product' => 11])->assertSuccessful();

        $this->assertSame(0, DB::table('product_city_availability')->where('product_id', 11)->count());
        $this->assertSame(1, DB::table('product_city_availability')->where('product_id', 22)->count());
    }
}
