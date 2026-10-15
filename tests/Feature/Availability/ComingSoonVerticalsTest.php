<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Http\Controllers\TypeController;
use Marvel\Services\AvailabilityService;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\MarginResolver;
use Marvel\Services\ServiceAvailabilityService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Catalog\Concerns\MarketplaceSchema;
use Tests\TestCase;

/**
 * Coming Soon verticals (owner brief 2026-10-15): Tools, FarmBox and Pots stay in the catalogue
 * and the navigation — the storefront shows a Coming Soon page for them — but nothing of
 * theirs can be bought: not from a listing, not by a direct API call, not with a cart a
 * client edited, and not by leaving the address out. Plants stay open (global catalogue).
 */
final class ComingSoonVerticalsTest extends TestCase
{
    use MarketplaceSchema;

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
        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Delhi', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 5, 'name' => 'Pune', 'status' => 'active', 'is_serviceable' => 0],
        ]);
        DB::table('postal_codes')->insert([['pincode' => '110001', 'city_id' => 1], ['pincode' => '411001', 'city_id' => 5]]);
        DB::table('shops')->insert(['id' => 1, 'name' => 'PlantAtHome', 'slug' => 'plantathome', 'is_active' => 1, 'approval_status' => 'approved']);
        $product = fn (int $id, string $name, int $type) => [
            'id' => $id, 'name' => $name, 'slug' => strtolower($name), 'type_id' => $type, 'shop_id' => 1, 'product_type' => 'simple',
            'price' => 499, 'min_price' => 499, 'max_price' => 499, 'in_stock' => 1, 'status' => 'publish', 'language' => 'en',
        ];
        DB::table('products')->insert([$product(12, 'Snake', 1), $product(21, 'Trowel', 2), $product(31, 'Basket', 3), $product(41, 'Pot', 12)]);
        // The tool has a seller rate, so only the Coming Soon switch stands between it and a cart.
        DB::table('vendor_product_prices')->insert([
            'shop_id' => 1, 'product_id' => 21, 'vendor_selling_price' => 200, 'is_available' => 1, 'review_status' => 'approved', 'fulfillment_mode' => 'courier',
        ]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Garden']);
        DB::table('category_product')->insert([['product_id' => 12, 'category_id' => 1], ['product_id' => 21, 'category_id' => 1], ['product_id' => 41, 'category_id' => 1]]);
        $soon = fn (string $slug, array $settings = []) => [
            'vertical_slug' => $slug, 'is_active' => 0, 'status' => 'coming_soon', 'maintenance_message' => "{$slug} are coming soon.",
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

    private function fresh(): void
    {
        app()->forgetScopedInstances();
        app(ServiceAvailabilityService::class)->bust();
    }

    private function line(int $productId): array
    {
        return ['product_id' => $productId, 'order_quantity' => 1, 'unit_price' => 1, 'subtotal' => 1];
    }

    /** /types as the storefront sees it (a shopper's request: filtered, not cached). */
    private function types(?string $city = null): array
    {
        $controller = app(TypeController::class);
        $controller->repository = new class {
            public function where($column, $value)
            {
                return $this;
            }

            public function get()
            {
                return Type::orderBy('id')->get();
            }
        };
        $request = Request::create('/types', 'GET', $city ? ['city' => $city] : []);
        $request->headers->set('Authorization', 'Bearer a-customer-token');
        return $controller->index($request)->collection
            ->mapWithKeys(fn ($r) => [$r->resource->slug => $r->resource->getAttribute('availability')])->all();
    }

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

    private function orderGuard(array $request): void
    {
        (fn ($r) => $this->assertVerticalsAvailable($r))->call(app(OrderRepository::class), $request);
    }

    public function test_coming_soon_verticals_stay_in_the_navigation_with_their_status(): void
    {
        $types = $this->types();
        $this->assertSame(['plants', 'tools', 'farm-box', 'pots-planters'], array_keys($types), 'kept, so their pages explain instead of a 404');
        $this->assertSame(['status' => 'active', 'message' => null, 'nationwide' => true], $types['plants']);
        $this->assertSame(['status' => 'coming_soon', 'message' => 'tools are coming soon.', 'nationwide' => false], $types['tools']);
        $this->assertSame('coming_soon', $types['farm-box']['status']);
        $this->assertSame('coming_soon', $this->types('Pune')['pots-planters']['status'], 'with a city too');

        DB::table('global_vertical_settings')->where('vertical_slug', 'farm-box')->update(['status' => 'disabled']);
        $this->fresh();
        $this->assertArrayNotHasKey('farm-box', $this->types(), 'a DISABLED vertical still disappears');

        DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->update(['status' => 'active', 'is_active' => 1]);
        $this->fresh();
        $this->assertSame('active', $this->types()['tools']['status'], 'switching back on needs no deploy');
    }

    public function test_coming_soon_products_never_reach_a_listing_or_related_products(): void
    {
        $scope = collect((new AvailabilityService())->cityScopeProductIds('Pune')->pluck('product_id'))->map(fn ($i) => (int) $i)->all();
        $this->assertSame([12], $scope, 'a coming-soon single seller no longer joins city scopes');

        $filter = app(ServiceAvailabilityService::class)->verticalFilterForCity(null);
        $this->assertContains('plants', $filter);
        $this->assertNotContains('tools', $filter);

        DB::table('products')->insert(['id' => 13, 'name' => 'Pothos', 'slug' => 'pothos', 'type_id' => 1, 'shop_id' => 1, 'product_type' => 'simple',
            'price' => 399, 'min_price' => 399, 'max_price' => 399, 'in_stock' => 1, 'status' => 'publish', 'language' => 'en']);
        DB::table('category_product')->insert(['product_id' => 13, 'category_id' => 1]);
        $related = app(ProductRepository::class)->fetchRelated('snake');
        $this->assertSame([13], collect($related)->pluck('id')->all(), 'another plant, never the trowel or the pot');
    }

    public function test_no_purchase_path_gets_a_coming_soon_product_through(): void
    {
        $lines = [$this->line(21), $this->line(31), $this->line(41)];
        foreach ([[], ['city' => 'Pune', 'zip' => '411001']] as $address) {
            $res = $this->verify(['shipping_address' => $address, 'products' => array_merge([$this->line(12)], $lines)]);
            $this->assertEqualsCanonicalizing([21, 31, 41], $res['unavailable_products'], $address ? 'with an address' : 'and without one');
            $this->assertSame(['tools' => 'tools are coming soon.', 'farm-box' => 'farm-box are coming soon.', 'pots-planters' => 'pots-planters are coming soon.'], (array) $res['blocked_verticals']);

            try {
                $this->orderGuard(['shipping_address' => $address, 'products' => [$this->line(21)]]);
                $this->fail('the order must be refused');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }
        $this->orderGuard(['shipping_address' => [], 'products' => [$this->line(12)]]); // a plant passes

        $engine = new ItemAssignmentService();
        $this->assertSame([], $engine->candidatesFor(21, null, 1, null), 'no vendor is offered without a city either');
        $this->assertSame([], $engine->candidatesFor(41, null, 1, 'Delhi'));
        $this->assertSame([21], (new CheckoutRepository())->checkStock([['product_id' => 21, 'order_quantity' => 1]]), 'the seller cannot take it');
    }
}
