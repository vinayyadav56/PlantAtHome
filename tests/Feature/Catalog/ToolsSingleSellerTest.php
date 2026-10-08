<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Database\Repositories\SettingsRepository;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Controllers\ServiceAvailabilityController;
use Marvel\Services\AvailabilityService;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\MarginResolver;
use Marvel\Services\OrderItemService;
use Marvel\Services\PricingService;
use Marvel\Services\ServiceAvailabilityService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Tools = one seller, nationwide. The `tools` vertical is sold by ONE shop (PlantAtHome) at
 * its rate + the Tools margin, in every city, never city-gated; Plants keep the multi-vendor
 * city model byte-for-byte. Every branch keys off ServiceAvailabilityService::singleSellerFor.
 *
 * Fixture: Delhi is a SUPPLIED city (strict scope: plant 11 only), Jaipur is serviceable with
 * no supply (browse-only), Rewari is paused. Shop 1 = PlantAtHome (the Tools seller), shop 7 a
 * Delhi nursery. Tool 21 has a seller rate of 200, tool 22 none, 23 is a tools BUNDLE.
 * Tools margin 10%; a Delhi all-vertical margin of 50% must never touch a tool.
 */
final class ToolsSingleSellerTest extends TestCase
{
    private const MIGRATION = 'packages/marvel/database/migrations/2026_10_08_000300_tools_single_seller.php';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('MARKETPLACE_RESERVE_STOCK=false');
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sqlite');
        Shop::resetMasterIdCache();
        VendorProductPrice::resetReviewStatics();
        MarginResolver::flush();

        $this->schema();

        DB::table('settings')->insert(['options' => json_encode([]), 'language' => 'en']);
        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants'], ['id' => 2, 'name' => 'Tools', 'slug' => 'tools']]);
        DB::table('states')->insert([['id' => 1, 'name' => 'Delhi'], ['id' => 2, 'name' => 'Maharashtra'], ['id' => 3, 'name' => 'Rajasthan']]);
        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Delhi', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 2, 'name' => 'Mumbai', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 3, 'name' => 'Jaipur', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 4, 'name' => 'Rewari', 'status' => 'paused', 'is_serviceable' => 1],
        ]);
        DB::table('postal_codes')->insert([
            ['pincode' => '110001', 'state_id' => 1, 'city_id' => 1],
            ['pincode' => '400001', 'state_id' => 2, 'city_id' => 2],
            ['pincode' => '302001', 'state_id' => 3, 'city_id' => 3],
        ]);
        $addr = fn (string $state) => json_encode(['city' => 'Delhi', 'state' => $state, 'zip' => '110001']);
        DB::table('shops')->insert([
            ['id' => 1, 'name' => 'PlantAtHome', 'slug' => 'plantathome', 'is_active' => 1, 'approval_status' => 'approved', 'address' => $addr('Delhi')],
            ['id' => 7, 'name' => 'Delhi Nursery', 'slug' => 'delhi-nursery', 'is_active' => 1, 'approval_status' => 'approved', 'address' => $addr('Delhi')],
            ['id' => 8, 'name' => 'Closed Shop', 'slug' => 'closed', 'is_active' => 0, 'approval_status' => 'approved', 'address' => $addr('Delhi')],
            ['id' => 9, 'name' => 'Held Shop', 'slug' => 'held', 'is_active' => 1, 'approval_status' => 'on_hold', 'address' => $addr('Delhi')],
        ]);
        $product = fn (int $id, string $name, int $type, array $over = []) => $over + [
            'id' => $id, 'name' => $name, 'slug' => strtolower($name), 'type_id' => $type, 'shop_id' => 1,
            'product_type' => 'simple', 'price' => 499, 'sale_price' => null, 'min_price' => 499, 'max_price' => 499, 'in_stock' => 1,
        ];
        DB::table('products')->insert([
            $product(11, 'Areca', 1),
            $product(12, 'Snake', 1),
            $product(21, 'Trowel', 2, ['price' => 999, 'sale_price' => 899, 'min_price' => 899, 'max_price' => 999]),
            $product(22, 'Gloves', 2),
            $product(23, 'Kit', 2, ['product_type' => 'bundle']),
        ]);
        // Plant 11 is supplied in Delhi by shop 7; tool 21 by its seller (no service area at all).
        $this->vpp(7, 11, 300);
        $this->vpp(1, 21, 200);
        DB::table('vendor_service_areas')->insert(['shop_id' => 7, 'city' => 'Delhi', 'fulfillment_mode' => 'local', 'eta_days' => 1, 'is_active' => 1]);
        DB::table('product_city_availability')->insert(['product_id' => 11, 'city' => 'delhi', 'variation_option_id' => 0, 'has_local' => 1, 'has_courier' => 0, 'vendor_count' => 1]);
        DB::table('pricing_margins')->insert([
            ['city' => null, 'type_id' => 2, 'margin_type' => 'percent', 'margin_percent' => 10, 'is_active' => 1],
            ['city' => 'Delhi', 'type_id' => null, 'margin_type' => 'percent', 'margin_percent' => 50, 'is_active' => 1],
        ]);
        DB::table('global_vertical_settings')->insert([
            ['vertical_slug' => 'plants', 'is_active' => 1, 'status' => 'active', 'settings' => null],
            ['vertical_slug' => 'tools', 'is_active' => 1, 'status' => 'active',
                'settings' => json_encode(['seller_model' => 'single_vendor', 'seller_shop_id' => 1, 'banner' => 'keep me'])],
        ]);
        $this->svc()->bust();
    }

    protected function tearDown(): void
    {
        putenv('MARKETPLACE_RESERVE_STOCK');
        parent::tearDown();
    }

    private function schema(): void
    {
        $tables = [
            'settings' => fn (Blueprint $t) => [$t->text('options')->nullable(), $t->string('language')->default('en'), $t->timestamps()],
            'types' => fn (Blueprint $t) => [$t->string('name'), $t->string('slug'), $t->string('language')->default('en'), $t->json('settings')->nullable(), $t->timestamps()],
            'global_vertical_settings' => fn (Blueprint $t) => [
                $t->string('vertical_slug', 64)->unique(), $t->boolean('is_active')->default(true), $t->string('status', 24)->default('active'),
                $t->text('maintenance_message')->nullable(), $t->json('settings')->nullable(),
                $t->unsignedBigInteger('created_by')->nullable(), $t->unsignedBigInteger('updated_by')->nullable(), $t->timestamps(),
            ],
            'city_vertical_service_settings' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('city_id'), $t->string('vertical_slug'), $t->string('status'), $t->text('maintenance_message')->nullable(), $t->timestamps(),
            ],
            'service_availability_logs' => fn (Blueprint $t) => [
                $t->string('entity_type'), $t->string('entity_id')->nullable(), $t->json('old_value')->nullable(), $t->json('new_value')->nullable(),
                $t->unsignedBigInteger('changed_by')->nullable(), $t->text('reason')->nullable(), $t->string('ip')->nullable(), $t->timestamp('created_at')->nullable(),
            ],
            'cities' => fn (Blueprint $t) => [
                $t->string('name'), $t->string('state_name')->nullable(), $t->decimal('lat', 10, 7)->nullable(), $t->decimal('lng', 10, 7)->nullable(),
                $t->string('status')->default('active'), $t->boolean('is_serviceable')->default(true), $t->json('settings')->nullable(),
            ],
            'states' => fn (Blueprint $t) => [$t->string('name')],
            'districts' => fn (Blueprint $t) => [$t->string('name')],
            'postal_codes' => fn (Blueprint $t) => [
                $t->string('pincode'), $t->unsignedBigInteger('state_id')->nullable(), $t->unsignedBigInteger('district_id')->nullable(),
                $t->unsignedBigInteger('city_id')->nullable(), $t->decimal('latitude', 10, 7)->nullable(), $t->decimal('longitude', 10, 7)->nullable(),
            ],
            'shops' => fn (Blueprint $t) => [
                $t->string('name')->nullable(), $t->string('slug')->nullable(), $t->unsignedBigInteger('owner_id')->nullable(),
                $t->boolean('is_active')->default(true), $t->string('approval_status')->nullable(), $t->text('address')->nullable(),
                $t->text('settings')->nullable(), $t->decimal('lat', 10, 7)->nullable(), $t->decimal('lng', 10, 7)->nullable(),
                $t->decimal('vendor_rating', 3, 2)->nullable(), $t->integer('vendor_priority_score')->nullable(), $t->integer('sla_default_days')->nullable(),
                $t->string('delivery_mode')->default('platform'), $t->timestamps(),
            ],
            'products' => fn (Blueprint $t) => [
                $t->string('name')->nullable(), $t->string('slug')->nullable(), $t->string('language')->default('en'),
                $t->unsignedBigInteger('type_id')->nullable(), $t->unsignedBigInteger('shop_id')->nullable(), $t->string('product_type')->default('simple'),
                $t->string('status')->default('publish'), $t->decimal('price')->nullable(), $t->decimal('sale_price')->nullable(),
                $t->decimal('min_price')->nullable(), $t->decimal('max_price')->nullable(), $t->boolean('in_stock')->default(true),
                $t->boolean('is_digital')->default(false), $t->decimal('delivery_charge')->nullable(), $t->integer('quantity')->default(0),
                $t->boolean('is_available_product')->default(true), $t->boolean('listing_enabled')->default(true), $t->timestamps(), $t->softDeletes(),
            ],
            'categories' => fn (Blueprint $t) => [$t->string('name')->nullable(), $t->timestamps()],
            'category_product' => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('category_id')],
            'pricing_margins' => fn (Blueprint $t) => [
                $t->string('city')->nullable(), $t->unsignedBigInteger('type_id')->nullable(), $t->string('margin_type')->default('percent'),
                $t->decimal('margin_percent', 8, 2)->default(0), $t->decimal('margin_flat', 12, 2)->nullable(), $t->boolean('is_active')->default(true), $t->timestamps(),
            ],
            'vendor_product_prices' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shop_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variation_option_id')->nullable(),
                $t->decimal('cost_price', 14, 2)->default(0), $t->decimal('vendor_selling_price', 14, 2)->nullable(), $t->string('period_type')->nullable(),
                $t->date('effective_from')->nullable(), $t->date('effective_to')->nullable(), $t->boolean('is_available')->default(true),
                $t->integer('stock_qty')->default(0), $t->integer('reserved_qty')->default(0), $t->boolean('track_stock')->default(false),
                $t->string('fulfillment_mode')->nullable(), $t->string('review_status')->nullable(), $t->timestamp('submitted_at')->nullable(),
                $t->timestamp('approved_at')->nullable(), $t->string('source')->nullable(), $t->unsignedBigInteger('created_by_user_id')->nullable(),
                $t->unsignedBigInteger('updated_by_user_id')->nullable(), $t->string('dedupe_key')->nullable(), $t->timestamps(), $t->softDeletes(),
            ],
            'vendor_service_areas' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shop_id'), $t->string('city')->nullable(), $t->string('pincode')->nullable(),
                $t->string('fulfillment_mode')->nullable(), $t->integer('eta_days')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps(),
            ],
            'vendor_shipping_rates' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shop_id'), $t->string('fulfillment_mode')->nullable(), $t->string('zone')->nullable(),
                $t->decimal('base_cost')->default(0), $t->decimal('per_kg_cost')->default(0), $t->boolean('is_active')->default(true), $t->timestamps(),
            ],
            'product_city_availability' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('product_id'), $t->string('city'), $t->unsignedBigInteger('variation_option_id')->default(0),
                $t->boolean('has_local')->default(false), $t->boolean('has_courier')->default(false), $t->decimal('min_price', 12, 2)->nullable(),
                $t->decimal('display_price', 14, 2)->nullable(), $t->integer('stock')->nullable(), $t->integer('stock_override')->nullable(),
                $t->integer('vendor_count')->default(0), $t->timestamp('updated_at')->nullable(),
            ],
            'orders' => fn (Blueprint $t) => [
                $t->string('tracking_number'), $t->unsignedBigInteger('customer_id')->nullable(), $t->unsignedBigInteger('parent_id')->nullable(),
                $t->string('order_status')->nullable(), $t->string('payment_status')->nullable(), $t->string('payment_gateway')->nullable(), $t->unsignedBigInteger('vendor_shop_id')->nullable(),
                $t->text('shipping_address')->nullable(), $t->string('language')->default('en'), $t->timestamps(), $t->softDeletes(),
            ],
            'order_items' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id')->nullable(), $t->unsignedBigInteger('variation_option_id')->nullable(),
                $t->integer('order_quantity')->default(1), $t->decimal('unit_price')->default(0), $t->unsignedBigInteger('assigned_shop_id')->nullable(),
                $t->unsignedBigInteger('vendor_product_price_id')->nullable(), $t->integer('reserved_qty')->default(0), $t->string('fulfillment_mode')->nullable(),
                $t->integer('eta_days')->nullable(), $t->unsignedBigInteger('shipment_id')->nullable(), $t->string('split_group', 16)->nullable(),
                $t->string('vendor_price_snapshot')->nullable(), $t->string('assignment_status')->default('unassigned'), $t->string('item_status')->default('pending'), $t->timestamps(),
            ],
            'shipments' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('shop_id')->nullable(), $t->unsignedBigInteger('pickup_location_id')->nullable(),
                $t->string('fulfillment_mode')->nullable(), $t->string('delivery_mode')->nullable(), $t->string('status')->default('pending'),
                $t->decimal('shipping_cost')->nullable(), $t->integer('eta_days')->nullable(), $t->date('expected_delivery_at')->nullable(),
                $t->string('provider')->nullable(), $t->string('awb_number')->nullable(), $t->string('cancelled_reason')->nullable(), $t->timestamp('cancelled_at')->nullable(),
                $t->string('provider_order_id')->nullable(), $t->string('provider_shipment_id')->nullable(), $t->string('split_group', 16)->nullable(), $t->timestamps(),
            ],
            'shipment_items' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shipment_id'), $t->unsignedBigInteger('order_item_id'), $t->unsignedInteger('quantity')->default(1),
                $t->string('status', 32)->default('pending'), $t->timestamps(),
            ],
            'order_events' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->string('type', 64), $t->string('label')->nullable(), $t->string('actor_type', 32)->nullable(),
                $t->unsignedBigInteger('actor_id')->nullable(), $t->json('meta')->nullable(), $t->timestamp('created_at')->nullable(),
            ],
            'users' => fn (Blueprint $t) => [$t->string('name')->nullable(), $t->timestamps()],
            'order_product' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variation_option_id')->nullable(),
                $t->integer('order_quantity')->nullable(), $t->string('unit_price')->nullable(), $t->string('subtotal')->nullable(), $t->timestamps(),
            ],
        ];
        foreach ($tables as $name => $cols) {
            Schema::create($name, function (Blueprint $t) use ($cols) {
                $t->bigIncrements('id');
                $cols($t);
            });
        }
    }

    private function vpp(int $shopId, int $productId, float $rate): void
    {
        DB::table('vendor_product_prices')->insert([
            'shop_id' => $shopId, 'product_id' => $productId, 'vendor_selling_price' => $rate,
            'is_available' => 1, 'review_status' => 'approved', 'fulfillment_mode' => 'courier',
        ]);
    }

    private function svc(): ServiceAvailabilityService
    {
        return app(ServiceAvailabilityService::class);
    }

    /** @return int[] sorted product ids of a city scope (null = full catalogue). */
    private function scope(string $city, bool $localOnly = false): ?array
    {
        $q = (new AvailabilityService())->cityScopeProductIds($city, $localOnly);
        return $q === null ? null : collect($q->pluck('product_id'))->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    private function price(int $productId, ?string $city): array
    {
        return (new PricingService())->sellingPrice(Product::find($productId), null, null, $city);
    }

    /** verify() for real, with only the tax engine and the optimiser stubbed out. */
    private function verify(array $payload): array
    {
        $repo = new class extends CheckoutRepository {
            public function calculateTax($request, $shipping_charge, $amount)
            {
                return 0;
            }

            public function gstBreakdown($request, $shipping_charge): array
            {
                return ['taxable_amount' => 0, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0, 'is_inter_state' => false,
                    'place_of_supply' => null, 'total_tax' => 0, 'delivery_tax_amount' => 0, 'lines' => []];
            }

            public function optimizerFlatFee(float $amount): ?float
            {
                return null;
            }

            protected function optimizedCheckout($request, float $amount, bool $isFullWalletPayment): ?array
            {
                return null;
            }
        };
        return $repo->verify(Request::create('/api/orders/checkout/verify', 'POST', $payload));
    }

    private function line(int $productId, float $price = 100): array
    {
        return ['product_id' => $productId, 'order_quantity' => 1, 'unit_price' => $price, 'subtotal' => $price];
    }

    // ── B. Visibility ────────────────────────────────────────────────────────

    public function test_restricted_city_scopes_list_tools_and_plants_strictness_is_unchanged(): void
    {
        // Delhi is strict: plant 11 (supplied) + the two non-bundle tools. Plant 12 has no
        // supply in Delhi and must stay OUT — a broken union fails open to the whole catalogue.
        $this->assertSame([11, 21, 22], $this->scope('Delhi'));
        $this->assertSame([11, 21, 22], $this->scope('New Delhi'), 'alias resolves to the same strict scope');
        // Rewari is paused (not serviceable): empty for plants, tools still listed.
        $this->assertSame([21, 22], $this->scope('Rewari'));
        // Jaipur: serviceable, unmapped → full catalogue, exactly as before.
        $this->assertNull($this->scope('Jaipur'));
        // availability=local: tools never deliver locally.
        $this->assertSame([11], $this->scope('Delhi', true));

        // The PDP's `(clone $scope)->where(...)` and applyCityScope's whereIn both still work.
        $q = (new AvailabilityService())->cityScopeProductIds('Delhi');
        $this->assertTrue((clone $q)->where('product_id', 21)->exists());
        $this->assertFalse((clone $q)->where('product_id', 12)->exists());
        $names = (new AvailabilityService())->applyCityScope(Product::query(), 'Delhi')->orderBy('id')->pluck('name')->all();
        $this->assertSame(['Areca', 'Trowel', 'Gloves'], $names);

        // Browse-only stays a Plants concept: the projection still has no supply in Jaipur.
        $this->assertFalse((new AvailabilityService())->cityHasSupply('Jaipur'));
    }

    public function test_plants_scope_is_byte_identical_without_a_seller(): void
    {
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->update(['settings' => null]);
        $this->svc()->bust();

        $this->assertSame([11], $this->scope('Delhi'));
        $this->assertSame([], $this->scope('Rewari'));
        $this->assertTrue(Product::find(21)->city_based);
    }

    public function test_single_seller_is_per_product_and_never_a_bundle(): void
    {
        $this->assertSame(1, $this->svc()->singleSellerFor(21));
        $this->assertSame(1, $this->svc()->singleSellerFor(Product::find(22)));
        $this->assertNull($this->svc()->singleSellerFor(23), 'a tools bundle is not single-seller');
        $this->assertNull($this->svc()->singleSellerFor(11));

        $this->assertFalse(Product::find(21)->city_based);
        $this->assertTrue(Product::find(23)->city_based);
        $this->assertTrue(Product::find(11)->city_based);
        // A partial select (my-inventory's `product:id,name`) still answers correctly.
        $this->assertFalse(Product::select('id', 'name')->find(21)->city_based);
        $this->assertContains('city_based', Product::find(21)->getAppends(), 'raw-model feeds (popular, cart) carry it too');
    }

    public function test_paused_city_and_the_city_vertical_matrix_block_plants_not_tools(): void
    {
        $this->assertFalse($this->svc()->resolve('plants', 'Rewari')['available']);
        $this->assertTrue($this->svc()->resolve('tools', 'Rewari')['available']);

        DB::table('city_vertical_service_settings')->insert([
            ['city_id' => 1, 'vertical_slug' => 'plants', 'status' => 'maintenance'],
            ['city_id' => 1, 'vertical_slug' => 'tools', 'status' => 'maintenance'],
        ]);
        $this->svc()->bust();
        $this->assertFalse($this->svc()->resolve('plants', 'Delhi')['available']);
        $this->assertTrue($this->svc()->resolve('tools', 'Delhi')['available']);

        // The global Tools switch (tier 1) still stops Tools everywhere.
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->update(['is_active' => 0]);
        $this->svc()->bust();
        $this->assertFalse($this->svc()->resolve('tools', 'Delhi')['available']);
    }

    // ── C. Price and stock ───────────────────────────────────────────────────

    public function test_price_is_the_seller_rate_plus_the_tools_margin_in_every_city(): void
    {
        foreach (['Delhi', 'Mumbai', 'Rewari', null] as $city) {
            $r = $this->price(21, $city);
            $this->assertEquals(220.0, $r['price'], 'rate 200 + Tools 10% in ' . ($city ?? 'no city') . '; the Delhi 50% rule never applies');
            $this->assertTrue($r['available']);
        }
        // A non-seller row (written past the model guard) neither prices nor fulfils the tool.
        $this->vpp(7, 21, 500);
        $this->assertEquals(220.0, $this->price(21, 'Delhi')['price']);

        $c = (new ItemAssignmentService())->candidatesFor(21, null, 1, 'Delhi', '110001');
        $this->assertCount(1, $c);
        $this->assertSame(1, $c[0]['shop_id']);
        $this->assertSame('courier', $c[0]['fulfillment_mode']);
        $this->assertSame(5, $c[0]['eta_days']);
        $this->assertEquals(220.0, $c[0]['selling_price']);

        // No seller rate: not for sale — never the catalogue price.
        $this->assertFalse($this->price(22, 'Delhi')['available']);
        // Plants are untouched: Delhi's 50% city rule still prices plant 11 (300 × 1.5).
        $this->assertEquals(450.0, $this->price(11, 'Delhi')['price']);
    }

    public function test_recompute_mirrors_the_nationwide_price_onto_the_product_row(): void
    {
        DB::table('product_city_availability')->insert(['product_id' => 21, 'city' => 'mumbai', 'variation_option_id' => 0, 'has_courier' => 1]);
        $svc = new AvailabilityService();
        $svc->recomputeForProduct(21);
        $svc->recomputeForProduct(22);

        $tool = DB::table('products')->find(21);
        $this->assertEquals(220, (float) $tool->price);
        $this->assertEquals(220, (float) $tool->min_price);
        $this->assertEquals(220, (float) $tool->max_price);
        $this->assertNull($tool->sale_price);
        $this->assertSame(1, (int) $tool->in_stock);
        $this->assertSame(0, DB::table('product_city_availability')->where('product_id', 21)->count());

        $unpriced = DB::table('products')->find(22);
        $this->assertSame(0, (int) $unpriced->in_stock, 'no seller rate ⇒ out of stock');
        $this->assertEquals(499, (float) $unpriced->price, 'price untouched — never null/0');

        // Tracked stock at 0 is "no usable seller row" too.
        DB::table('vendor_product_prices')->where('product_id', 21)->update(['track_stock' => 1, 'stock_qty' => 0]);
        $svc->recomputeForProduct(21);
        $this->assertSame(0, (int) DB::table('products')->find(21)->in_stock);
        $this->assertEquals(220, (float) DB::table('products')->find(21)->price);
    }

    public function test_a_product_form_save_is_re_mirrored(): void
    {
        $repo = \Mockery::mock(ProductRepository::class);
        $repo->shouldReceive('updateProduct')->andReturnUsing(function ($request, $id) {
            DB::table('products')->where('id', $id)->update(['price' => 999, 'min_price' => 999, 'max_price' => 999, 'in_stock' => 1]);
            return Product::find($id);
        });
        $settings = \Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('first')->andReturn(null);
        $request = Request::create('/api/products/21', 'PUT', ['name' => 'Trowel']);
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($p): bool
            {
                return true;
            }
        });
        $request->id = 21;

        $saved = (new ProductController($repo, $settings))->updateProduct($request);

        $this->assertEquals(220, (float) DB::table('products')->find(21)->price);
        $this->assertEquals(220, (float) $saved->price, 'the response shows the mirrored price');
    }

    public function test_another_shop_cannot_supply_a_single_seller_product(): void
    {
        try {
            VendorProductPrice::create(['shop_id' => 7, 'product_id' => 21, 'vendor_selling_price' => 150, 'is_available' => true]);
            $this->fail('a non-seller row for a tool must be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, VendorProductPrice::where('shop_id', 7)->where('product_id', 21)->count());

        // The seller may, and plants stay open to every vendor.
        VendorProductPrice::create(['shop_id' => 1, 'product_id' => 22, 'vendor_selling_price' => 90, 'is_available' => true]);
        VendorProductPrice::create(['shop_id' => 7, 'product_id' => 12, 'vendor_selling_price' => 90, 'is_available' => true]);
        $this->assertSame(2, VendorProductPrice::whereIn('product_id', [12, 22])->count());
    }

    // ── Checkout ─────────────────────────────────────────────────────────────

    public function test_a_tools_only_cart_in_a_no_supply_city_ships_to_another_city(): void
    {
        $res = $this->verify([
            'shopping_city'    => 'Jaipur', // browse-only for Plants
            'shipping_address' => ['city' => 'Mumbai', 'zip' => '400001'],
            'products'         => [$this->line(21, 220)],
        ]);

        $this->assertSame([], $res['unavailable_products']);
        $this->assertNull($res['city_stock']);
        $this->assertNull($res['city_mismatch']);
        $this->assertEquals(220, $res['priced_products'][0]['unit_price']);
        $this->assertFalse($res['delivery_methods'][0]['available'], 'a Tools-only cart is never "Local Delivery"');
    }

    public function test_a_mixed_cart_flags_only_the_plant_lines(): void
    {
        // Browse-only city: only the plant is out of stock, with the mixed-cart wording.
        $res = $this->verify([
            'shopping_city'    => 'Jaipur',
            'shipping_address' => ['city' => 'Jaipur', 'zip' => '302001'],
            'products'         => [$this->line(11), $this->line(21, 220)],
        ]);
        $this->assertSame([11], $res['unavailable_products']);
        $this->assertSame('CITY_OUT_OF_STOCK', $res['city_stock']['code']);
        $this->assertStringContainsString("Plants aren't available in Jaipur", $res['city_stock']['message']);

        // Address in another city: the mismatch still applies, to the plant only.
        $res = $this->verify([
            'shopping_city'    => 'Delhi',
            'shipping_address' => ['city' => 'Mumbai', 'zip' => '400001'],
            'products'         => [$this->line(11), $this->line(21, 220)],
        ]);
        $this->assertSame('SHOPPING_CITY_MISMATCH', $res['city_mismatch']['code']);
        $this->assertSame([11], $res['unavailable_products']);

        // An all-plant cart keeps the original whole-cart wording.
        $stock = (new CheckoutRepository())->shoppingCityOutOfStock(['shopping_city' => 'Jaipur', 'products' => [$this->line(11)]]);
        $this->assertStringContainsString('All products are currently out of stock in Jaipur', $stock['message']);
    }

    public function test_check_stock_flags_a_tool_its_seller_cannot_fill(): void
    {
        $repo = new CheckoutRepository();
        $this->assertSame([22], $repo->checkStock([['product_id' => 22, 'order_quantity' => 1]]));
        $this->assertSame([], $repo->checkStock([['product_id' => 21, 'order_quantity' => 1]]));
        // Plants: per-unit stock is still never a blocker (city is their only gate).
        $this->assertSame([], $repo->checkStock([['product_id' => 12, 'order_quantity' => 1]]));
    }

    public function test_cart_city_switch_keeps_tools_in_a_browse_only_city(): void
    {
        $res = $this->postJson('/api/cart/validate-city', [
            'city'  => 'Jaipur',
            'items' => [['product_id' => 11], ['product_id' => 21]],
        ])->assertOk();

        $this->assertSame([21], array_column($res->json('data.available'), 'product_id'));
        $this->assertSame([11], array_column($res->json('data.unavailable'), 'product_id'));
    }

    // ── D. Orders ────────────────────────────────────────────────────────────

    public function test_with_auto_assign_off_tools_lines_go_to_the_seller_and_plants_wait(): void
    {
        $order = Order::create([
            'tracking_number'  => 'T0001',
            'order_status'     => 'order-processing',
            'shipping_address' => json_encode(['city' => 'Mumbai', 'zip' => '400001']),
        ]);
        $plant = OrderItem::create(['order_id' => $order->id, 'product_id' => 11, 'order_quantity' => 1, 'unit_price' => 300]);
        $tool = OrderItem::create(['order_id' => $order->id, 'product_id' => 21, 'order_quantity' => 2, 'unit_price' => 220]);

        $res = (new OrderItemService())->assignSingleSellerLines($order);

        $this->assertSame(1, $res['applied']);
        $tool->refresh();
        $plant->refresh();
        $this->assertSame(1, (int) $tool->assigned_shop_id);
        $this->assertSame('courier', $tool->fulfillment_mode);
        $this->assertSame('suggested', $tool->assignment_status, 'a system assignment, not an operator override');
        $this->assertNull($plant->assigned_shop_id);
        $this->assertSame('unassigned', $plant->assignment_status);

        $shipments = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(1, $shipments, 'the seller gets its own shipment; the plant line has none');
        $this->assertSame(1, (int) $shipments->first()->shop_id);
        $this->assertSame((int) $shipments->first()->id, (int) $tool->shipment_id);
    }

    public function test_a_mixed_order_gets_no_order_level_vendor_and_still_raises_the_unassigned_alarm(): void
    {
        $mixed = Order::create(['tracking_number' => 'T0002', 'order_status' => 'order-processing', 'shipping_address' => json_encode(['city' => 'Mumbai'])]);
        DB::table('orders')->where('id', $mixed->id)->update(['payment_gateway' => 'CASH_ON_DELIVERY', 'created_at' => now()->subHours(3)]);
        OrderItem::create(['order_id' => $mixed->id, 'product_id' => 11, 'order_quantity' => 1, 'unit_price' => 300]);
        OrderItem::create(['order_id' => $mixed->id, 'product_id' => 21, 'order_quantity' => 1, 'unit_price' => 220]);
        $toolsOnly = Order::create(['tracking_number' => 'T0003', 'order_status' => 'order-processing', 'shipping_address' => json_encode(['city' => 'Mumbai'])]);
        OrderItem::create(['order_id' => $toolsOnly->id, 'product_id' => 21, 'order_quantity' => 1, 'unit_price' => 220]);

        (new OrderItemService())->assignSingleSellerLines($mixed);
        (new OrderItemService())->assignSingleSellerLines($toolsOnly);

        $this->assertNull(DB::table('orders')->where('id', $mixed->id)->value('vendor_shop_id'), 'plant lines still wait: no order-level vendor yet');
        $this->assertSame(1, (int) DB::table('orders')->where('id', $toolsOnly->id)->value('vendor_shop_id'), 'the seller has the whole order');

        \Illuminate\Support\Facades\Log::spy();
        $this->artisan('orders:sweep-unassigned')->assertExitCode(0);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx = []) => $msg === 'orders.unassigned.sweep' && in_array($mixed->id, $ctx['order_ids'] ?? [], true))
            ->once();
    }

    public function test_a_city_with_every_city_based_vertical_off_is_not_narrowed_to_tools(): void
    {
        DB::table('cities')->insert(['id' => 5, 'name' => 'Pune', 'status' => 'maintenance', 'is_serviceable' => 1]);
        $this->svc()->bust();

        $this->assertSame(['tools'], array_values($this->svc()->availableVerticalsForCity('Pune')), 'Tools stay orderable in a paused city');
        $this->assertNull($this->svc()->verticalFilterForCity('Pune'), 'but the listing keeps the browse-everything fallback');
    }

    public function test_a_held_seller_lists_its_tools_out_of_stock_as_checkout_sees_them(): void
    {
        DB::table('shops')->where('id', 1)->update(['approval_status' => Shop::STATUS_ON_HOLD]);
        (new AvailabilityService())->recomputeForProduct(21);

        $this->assertSame(0, (int) DB::table('products')->where('id', 21)->value('in_stock'));
        $this->assertFalse($this->price(21, 'Delhi')['available']);
        $this->assertNotEmpty((new CheckoutRepository())->checkStock([['product_id' => 21, 'order_quantity' => 1]]));
    }

    // ── A. Seller-model endpoint ─────────────────────────────────────────────

    private function putSeller(string $slug, array $body): array
    {
        $request = Request::create("/api/verticals/{$slug}/seller-model", 'PUT', $body);
        return app(ServiceAvailabilityController::class)->setSellerModel($request, $slug);
    }

    private function validationMessage(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            return collect($e->errors())->flatten()->implode(' ');
        }
        $this->fail('expected a 422');
    }

    public function test_reads_the_seller_model_of_every_vertical(): void
    {
        $rows = collect(app(ServiceAvailabilityController::class)->sellerModels())->keyBy('vertical');

        $this->assertSame([
            'vertical' => 'tools', 'seller_model' => 'single_vendor', 'seller_shop_id' => 1,
            'seller' => ['id' => 1, 'name' => 'PlantAtHome', 'slug' => 'plantathome'], 'city_based' => false,
        ], $rows['tools']);
        $this->assertSame('multi_vendor', $rows['plants']['seller_model']);
        $this->assertNull($rows['plants']['seller']);
        $this->assertTrue($rows['plants']['city_based']);
        $this->assertSame($rows['tools'], app(ServiceAvailabilityController::class)->showSellerModel('tools'));
    }

    public function test_saving_a_seller_creates_the_row_merges_settings_and_re_mirrors(): void
    {
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->delete();
        $this->svc()->bust();
        $this->assertTrue(Product::find(21)->city_based);

        $res = $this->putSeller('tools', ['seller_model' => 'single_vendor', 'seller_shop_id' => 1]);

        $this->assertSame('single_vendor', $res['seller_model']);
        $this->assertSame(1, $res['seller_shop_id']);
        $this->assertSame(0, $res['inert_rows']);
        $row = DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->first();
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame('active', $row->status);
        $this->assertEquals(220, (float) DB::table('products')->find(21)->price, 'tools re-mirrored on save');
        $this->assertSame(1, DB::table('service_availability_logs')->count(), 'audited');

        // A later save merges — unrelated settings keys survive.
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')
            ->update(['settings' => json_encode(['seller_model' => 'single_vendor', 'seller_shop_id' => 1, 'banner' => 'keep me'])]);
        $this->svc()->bust();
        $this->putSeller('tools', ['seller_model' => 'single_vendor', 'seller_shop_id' => 1]);
        $settings = json_decode(DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->value('settings'), true);
        $this->assertSame('keep me', $settings['banner']);
    }

    public function test_plants_cannot_switch_to_a_single_seller_while_other_shops_supply_them(): void
    {
        // Even an unreviewed row counts.
        DB::table('vendor_product_prices')->insert(['shop_id' => 9, 'product_id' => 12, 'vendor_selling_price' => 80, 'review_status' => 'pending_review']);

        $msg = $this->validationMessage(fn () => $this->putSeller('plants', ['seller_model' => 'single_vendor', 'seller_shop_id' => 1]));
        $this->assertStringContainsString('2 inventory rows', $msg);
        $this->assertNull($this->svc()->singleSellerShopId('plants'));
    }

    public function test_an_inactive_or_held_seller_is_refused(): void
    {
        foreach ([8, 9, 999] as $shopId) {
            $this->validationMessage(fn () => $this->putSeller('tools', ['seller_model' => 'single_vendor', 'seller_shop_id' => $shopId]));
        }
        $this->assertSame(1, $this->svc()->singleSellerShopId('tools'));
    }

    public function test_changing_the_seller_reports_the_old_sellers_rows_as_inert(): void
    {
        $res = $this->putSeller('tools', ['seller_model' => 'single_vendor', 'seller_shop_id' => 7]);

        $this->assertSame(7, $res['seller_shop_id']);
        $this->assertSame(1, $res['inert_rows']);
        $this->assertNotEmpty($res['warnings']);
        $this->assertSame(7, $this->svc()->singleSellerFor(21));
        // Shop 7 has no tool rates yet: the tool goes out of stock, the old rate sells nothing.
        $this->assertSame(0, (int) DB::table('products')->find(21)->in_stock);
        $this->assertFalse($this->price(21, 'Delhi')['available']);
    }

    public function test_routes_read_for_staff_and_write_for_super_admin_only(): void
    {
        $route = fn (string $method, string $uri) => collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => in_array($method, $r->methods(), true) && $r->uri() === $uri);

        $this->assertContains('permission:super_admin', $route('PUT', 'api/verticals/{slug}/seller-model')->gatherMiddleware());
        $this->assertContains('permission:staff|store_owner', $route('GET', 'api/verticals/seller-models')->gatherMiddleware());
        $this->assertContains('permission:staff|store_owner', $route('GET', 'api/verticals/{slug}/seller-model')->gatherMiddleware());
    }

    // ── Data migration ───────────────────────────────────────────────────────

    public function test_migration_makes_plantathome_the_tools_seller_and_down_restores(): void
    {
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')
            ->update(['is_active' => 0, 'settings' => json_encode(['banner' => 'keep me'])]);
        $this->svc()->bust();
        $before = DB::table('products')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all();

        $migration = require base_path(self::MIGRATION);
        ob_start();
        $migration->up();
        ob_end_clean();

        $row = DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->first();
        $this->assertSame(['banner' => 'keep me', 'seller_model' => 'single_vendor', 'seller_shop_id' => 1], json_decode($row->settings, true));
        $this->assertSame(0, (int) $row->is_active, 'the existing switch is kept');
        $this->assertEquals(220, (float) DB::table('products')->find(21)->price);
        $this->assertSame(0, (int) DB::table('products')->find(22)->in_stock, 'no rate invented');
        $this->assertEquals(499, (float) DB::table('products')->find(22)->price);
        $this->assertEquals(499, (float) DB::table('products')->find(11)->price, 'plants untouched');

        $migration->down();

        $row = DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->first();
        $this->assertSame(['banner' => 'keep me'], json_decode($row->settings, true));
        $this->assertSame($before, DB::table('products')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all());
        $this->assertFalse(Schema::hasTable('pah_tools_seller_backup'));
    }

    public function test_migration_creates_a_missing_tools_row(): void
    {
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->delete();

        $migration = require base_path(self::MIGRATION);
        ob_start();
        $migration->up();
        ob_end_clean();

        $row = DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->first();
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame('active', $row->status);
        $this->assertSame(1, $this->svc()->singleSellerShopId('tools'));

        $migration->down();
        $this->assertSame(0, DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->count());
    }

    public function test_migration_without_a_plantathome_shop_is_a_no_op_and_creates_no_shop(): void
    {
        DB::table('shops')->where('id', 1)->update(['slug' => 'pah-old']);
        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->update(['settings' => null]);

        ob_start();
        (require base_path(self::MIGRATION))->up();
        ob_end_clean();

        $this->assertNull(DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->value('settings'));
        $this->assertSame(4, DB::table('shops')->count());
        $this->assertFalse(Schema::hasTable('pah_tools_seller_backup'));
    }
}
