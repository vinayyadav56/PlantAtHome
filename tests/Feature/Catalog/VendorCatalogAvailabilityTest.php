<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\VendorInventoryController;
use Marvel\Database\Models\VendorProductPrice;
use Tests\TestCase;

/**
 * Vendor catalog availability — the mandate's cases as executable rules:
 *   A: nothing attached  → product listed with every variant available
 *   B: partially attached → product listed with ONLY the remaining variants
 *   C: fully attached     → product EXCLUDED from the response
 *   D: simple product     → hidden once the vendor owns it
 * plus attach-only dedupe: re-adding an owned identity is skipped and never touches the price,
 * and two vendors see the catalogue independently.
 */
final class VendorCatalogAvailabilityTest extends TestCase
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
        ]);
        DB::purge('sqlite');
        \Marvel\Database\Models\VendorProductPrice::resetReviewStatics();

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
            $t->string('name');
            $t->string('slug');
            $t->string('sku')->nullable();
            $t->json('image')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->string('product_type')->default('variable');
            $t->decimal('price')->nullable();
            $t->string('status')->default('publish');
            $t->string('language')->default('en');
            $t->unsignedBigInteger('proposed_by_shop_id')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        // kodeine Metable probes products_meta whenever a Product attribute misses — stub it empty.
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->default('null');
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('title');
            $t->string('sku')->nullable();
            $t->decimal('price')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->string('period_type')->default('monthly');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->decimal('vendor_selling_price')->nullable();
            $t->decimal('cost_price')->nullable();
            $t->integer('stock_qty')->default(0);
            $t->integer('reserved_qty')->default(0);
            $t->boolean('track_stock')->default(false);
            $t->string('fulfillment_mode')->nullable();
            $t->string('source')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->string('review_status')->default('approved');
            $t->text('review_comment')->nullable();
            $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->integer('moq')->nullable();
            $t->integer('lead_time_days')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->string('dedupe_key')->nullable()->unique();
            $t->boolean('is_available')->default(true);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        // The post-attach availability recompute walks shops / service areas / categories /
        // per-city availability — stub them empty so it no-ops.
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('approval_status')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_service_areas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('city')->nullable();
            $t->string('pincode')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->string('city_key')->nullable();
            $t->boolean('is_available')->default(false);
            $t->decimal('price')->nullable();
            $t->decimal('max_vendor_rate')->nullable();
            $t->unsignedBigInteger('best_shop_id')->nullable();
            $t->timestamps();
        });

        // PricingService reads settings.options at construction — one empty-options row suffices.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => '{}', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);

        // Plant A: 4 sizes. Plant B: 3 sizes. Plant C (simple): no variants.
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Plant A', 'slug' => 'plant-a', 'product_type' => 'variable', 'status' => 'publish', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Plant B', 'slug' => 'plant-b', 'product_type' => 'variable', 'status' => 'publish', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Plant C', 'slug' => 'plant-c', 'product_type' => 'simple', 'status' => 'publish', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('variation_options')->insert([
            ['id' => 11, 'product_id' => 1, 'title' => '6 inch', 'sku' => 'sku-11', 'price' => 500, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'product_id' => 1, 'title' => '8 inch', 'sku' => 'sku-12', 'price' => 700, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 13, 'product_id' => 1, 'title' => '10 inch', 'sku' => 'sku-13', 'price' => 900, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 14, 'product_id' => 1, 'title' => '12 inch', 'sku' => 'sku-14', 'price' => 1200, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'product_id' => 2, 'title' => 'Small', 'sku' => 'sku-21', 'price' => 200, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'product_id' => 2, 'title' => 'Medium', 'sku' => 'sku-22', 'price' => 300, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 23, 'product_id' => 2, 'title' => 'Large', 'sku' => 'sku-23', 'price' => 400, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** An admin request pinned to one vendor shop, mirroring the proxy tests' auth shape. */
    private function asVendor(int $shopId, array $params = []): Request
    {
        $request = new Request(array_merge(['shop_id' => $shopId], $params));
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public $shops;
            public function __construct()
            {
                $this->shops = collect();
            }
            public function hasPermissionTo($permission): bool
            {
                return true; // super-admin path: may act on any shop
            }
        });
        return $request;
    }

    private function search(int $shopId): array
    {
        $res = (new VendorInventoryController())->catalogSearch($this->asVendor($shopId));
        return collect($res->items())->keyBy('id')->all();
    }

    private function attach(int $shopId, array $items): array
    {
        $req = $this->asVendor($shopId);
        $req->merge(['items' => $items]);
        return (new VendorInventoryController())->bulkAttach($req)->getData(true);
    }

    public function test_case_a_untouched_product_lists_every_variant(): void
    {
        $items = $this->search(33);
        $this->assertArrayHasKey(1, $items);
        $this->assertCount(4, $items[1]['available_variants']);
    }

    /** Mandate Test 2 + Case B: partial attach leaves only the remaining variants. */
    public function test_case_b_partially_attached_shows_only_remaining_variants(): void
    {
        $this->attach(33, [
            ['product_id' => 2, 'variation_option_id' => 21, 'vendor_selling_price' => 210],
            ['product_id' => 2, 'variation_option_id' => 22, 'vendor_selling_price' => 310],
        ]);
        $items = $this->search(33);
        $this->assertArrayHasKey(2, $items, 'a partially attached plant must stay visible');
        $remaining = collect($items[2]['available_variants'])->pluck('title')->all();
        $this->assertSame(['Large'], $remaining);
    }

    /** Mandate Tests 1 + 4 + Case C: attaching every variant removes the product entirely. */
    public function test_case_c_fully_attached_product_disappears(): void
    {
        $this->attach(33, [
            ['product_id' => 1, 'variation_option_id' => 11, 'vendor_selling_price' => 500],
            ['product_id' => 1, 'variation_option_id' => 12, 'vendor_selling_price' => 700],
            ['product_id' => 1, 'variation_option_id' => 13, 'vendor_selling_price' => 900],
        ]);
        $this->assertArrayHasKey(1, $this->search(33), 'still one variant left — must remain');

        $this->attach(33, [['product_id' => 1, 'variation_option_id' => 14, 'vendor_selling_price' => 1200]]);
        $this->assertArrayNotHasKey(1, $this->search(33), 'all four variants attached — Plant A must vanish');
    }

    /** Case D: a simple product is its own inventory item and hides once owned. */
    public function test_case_d_simple_product_hides_once_added(): void
    {
        $this->assertArrayHasKey(3, $this->search(33));
        $this->attach(33, [['product_id' => 3, 'vendor_selling_price' => 150]]);
        $this->assertArrayNotHasKey(3, $this->search(33));
    }

    /** Mandate Test 3: re-adding an owned identity is skipped and the live price is untouched. */
    public function test_re_attaching_an_owned_variant_is_skipped_not_overwritten(): void
    {
        $this->attach(33, [['product_id' => 1, 'variation_option_id' => 12, 'vendor_selling_price' => 700]]);
        $res = $this->attach(33, [['product_id' => 1, 'variation_option_id' => 12, 'vendor_selling_price' => 999]]);

        $this->assertSame(0, $res['saved']);
        $this->assertSame(1, $res['skipped']);
        $this->assertSame([], $res['errors'], 'the dedupe rule working is not an error');
        $row = VendorProductPrice::where('shop_id', 33)->where('variation_option_id', 12)->first();
        $this->assertEquals(700.0, (float) $row->vendor_selling_price, 'a duplicate submit overwrote the live price');
        $this->assertSame(1, VendorProductPrice::where('shop_id', 33)->where('variation_option_id', 12)->count());
    }

    /** Mandate Test 7: availability is per-vendor. */
    public function test_a_product_not_in_the_master_catalog_is_invisible_to_vendors(): void
    {
        // Rule: vendors select from the curated Available Products list, never from the raw
        // catalogue. Product 1 is `publish` — status alone no longer makes it selectable.
        \Illuminate\Support\Facades\DB::table('products')->where('id', 1)
            ->update(['is_available_product' => false]);

        $this->assertArrayNotHasKey(1, $this->search(33), 'an uncurated product must not appear in the vendor picker');
    }

    public function test_the_writer_refuses_a_new_listing_on_an_uncurated_product(): void
    {
        // Hiding it from the picker is not a gate — bulkAttach takes ids, and the vendor
        // spreadsheet upload and the admin price-sheet import reach the same writer. This is the
        // one place all three pass through.
        \Illuminate\Support\Facades\DB::table('products')->where('id', 1)
            ->update(['is_available_product' => false]);

        $res = $this->attach(33, [['product_id' => 1, 'variation_option_id' => 11, 'vendor_selling_price' => 499]]);

        $this->assertSame(0, $res['saved'] ?? -1, 'a crafted product_id must not walk past the catalogue gate');
        $this->assertSame(
            0,
            \Marvel\Database\Models\VendorProductPrice::where('product_id', 1)->where('shop_id', 33)->count(),
            'nothing may be written for an uncurated product',
        );
    }

    public function test_an_existing_listing_survives_its_product_leaving_the_catalog(): void
    {
        // Same grandfathering the status check has always applied: pulling a product back must not
        // punish a vendor who is already selling it, which is also why switching a listing off
        // preserves vendor rows rather than deactivating them.
        $this->attach(33, [['product_id' => 1, 'variation_option_id' => 11, 'vendor_selling_price' => 499]]);
        \Illuminate\Support\Facades\DB::table('products')->where('id', 1)
            ->update(['is_available_product' => false]);

        $res = $this->attach(33, [['product_id' => 1, 'variation_option_id' => 11, 'vendor_selling_price' => 549]]);

        $this->assertSame(
            1,
            \Marvel\Database\Models\VendorProductPrice::where('product_id', 1)->where('shop_id', 33)->count(),
            'the existing row must survive',
        );
        $this->assertArrayHasKey('saved', $res);
    }

    public function test_vendors_see_independent_availability(): void
    {
        $this->attach(33, [
            ['product_id' => 1, 'variation_option_id' => 11, 'vendor_selling_price' => 500],
            ['product_id' => 1, 'variation_option_id' => 12, 'vendor_selling_price' => 700],
            ['product_id' => 1, 'variation_option_id' => 13, 'vendor_selling_price' => 900],
            ['product_id' => 1, 'variation_option_id' => 14, 'vendor_selling_price' => 1200],
        ]);
        $this->assertArrayNotHasKey(1, $this->search(33), 'vendor 33 owns all of Plant A');
        $other = $this->search(44);
        $this->assertArrayHasKey(1, $other, 'vendor 44 never touched Plant A');
        $this->assertCount(4, $other[1]['available_variants']);
    }
}
