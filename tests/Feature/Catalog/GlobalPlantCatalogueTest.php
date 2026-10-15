<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Controllers\ServiceAvailabilityController;
use Marvel\Services\AvailabilityService;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\MarginResolver;
use Marvel\Services\OrderItemService;
use Marvel\Services\PricingService;
use Marvel\Services\ServiceAvailabilityService;
use Tests\Feature\Catalog\Concerns\MarketplaceSchema;
use Tests\TestCase;

/**
 * Temporary global plant catalogue (owner brief 2026-10-15): while no vendors are onboarded,
 * every listed plant is orderable anywhere in India at its catalogue price; a city vendor
 * still takes over the plants it supplies; staff assign the rest to the fallback shop by hand.
 * Switch: `global_vertical_settings.plants.settings {catalogue_mode: global, fallback_shop_id}`.
 *
 * Fixture: Delhi has a nursery (shop 7) supplying plant 11 at 300 (+20% plants margin = 360);
 * Jaipur is serviceable with no vendor; Pune is open but NOT serviceable; Rewari is paused.
 * Shop 1 = PlantAtHome, the fallback. Plants 12 (499) and 16 (variable: 280 sale / 450) are
 * catalogue-only; 13 draft, 14 unlisted, 15 unpriced. Tools / FarmBox / Pots are coming soon.
 */
final class GlobalPlantCatalogueTest extends TestCase
{
    use MarketplaceSchema;

    private const PLANTS = [11, 12, 13, 14, 15, 16];

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

        $this->marketplaceSchema();

        DB::table('settings')->insert(['options' => json_encode([]), 'language' => 'en']);
        DB::table('types')->insert([
            ['id' => 1, 'name' => 'Plants', 'slug' => 'plants'],
            ['id' => 2, 'name' => 'Tools', 'slug' => 'tools'],
            ['id' => 3, 'name' => 'FarmBox', 'slug' => 'farm-box'],
            ['id' => 12, 'name' => 'Pots & Planters', 'slug' => 'pots-planters'],
        ]);
        DB::table('states')->insert([['id' => 1, 'name' => 'Delhi'], ['id' => 2, 'name' => 'Maharashtra'], ['id' => 3, 'name' => 'Rajasthan'], ['id' => 4, 'name' => 'Haryana']]);
        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Delhi', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 3, 'name' => 'Jaipur', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 4, 'name' => 'Rewari', 'status' => 'paused', 'is_serviceable' => 1],
            ['id' => 5, 'name' => 'Pune', 'status' => 'active', 'is_serviceable' => 0],
        ]);
        DB::table('postal_codes')->insert([
            ['pincode' => '110001', 'state_id' => 1, 'city_id' => 1],
            ['pincode' => '302001', 'state_id' => 3, 'city_id' => 3],
            ['pincode' => '123401', 'state_id' => 4, 'city_id' => 4],
            ['pincode' => '411001', 'state_id' => 2, 'city_id' => 5],
        ]);
        $addr = json_encode(['city' => 'Bengaluru', 'state' => 'Karnataka', 'zip' => '560001']);
        DB::table('shops')->insert([
            ['id' => 1, 'name' => 'PlantAtHome', 'slug' => 'plantathome', 'is_active' => 1, 'approval_status' => 'approved', 'address' => $addr],
            ['id' => 7, 'name' => 'Delhi Nursery', 'slug' => 'delhi-nursery', 'is_active' => 1, 'approval_status' => 'approved', 'address' => $addr],
            ['id' => 8, 'name' => 'Closed Shop', 'slug' => 'closed', 'is_active' => 0, 'approval_status' => 'approved', 'address' => $addr],
            ['id' => 9, 'name' => 'Held Shop', 'slug' => 'held', 'is_active' => 1, 'approval_status' => 'on_hold', 'address' => $addr],
        ]);
        $product = fn (int $id, string $name, int $type, array $over = []) => $over + [
            'id' => $id, 'name' => $name, 'slug' => strtolower($name), 'type_id' => $type, 'shop_id' => 1,
            'product_type' => 'simple', 'price' => 499, 'sale_price' => null, 'min_price' => 499, 'max_price' => 499, 'in_stock' => 1,
            'status' => 'publish', 'is_available_product' => 1, 'listing_enabled' => 1,
        ];
        DB::table('products')->insert([
            $product(11, 'Areca', 1),
            $product(12, 'Snake', 1),
            $product(13, 'Draft', 1, ['status' => 'draft']),
            $product(14, 'Unlisted', 1, ['listing_enabled' => 0]),
            $product(15, 'Unpriced', 1, ['price' => 0, 'min_price' => 0, 'max_price' => 0]),
            $product(16, 'Fern', 1, ['product_type' => 'variable', 'price' => 300, 'min_price' => 280, 'max_price' => 450]),
            $product(21, 'Trowel', 2),
            $product(31, 'Basket', 3),
            $product(41, 'Pot', 12),
        ]);
        DB::table('variation_options')->insert([
            ['id' => 161, 'product_id' => 16, 'title' => 'Small', 'price' => '300', 'sale_price' => '280'],
            ['id' => 162, 'product_id' => 16, 'title' => 'Large', 'price' => '450', 'sale_price' => null],
        ]);
        // Plant 11 is supplied in Delhi by shop 7 (local); the projection says so.
        $this->vpp(7, 11, 300);
        DB::table('vendor_service_areas')->insert(['shop_id' => 7, 'city' => 'Delhi', 'fulfillment_mode' => 'local', 'eta_days' => 1, 'is_active' => 1]);
        DB::table('product_city_availability')->insert([
            'product_id' => 11, 'city' => 'delhi', 'variation_option_id' => 0, 'has_local' => 1, 'has_courier' => 0, 'vendor_count' => 1, 'display_price' => 360,
        ]);
        DB::table('pricing_margins')->insert(['city' => null, 'type_id' => 1, 'margin_type' => 'percent', 'margin_percent' => 20, 'is_active' => 1]);
        $soon = fn (string $slug, array $settings = []) => [
            'vertical_slug' => $slug, 'is_active' => 0, 'status' => 'coming_soon', 'maintenance_message' => 'Coming soon to PlantAtHome.',
            'settings' => $settings ? json_encode($settings) : null,
        ];
        DB::table('global_vertical_settings')->insert([
            ['vertical_slug' => 'plants', 'is_active' => 1, 'status' => 'active', 'maintenance_message' => null,
                'settings' => json_encode(['catalogue_mode' => 'global', 'fallback_shop_id' => 1])],
            $soon('tools', ['seller_model' => 'single_vendor', 'seller_shop_id' => 1]),
            $soon('farm-box'),
            $soon('pots-planters'),
        ]);
        $this->fresh();
    }

    protected function tearDown(): void
    {
        putenv('MARKETPLACE_RESERVE_STOCK');
        parent::tearDown();
    }

    /** A new request's view: fresh scoped service (memos), rebuilt availability map. */
    private function fresh(): void
    {
        app()->forgetScopedInstances();
        $this->svc()->bust();
    }

    private function svc(): ServiceAvailabilityService
    {
        return app(ServiceAvailabilityService::class);
    }

    private function vpp(int $shopId, int $productId, float $rate, ?int $variationOptionId = null): void
    {
        DB::table('vendor_product_prices')->insert([
            'shop_id' => $shopId, 'product_id' => $productId, 'variation_option_id' => $variationOptionId, 'vendor_selling_price' => $rate,
            'is_available' => 1, 'review_status' => 'approved', 'fulfillment_mode' => 'courier',
        ]);
    }

    /** @return int[]|null sorted product ids of a city scope (null = full catalogue). */
    private function scope(string $city, bool $localOnly = false): ?array
    {
        $q = (new AvailabilityService())->cityScopeProductIds($city, $localOnly);
        return $q === null ? null : collect($q->pluck('product_id'))->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    private function price(int $productId, ?string $city): array
    {
        return (new PricingService())->sellingPrice(Product::find($productId), null, null, $city);
    }

    private function line(int $productId, float $price = 1, ?int $variationOptionId = null): array
    {
        return ['product_id' => $productId, 'variation_option_id' => $variationOptionId, 'order_quantity' => 1, 'unit_price' => $price, 'subtotal' => $price];
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

    /** OrderRepository's final vertical guard (protected), as storeOrder runs it. */
    private function assertVerticalsAvailable(array $request): void
    {
        (fn ($r) => $this->assertVerticalsAvailable($r))->call(app(OrderRepository::class), $request);
    }

    // ── The switch ───────────────────────────────────────────────────────────

    public function test_the_switch_is_live_only_while_plants_are_on_and_the_fallback_can_sell(): void
    {
        $this->assertSame(['plants' => 1], $this->svc()->globalCatalogueVerticals());
        $this->assertSame(1, $this->svc()->globalFallbackFor(12));
        $this->assertSame(1, $this->svc()->globalFallbackFor(Product::find(16)));
        $this->assertNull($this->svc()->globalFallbackFor(21), 'tools are no global catalogue');

        DB::table('shops')->where('id', 1)->update(['approval_status' => Shop::STATUS_ON_HOLD]);
        $this->fresh();
        $this->assertSame([], $this->svc()->globalCatalogueVerticals(), 'a held fallback switches it off');

        DB::table('shops')->where('id', 1)->update(['approval_status' => 'approved', 'is_active' => 0]);
        $this->fresh();
        $this->assertSame([], $this->svc()->globalCatalogueVerticals(), 'an inactive fallback switches it off');

        DB::table('shops')->where('id', 1)->update(['is_active' => 1]);
        DB::table('global_vertical_settings')->where('vertical_slug', 'plants')->update(['status' => 'coming_soon', 'is_active' => 0]);
        $this->fresh();
        $this->assertSame([], $this->svc()->globalCatalogueVerticals(), 'only while the vertical itself is on');

        DB::table('global_vertical_settings')->where('vertical_slug', 'plants')->update([
            'status' => 'active', 'is_active' => 1,
            'settings' => json_encode(['catalogue_mode' => 'global', 'fallback_shop_id' => 404]),
        ]);
        $this->fresh();
        $this->assertSame([], $this->svc()->globalCatalogueVerticals(), 'never a shop that does not exist');
    }

    // ── 1–3. Browse and order from any city, one catalogue ──────────────────

    public function test_every_open_city_lists_every_plant_once_and_nothing_coming_soon(): void
    {
        $this->assertNull($this->scope('Jaipur'), 'serviceable without a vendor: the full catalogue, as today');
        $this->assertSame(self::PLANTS, $this->scope('Pune'), 'open but not serviceable: every plant (was: nothing)');
        $this->assertSame(self::PLANTS, $this->scope('Delhi'), "a vendor city: its nursery's plants AND the rest of the catalogue");
        $this->assertSame(self::PLANTS, $this->scope('New Delhi'), 'aliases resolve the same way');
        $this->assertSame([], $this->scope('Rewari'), 'a paused city still blocks');
        $this->assertSame([11], $this->scope('Delhi', true), 'local delivery only = the vendor plants');

        $this->assertTrue($this->svc()->resolve('plants', 'Pune')['available']);
        $this->assertSame('city_paused', $this->svc()->resolve('plants', 'Rewari')['reason']);

        $filter = $this->svc()->verticalFilterForCity('Pune');
        $this->assertContains('plants', $filter);
        $this->assertEmpty(array_intersect(['tools', 'farm-box', 'pots-planters'], $filter), 'listings never show a coming-soon vertical');
        $this->assertEmpty(array_intersect(['tools', 'farm-box', 'pots-planters'], $this->svc()->verticalFilterForCity(null)));

        foreach (['Pune' => true, 'Jaipur' => true, 'Delhi' => true, 'Rewari' => false] as $city => $orderable) {
            $this->getJson('/api/city-availability?city=' . $city)->assertOk()->assertJsonPath('has_availability', $orderable);
        }
        $this->getJson('/api/city-availability?city=Pune')->assertJsonPath('available_count', 0, 'counts stay real vendor supply');
    }

    public function test_a_plant_checks_out_in_a_city_without_vendors_at_its_catalogue_price(): void
    {
        foreach (['Pune' => '411001', 'Jaipur' => '302001'] as $city => $zip) {
            $res = $this->verify([
                'shopping_city'    => $city,
                'shipping_address' => ['city' => $city, 'zip' => $zip],
                'products'         => [$this->line(12), $this->line(16, 1, 161)],
            ]);
            $this->assertSame([], $res['unavailable_products'], $city);
            $this->assertNull($res['city_stock'], "{$city}: no browse-only block");
            $this->assertNull($res['city_mismatch']);
            $this->assertSame([], (array) $res['blocked_verticals']);
            $this->assertArrayNotHasKey('coverage', $res);
            $this->assertEquals(499, $res['priced_products'][0]['unit_price'], 'the catalogue price, never the client price');
            $this->assertEquals(280, $res['priced_products'][1]['unit_price'], "the size's own catalogue (sale) price");
        }

        // The order step's own gates agree.
        $order = ['shopping_city' => 'Pune', 'shipping_address' => ['city' => 'Pune', 'zip' => '411001'], 'products' => [$this->line(12)]];
        $this->assertNull((new CheckoutRepository())->shoppingCityOutOfStock($order));
        (new CheckoutRepository())->assertCoverage($order);
        $this->assertVerticalsAvailable($order);

        // The cart's city switch keeps the plant, wherever the shopper moves.
        foreach (['Pune', 'Jaipur'] as $city) {
            $this->postJson('/api/cart/validate-city', ['city' => $city, 'items' => [['product_id' => 12]]])
                ->assertOk()->assertJsonPath('data.available.0.product_id', 12);
        }
    }

    public function test_the_same_listing_serves_every_city_without_touching_product_rows(): void
    {
        $before = DB::table('products')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all();
        foreach (['Pune', 'Jaipur', 'Delhi'] as $city) {
            $this->assertEquals(499, $this->price(12, $city)['price'], $city);
            $this->assertTrue($this->price(12, $city)['available']);
            $this->assertNull($this->price(12, $city)['message'], 'never the "within 6 hours" note');
        }
        $this->assertSame($before, DB::table('products')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all(), 'no product row is written');
        $this->assertSame(1, DB::table('product_city_availability')->count(), 'no city rows are invented');
        $this->assertSame(9, DB::table('products')->count(), 'no duplicate listing');
    }

    public function test_a_size_no_vendor_prices_keeps_its_catalogue_price_on_the_product_page(): void
    {
        // Delhi's nursery prices only the Small fern.
        DB::table('product_city_availability')->insert([
            'product_id' => 16, 'city' => 'delhi', 'variation_option_id' => 161, 'has_local' => 1, 'vendor_count' => 1, 'display_price' => 330,
        ]);
        $data = ['id' => 16, 'city_based' => true, 'price' => 300, 'sale_price' => null, 'variation_options' => [
            ['id' => 161, 'title' => 'Small', 'price' => '300', 'sale_price' => '280'],
            ['id' => 162, 'title' => 'Large', 'price' => '450', 'sale_price' => null],
        ]];
        $out = (fn ($d, $r) => $this->attachCityPricing($d, $r))
            ->call(app(ProductController::class), $data, Request::create('/api/products/fern', 'GET', ['city' => 'Delhi']));

        $this->assertEquals(330, $out['variation_options'][0]['price'], 'the vendor price where a vendor prices it');
        $this->assertTrue($out['variation_options'][1]['city_available'], 'the Large stays orderable');
        $this->assertSame('450', $out['variation_options'][1]['price'], 'at its catalogue price');
        $this->assertEquals(330, $out['min_price']);
        $this->assertEquals(450, $out['max_price']);
    }

    public function test_delivery_methods_never_call_a_fallback_line_local(): void
    {
        // Pune (Maharashtra) has no nursery: the fallback (Karnataka) ships it interstate.
        $res = $this->verify(['shipping_address' => ['city' => 'Pune', 'zip' => '411001'], 'products' => [$this->line(12)]]);
        $methods = collect($res['delivery_methods'])->keyBy('slug');
        $this->assertFalse($methods['local']['available']);
        $this->assertTrue($methods['standard_interstate']['available']);

        // Delhi's nursery supplies plant 11 locally: still local there.
        $res = $this->verify(['shipping_address' => ['city' => 'Delhi', 'zip' => '110001'], 'products' => [$this->line(11)]]);
        $this->assertTrue(collect($res['delivery_methods'])->keyBy('slug')['local']['available']);
    }

    // ── 4. Catalogue rules still decide ─────────────────────────────────────

    public function test_draft_unlisted_unpriced_and_deleted_plants_cannot_be_bought(): void
    {
        $repo = new CheckoutRepository();
        $lines = array_map(fn ($id) => ['product_id' => $id, 'order_quantity' => 1], [12, 13, 14, 15]);
        $this->assertSame([13, 14, 15], $repo->checkStock($lines));

        $res = $this->verify(['shipping_address' => ['city' => 'Pune', 'zip' => '411001'], 'products' => array_map(fn ($id) => $this->line($id), [12, 13, 14, 15])]);
        $this->assertEqualsCanonicalizing([13, 14, 15], $res['unavailable_products']);

        DB::table('products')->where('id', 12)->update(['deleted_at' => now()]);
        $this->assertSame([12], $repo->checkStock([['product_id' => 12, 'order_quantity' => 1]]));
    }

    // ── 8. An unsupported PIN gets an honest answer ─────────────────────────

    public function test_an_unknown_pin_is_refused_with_a_clear_message_everywhere(): void
    {
        $res = $this->verify(['shipping_address' => ['city' => 'Pune', 'zip' => '999999'], 'products' => [$this->line(12)]]);
        $this->assertSame('PINCODE_UNKNOWN', $res['coverage']['code']);
        $this->assertStringContainsString("couldn't find this PIN", $res['coverage']['message']);
        $this->assertSame([12], $res['unavailable_products']);

        // The cart page previews delivery without an address: no refusal there.
        $res = $this->verify(['products' => [$this->line(12)]]);
        $this->assertArrayNotHasKey('coverage', $res);
        $this->assertSame([], $res['unavailable_products']);

        // But an order must carry its PIN.
        try {
            (new CheckoutRepository())->assertCoverage(['shipping_address' => ['city' => 'Pune'], 'products' => [$this->line(12)]]);
            $this->fail('an order without a PIN must be refused');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('PINCODE_UNKNOWN', $e->getResponse()->getData(true)['code']);
        }

        // The storefront's PIN checks say the same thing.
        $this->getJson('/api/delivery-pincodes/check?pincode=999999')->assertJson(['serviceable' => false, 'reason' => 'unknown_pincode']);
        $this->getJson('/api/delivery-pincodes/check?pincode=411001')->assertJson(['serviceable' => true, 'source' => 'global']);
        $this->getJson('/api/delivery-pincodes/check?pincode=123401')->assertJson(['serviceable' => false, 'reason' => 'city_paused']);
        $this->getJson('/api/delivery-options?pincode=999999&product_id=12')->assertJson(['serviceable' => false, 'reason' => 'unknown_pincode']);
        $this->getJson('/api/delivery-options?pincode=411001&product_id=12')
            ->assertJsonPath('serviceable', true)->assertJsonPath('options.0.type', 'courier');
    }

    // ── 9 + fulfilment. Vendors take over; staff assign the rest ───────────

    public function test_staff_assign_a_vendorless_line_to_the_fallback_and_nothing_does_it_for_them(): void
    {
        $order = Order::create(['tracking_number' => 'G0001', 'order_status' => 'order-processing', 'shipping_address' => json_encode(['city' => 'Pune', 'zip' => '411001'])]);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => 12, 'order_quantity' => 2, 'unit_price' => 499]);
        $svc = new OrderItemService();

        $svc->assignSingleSellerLines($order);
        $this->assertSame('unassigned', $item->fresh()->assignment_status, 'never assigned at creation');

        $candidates = (new ItemAssignmentService())->candidatesFor(12, null, 2, 'Pune', '411001');
        $this->assertCount(1, $candidates);
        $this->assertSame(1, $candidates[0]['shop_id']);
        $this->assertTrue($candidates[0]['global_fallback']);
        $this->assertSame('courier', $candidates[0]['fulfillment_mode']);
        $this->assertEquals(499, $candidates[0]['selling_price']);
        $this->assertNull($candidates[0]['vendor_rate']);
        $this->assertNull((new ItemAssignmentService())->bestFor(12, null, 2, 'Pune', '411001'), 'auto-assign never picks the fallback');

        $this->assertSame(0, $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 7]])['applied'], 'never a shop that cannot fulfil it');
        $this->assertSame(0, $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 404]])['applied'], 'never a shop that does not exist');

        $this->assertSame(1, $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 1]])['applied']);
        $item->refresh();
        $this->assertSame(1, (int) $item->assigned_shop_id);
        $this->assertSame('courier', $item->fulfillment_mode);
        $this->assertSame('overridden', $item->assignment_status, "an operator's choice");
        $shipment = Shipment::where('order_id', $order->id)->sole();
        $this->assertSame(1, (int) $shipment->shop_id);
        $this->assertSame((int) $shipment->id, (int) $item->shipment_id);
    }

    public function test_a_vendor_city_keeps_its_vendor_and_price_and_the_fallback_waits_last(): void
    {
        $delhi = $this->price(11, 'Delhi');
        $this->assertEquals(360, $delhi['price'], 'vendor rate + plants margin');
        $this->assertTrue($delhi['has_vendor_cost']);
        $this->assertEquals(499, $this->price(11, 'Jaipur')['price'], "Delhi's nursery never prices Jaipur");
        $this->assertTrue($this->price(11, 'Jaipur')['available']);

        $candidates = (new ItemAssignmentService())->candidatesFor(11, null, 1, 'Delhi', '110001');
        $this->assertSame([7, 1], array_column($candidates, 'shop_id'));
        $this->assertTrue($candidates[1]['global_fallback']);
        $this->assertEquals(360, $candidates[1]['selling_price'], 'one price per line, whoever ships it');
        $this->assertSame(7, (new ItemAssignmentService())->bestFor(11, null, 1, 'Delhi', '110001')['shop_id']);
    }

    public function test_onboarding_a_vendor_takes_over_its_city_with_the_catalogue_behind_it(): void
    {
        $this->vpp(7, 12, 350);
        DB::table('vendor_service_areas')->insert(['shop_id' => 7, 'city' => 'Pune', 'fulfillment_mode' => 'local', 'eta_days' => 1, 'is_active' => 1]);
        (new AvailabilityService())->recomputeForProduct(12);

        $this->assertSame(self::PLANTS, $this->scope('Pune'), 'strict now, with the rest of the catalogue still listed');
        $this->assertSame([12], $this->scope('Pune', true));
        $this->assertEquals(420, $this->price(12, 'Pune')['price'], 'the vendor price takes over');
        $this->assertEquals(300, $this->price(16, 'Pune')['price'], 'other plants keep the catalogue price');
        $this->assertSame([7, 1], array_column((new ItemAssignmentService())->candidatesFor(12, null, 1, 'Pune', '411001'), 'shop_id'));
        $this->assertSame([1], array_column((new ItemAssignmentService())->candidatesFor(16, 161, 1, 'Pune', '411001'), 'shop_id'));
        $this->assertEquals(499, (float) DB::table('products')->where('id', 12)->value('price'), 'no product record touched');
    }

    public function test_switching_the_global_catalogue_off_restores_city_only_behaviour(): void
    {
        DB::table('global_vertical_settings')->where('vertical_slug', 'plants')->update(['settings' => null]);
        $this->fresh();

        $this->assertSame([], $this->scope('Pune'));
        $this->assertSame([11], $this->scope('Delhi'));
        $this->assertSame('city_active', $this->svc()->resolve('plants', 'Pune')['reason']);
        $this->getJson('/api/city-availability?city=Jaipur')->assertJsonPath('has_availability', false);
        $this->assertEquals(360, $this->price(11, 'Jaipur')['price'], "today's national vendor fallback");
        $this->assertSame([], (new ItemAssignmentService())->candidatesFor(12, null, 1, 'Jaipur', '302001'));
        $res = $this->verify(['shopping_city' => 'Jaipur', 'shipping_address' => ['city' => 'Jaipur', 'zip' => '302001'], 'products' => [$this->line(12)]]);
        $this->assertSame('CITY_OUT_OF_STOCK', $res['city_stock']['code']);
        $this->assertSame([], (new CheckoutRepository())->checkStock([['product_id' => 13, 'order_quantity' => 1]]), 'the catalogue check is a global-mode gate');
    }

    // ── The admin switch ─────────────────────────────────────────────────────

    private function putSeller(string $slug, array $body): array
    {
        return app(ServiceAvailabilityController::class)->setSellerModel(Request::create("/api/verticals/{$slug}/seller-model", 'PUT', $body), $slug);
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->implode(' ');
        }
        $this->fail('expected a 422');
    }

    public function test_the_selling_endpoint_sets_validates_and_reports_the_switch(): void
    {
        $plants = $this->putSeller('plants', ['seller_model' => 'multi_vendor']);
        $this->assertSame('global', $plants['catalogue_mode'], 'a save that does not mention it keeps it');
        $this->assertSame(['id' => 1, 'name' => 'PlantAtHome', 'slug' => 'plantathome'], $plants['fallback']);

        $this->assertStringContainsString('active shop', $this->refusal(fn () => $this->putSeller('plants', ['seller_model' => 'multi_vendor', 'catalogue_mode' => 'global', 'fallback_shop_id' => 9])));
        $this->assertStringContainsString('active shop', $this->refusal(fn () => $this->putSeller('plants', ['seller_model' => 'multi_vendor', 'catalogue_mode' => 'global', 'fallback_shop_id' => 404])));
        $this->assertStringContainsString('multi-vendor', $this->refusal(fn () => $this->putSeller('plants', ['seller_model' => 'single_vendor', 'seller_shop_id' => 1])));

        $off = $this->putSeller('plants', ['seller_model' => 'multi_vendor', 'catalogue_mode' => 'city', 'reason' => 'vendors onboarded']);
        $this->assertSame('city', $off['catalogue_mode']);
        $this->assertSame([], app(ServiceAvailabilityService::class)->globalCatalogueVerticals(), 'live at once (cache busted)');
        $this->assertSame(2, DB::table('service_availability_logs')->where('entity_id', 'plants')->count(), 'every save is audited');

        $on = $this->putSeller('plants', ['seller_model' => 'multi_vendor', 'catalogue_mode' => 'global', 'fallback_shop_id' => 1]);
        $this->assertSame(1, $on['fallback_shop_id']);
        $this->assertSame(['plants' => 1], app(ServiceAvailabilityService::class)->globalCatalogueVerticals());
    }
}
