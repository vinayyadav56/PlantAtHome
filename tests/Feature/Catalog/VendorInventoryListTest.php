<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\VendorInventoryController;
use Tests\TestCase;

/**
 * GET vendor/inventory — the two things the My Inventory screen needs from it.
 *
 * `status` is DERIVED, not a column: the screen computes it from review_status, is_available,
 * track_stock and (stock_qty - reserved_qty). The server filter has to reproduce that derivation
 * exactly or the filter lies about the vendor's own stock — so each case here is pinned to the
 * rule the client renders, including the legacy NULL review_status that must read as approved.
 *
 * `group_by=product` exists because paginating ROWS splits a plant's sizes across a page boundary.
 * It is OPT-IN: /[shop] shows the un-grouped paginator's `total` as its "Inventory" listings KPI,
 * so grouping by default would silently turn that count into a number of plants.
 */
final class VendorInventoryListTest extends TestCase
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
        \Marvel\Database\Models\Shop::resetMasterIdCache();

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('sku')->nullable();
            $t->json('image')->nullable();
            $t->string('status')->default('publish');
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
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
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->decimal('vendor_selling_price')->nullable();
            $t->decimal('cost_price')->nullable();
            $t->integer('stock_qty')->default(0);
            $t->integer('reserved_qty')->default(0);
            $t->boolean('track_stock')->default(false);
            $t->string('fulfillment_mode')->nullable();
            $t->string('review_status')->nullable();
            $t->boolean('is_available')->default(true);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('shops')->insert(['id' => 7, 'name' => 'Delhi Nursery', 'slug' => 'delhi-nursery', 'created_at' => now(), 'updated_at' => now()]);
        // Three plants; the first carries three sizes so a small page size can split it.
        foreach ([[1, 'Monstera'], [2, 'Snake Plant'], [3, 'Areca Palm']] as [$id, $name]) {
            DB::table('products')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[11, 1, 'Small'], [12, 1, 'Medium'], [13, 1, 'Large']] as [$id, $pid, $title]) {
            DB::table('variation_options')->insert(['id' => $id, 'product_id' => $pid, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<string,mixed> $over */
    private function row(int $id, int $productId, ?int $variant, array $over = []): void
    {
        DB::table('vendor_product_prices')->insert(array_merge([
            'id' => $id, 'shop_id' => 7, 'product_id' => $productId, 'variation_option_id' => $variant,
            'vendor_selling_price' => 100, 'stock_qty' => 5, 'reserved_qty' => 0,
            'track_stock' => true, 'review_status' => 'approved', 'is_available' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function listing(array $params): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $request = Request::create('/api/vendor/inventory', 'GET', $params + ['shop_id' => 7]);
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public $shops;
            public function __construct() { $this->shops = collect([(object) ['id' => 7]]); }
            public function hasPermissionTo($p): bool { return false; }
        });
        return app(VendorInventoryController::class)->inventory($request);
    }

    private function idsFor(array $params): array
    {
        return $this->listing($params)->getCollection()->pluck('id')->sort()->values()->all();
    }

    public function test_status_live_is_approved_available_and_in_stock(): void
    {
        $this->row(1, 1, 11);                                              // live
        $this->row(2, 1, 12, ['track_stock' => false, 'stock_qty' => 0]);  // live: untracked
        $this->row(3, 1, 13, ['stock_qty' => 2, 'reserved_qty' => 2]);     // out of stock
        $this->row(4, 2, null, ['is_available' => false]);                 // paused
        $this->row(5, 3, null, ['review_status' => 'pending_review']);     // pending

        $this->assertSame([1, 2], $this->idsFor(['status' => 'live']));
    }

    public function test_a_legacy_null_review_status_counts_as_approved(): void
    {
        // The screen reads `review_status ?? 'approved'`. A bare `where('review_status','approved')`
        // drops every pre-pipeline row, so a vendor's oldest live listings vanish from the filter.
        $this->row(1, 1, 11, ['review_status' => null]);
        $this->row(2, 1, 12, ['review_status' => 'approved']);

        $this->assertSame([1, 2], $this->idsFor(['status' => 'live']));
    }

    public function test_out_of_stock_only_counts_tracked_rows(): void
    {
        $this->row(1, 1, 11, ['stock_qty' => 0]);                          // tracked, none free
        $this->row(2, 1, 12, ['stock_qty' => 4, 'reserved_qty' => 4]);     // tracked, all reserved
        $this->row(3, 1, 13, ['track_stock' => false, 'stock_qty' => 0]);  // untracked = unlimited

        $this->assertSame([1, 2], $this->idsFor(['status' => 'out_of_stock']));
    }

    public function test_review_states_and_paused_are_selectable(): void
    {
        $this->row(1, 1, 11, ['review_status' => 'pending_review']);
        $this->row(2, 1, 12, ['review_status' => 'rejected']);
        $this->row(3, 1, 13, ['is_available' => false]);

        $this->assertSame([1], $this->idsFor(['status' => 'pending_review']));
        $this->assertSame([2], $this->idsFor(['status' => 'rejected']));
        $this->assertSame([3], $this->idsFor(['status' => 'paused']));
    }

    public function test_an_unknown_status_does_not_empty_the_screen(): void
    {
        $this->row(1, 1, 11);
        $this->row(2, 2, null);

        $this->assertSame([1, 2], $this->idsFor(['status' => 'nonsense']));
    }

    public function test_group_by_product_never_splits_a_plant_across_pages(): void
    {
        $this->row(1, 1, 11);
        $this->row(2, 1, 12);
        $this->row(3, 1, 13);
        $this->row(4, 2, null);
        $this->row(5, 3, null);

        // Two PLANTS per page: Monstera's three sizes must arrive together, never split.
        $first = $this->listing(['group_by' => 'product', 'limit' => 2, 'page' => 1]);
        $this->assertSame([1, 2, 3, 4], $first->getCollection()->pluck('id')->sort()->values()->all());
        $this->assertSame(3, $first->total(), 'total counts PLANTS when grouping');
        $this->assertSame(2, $first->lastPage());

        $second = $this->listing(['group_by' => 'product', 'limit' => 2, 'page' => 2]);
        $this->assertSame([5], $second->getCollection()->pluck('id')->sort()->values()->all());
    }

    public function test_without_grouping_the_total_stays_a_listing_count(): void
    {
        // /[shop] renders this paginator's `total` as the vendor's "Inventory" KPI. If grouping
        // ever became the default, that tile would silently start counting plants, not listings.
        $this->row(1, 1, 11);
        $this->row(2, 1, 12);
        $this->row(3, 1, 13);
        $this->row(4, 2, null);

        $this->assertSame(4, $this->listing([])->total());
    }

    public function test_status_and_grouping_compose(): void
    {
        $this->row(1, 1, 11);
        $this->row(2, 1, 12, ['stock_qty' => 0]);
        $this->row(3, 2, null, ['stock_qty' => 0]);

        // Only plants that still have an out-of-stock size, and only those sizes.
        $page = $this->listing(['group_by' => 'product', 'status' => 'out_of_stock', 'limit' => 10]);
        $this->assertSame([2, 3], $page->getCollection()->pluck('id')->sort()->values()->all());
        $this->assertSame(2, $page->total());
    }
}
