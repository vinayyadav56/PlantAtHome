<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\Courier\ShippingServiceClient;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\OrderItemService;
use Tests\TestCase;

/**
 * Vendor-grouped shipments: one shipment per vendor per order, live-booked (sealed)
 * shipments survive every re-plan/regroup, reassignment of their items is refused,
 * rebooking a cancelled shipment clears the zombie cancelled_* fields and records
 * what the booking cost, and a live-booked shipment cannot be dispatched twice.
 */
final class VendorShipmentGroupingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stock reservation off via env so applyAssignment never touches Settings/VPP tables.
        putenv('MARKETPLACE_RESERVE_STOCK=false');
        $_ENV['MARKETPLACE_RESERVE_STOCK'] = 'false';

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

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->unsignedBigInteger('vendor_shop_id')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->text('shipping_address')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->integer('order_quantity')->default(1);
            $t->decimal('unit_price')->default(0);
            $t->unsignedBigInteger('assigned_shop_id')->nullable();
            $t->unsignedBigInteger('vendor_product_price_id')->nullable();
            $t->integer('reserved_qty')->default(0);
            $t->string('fulfillment_mode')->nullable();
            $t->integer('eta_days')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->string('split_group', 16)->nullable();
            $t->string('vendor_price_snapshot')->nullable();
            $t->string('assignment_status')->default('unassigned');
            $t->string('item_status')->default('pending');
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->string('delivery_mode')->nullable();
            $t->string('mode')->nullable();
            $t->string('status')->default('pending');
            $t->decimal('shipping_cost')->nullable();
            $t->decimal('booked_cost')->nullable();
            $t->integer('eta_days')->nullable();
            $t->date('expected_delivery_at')->nullable();
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_shipment_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('courier_name')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('cod_amount')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->string('cancelled_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->index();
            $t->string('type', 64);
            $t->string('label')->nullable();
            $t->string('actor_type', 32)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        // Settings lookups (Settings::getData) during the assign path resolve to "no row".
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        // buildRequest loads the vendor shop for the pickup address.
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->string('pickup_location_name')->nullable();
            $t->string('pickup_postcode')->nullable();
            $t->string('delivery_mode')->default('platform');
            $t->text('self_delivery')->nullable();
            $t->timestamps();
        });
        DB::table('shops')->insert([
            'id' => 5, 'name' => 'Vendor 5',
            'address' => json_encode(['street_address' => '1 Lane', 'city' => 'Delhi', 'zip' => '110001', 'country' => 'IN']),
        ]);
        // Order model default eager-load stubs.
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->integer('order_quantity')->nullable();
            $t->string('unit_price')->nullable();
            $t->string('subtotal')->nullable();
            $t->timestamps();
        });
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'tracking_number'  => 'T' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'order_status'     => 'order-processing',
            'payment_status'   => 'payment-success',
            'payment_gateway'  => 'RAZORPAY',
            'shipping_address' => json_encode(['city' => 'Delhi', 'zip' => '110001']),
        ]);
    }

    private function makeItem(Order $order, array $attrs = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id'       => $order->id,
            'product_id'     => 1,
            'order_quantity' => 1,
            'unit_price'     => 100,
        ], $attrs));
    }

    private function service(int $vendorShopId = 7): OrderItemService
    {
        return new OrderItemService(new FakeAssignmentEngine($vendorShopId));
    }

    public function test_multi_item_same_vendor_override_creates_one_shipment(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);

        $res = $this->service(7)->assignItems($order, [
            ['order_item_id' => $a->id, 'shop_id' => 7],
            ['order_item_id' => $b->id, 'shop_id' => 7],
        ]);

        $this->assertSame(2, $res['applied']);
        $rows = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows, 'two items for one vendor must share ONE shipment');
        $this->assertSame(7, (int) $rows->first()->shop_id);
        $this->assertSame($rows->first()->id, (int) $a->fresh()->shipment_id);
        $this->assertSame($rows->first()->id, (int) $b->fresh()->shipment_id);
    }

    public function test_booked_shipment_is_sealed_reassign_refused_row_preserved(): void
    {
        $order = $this->makeOrder();
        $booked = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'local',
            'status' => 'assigned', 'provider_order_id' => 'CRN-LIVE-1',
        ]);
        $locked = $this->makeItem($order, ['assigned_shop_id' => 5, 'fulfillment_mode' => 'local', 'shipment_id' => $booked->id, 'assignment_status' => 'approved', 'item_status' => 'assigned']);
        $free = $this->makeItem($order);

        $res = $this->service(7)->assignItems($order, [
            ['order_item_id' => $locked->id, 'shop_id' => 7],
            ['order_item_id' => $free->id, 'shop_id' => 7],
        ]);

        $this->assertSame(1, $res['applied']);
        $this->assertStringContainsString('already booked', $res['rejected'][0]['reason']);
        // The booked row survives with the same id + CRN, its item untouched.
        $survivor = Shipment::find($booked->id);
        $this->assertNotNull($survivor, 'regroup must never delete a live-booked shipment');
        $this->assertSame('CRN-LIVE-1', $survivor->provider_order_id);
        $this->assertSame($booked->id, (int) $locked->fresh()->shipment_id);
        $this->assertSame(5, (int) $locked->fresh()->assigned_shop_id);
        // The free item landed on a NEW vendor-7 shipment.
        $this->assertSame(7, (int) Shipment::find($free->fresh()->shipment_id)->shop_id);
    }

    public function test_self_delivery_vendor_stamps_shipment_and_reassignment_clears_it(): void
    {
        DB::table('shops')->insert(['id' => 7, 'name' => 'Self Vendor', 'delivery_mode' => 'self']);
        DB::table('shops')->insert(['id' => 8, 'name' => 'Platform Vendor', 'delivery_mode' => 'platform']);

        $order = $this->makeOrder();
        $item = $this->makeItem($order);

        $this->service(7)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 7]]);
        $row = Shipment::where('order_id', $order->id)->first();
        $this->assertSame('self', $row->delivery_mode, 'a self-delivery vendor leg must be stamped self');

        // Reassigning to a platform vendor must CLEAR the stamp (restamp, not carry).
        $this->service(8)->assignItems($order, [['order_item_id' => $item->fresh()->id, 'shop_id' => 8]]);
        $row2 = Shipment::where('order_id', $order->id)->first();
        $this->assertSame(8, (int) $row2->shop_id);
        $this->assertNull($row2->delivery_mode, 'a platform vendor leg must not keep a stale self stamp');
    }

    public function test_order_level_vendor_choice_moves_the_lines_and_the_shipment(): void
    {
        // The operator picking a vendor in the assignment panel used to write
        // ONLY orders.vendor_shop_id, while the shipment cards are built from
        // order_items.assigned_shop_id — so the card kept showing the
        // auto-assigned vendor and the choice looked ignored.
        DB::table('shops')->insert(['id' => 9, 'name' => 'Chosen Vendor']);
        $order = $this->makeOrder();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);
        $this->service(7)->assignItems($order, [
            ['order_item_id' => $a->id, 'shop_id' => 7],
            ['order_item_id' => $b->id, 'shop_id' => 7],
        ]);
        $this->assertSame(7, (int) Shipment::where('order_id', $order->id)->first()->shop_id);

        $res = $this->service(9)->syncOrderVendor($order, 9);

        $this->assertSame(2, $res['applied']);
        $rows = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows, 'both lines still share ONE vendor shipment');
        $this->assertSame(9, (int) $rows->first()->shop_id, 'the shipment must follow the chosen vendor');
    }

    public function test_order_level_sync_is_a_noop_when_already_on_that_vendor(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order);
        $this->service(7)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 7]]);
        $before = Shipment::where('order_id', $order->id)->first()->id;

        $res = $this->service(7)->syncOrderVendor($order, 7);

        $this->assertSame(0, $res['applied']);
        $this->assertTrue($res['already'] ?? false);
        $this->assertSame($before, Shipment::where('order_id', $order->id)->first()->id, 'no shipment churn');
    }

    public function test_order_level_sync_refuses_lines_on_a_booked_shipment(): void
    {
        DB::table('shops')->insert(['id' => 9, 'name' => 'Chosen Vendor']);
        $order = $this->makeOrder();
        $booked = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'local',
            'status' => 'assigned', 'provider_order_id' => 'CRN-LIVE-9',
        ]);
        $locked = $this->makeItem($order, [
            'assigned_shop_id' => 5, 'fulfillment_mode' => 'local', 'shipment_id' => $booked->id,
        ]);

        $res = $this->service(9)->syncOrderVendor($order, 9);

        $this->assertSame(0, $res['applied']);
        $this->assertStringContainsString('already booked', $res['rejected'][0]['reason']);
        $this->assertSame($booked->id, (int) $locked->fresh()->shipment_id);
        $this->assertSame('CRN-LIVE-9', Shipment::find($booked->id)->provider_order_id);
    }

    public function test_one_vendor_can_be_split_into_two_shipments_and_merged_back(): void
    {
        // Default: a vendor's lines share ONE parcel. A large order sometimes
        // needs two vehicles from that same vendor.
        $order = $this->makeOrder();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);
        $svc = $this->service(7);
        $svc->assignItems($order, [
            ['order_item_id' => $a->id, 'shop_id' => 7],
            ['order_item_id' => $b->id, 'shop_id' => 7],
        ]);
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());

        $res = $svc->splitShipment($order, [$b->id]);
        $this->assertSame(1, $res['applied']);
        $rows = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(2, $rows, 'the split line gets its own parcel');
        $this->assertSame([7, 7], $rows->pluck('shop_id')->map(fn ($i) => (int) $i)->all());
        $this->assertNotSame($a->fresh()->shipment_id, $b->fresh()->shipment_id);

        // Merge back.
        $svc->splitShipment($order, [$b->id], false);
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());
        $this->assertSame($a->fresh()->shipment_id, $b->fresh()->shipment_id);
    }

    public function test_split_refuses_a_line_on_a_booked_shipment(): void
    {
        $order = $this->makeOrder();
        $booked = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'local',
            'status' => 'assigned', 'awb_number' => 'AWB-SPLIT',
        ]);
        $locked = $this->makeItem($order, [
            'assigned_shop_id' => 5, 'fulfillment_mode' => 'local', 'shipment_id' => $booked->id,
        ]);

        $res = $this->service(7)->splitShipment($order, [$locked->id]);

        $this->assertSame(0, $res['applied']);
        $this->assertStringContainsString('already booked', $res['rejected'][0]['reason']);
        $this->assertSame($booked->id, (int) $locked->fresh()->shipment_id);
    }

    public function test_per_item_override_back_fills_the_order_level_vendor(): void
    {
        // The panel and the per-item table are two views of ONE decision, so
        // they must agree in both directions — otherwise overriding a line
        // leaves the panel showing a vendor that no longer has any lines.
        DB::table('shops')->insert(['id' => 9, 'name' => 'Chosen Vendor']);
        $order = $this->makeOrder();
        $order->forceFill(['vendor_shop_id' => 7])->save();
        $item = $this->makeItem($order);
        $this->service(7)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 7]]);

        $this->service(9)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 9]]);

        $this->assertSame(9, (int) $order->fresh()->vendor_shop_id);
    }

    public function test_multi_vendor_order_leaves_the_order_level_vendor_alone(): void
    {
        // No single vendor owns a split order — inventing a "winner" would be
        // a lie, so the approved value stands.
        DB::table('shops')->insert(['id' => 9, 'name' => 'Second Vendor']);
        $order = $this->makeOrder();
        $order->forceFill(['vendor_shop_id' => 7])->save();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);
        $this->service(7)->assignItems($order, [
            ['order_item_id' => $a->id, 'shop_id' => 7],
            ['order_item_id' => $b->id, 'shop_id' => 7],
        ]);

        $this->service(9)->assignItems($order, [['order_item_id' => $b->id, 'shop_id' => 9]]);

        $this->assertSame(7, (int) $order->fresh()->vendor_shop_id);
        $this->assertCount(2, Shipment::where('order_id', $order->id)->get());
    }

    public function test_auto_assign_preserves_booked_rows_and_reports_locked(): void
    {
        $order = $this->makeOrder();
        $booked = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'local',
            'status' => 'assigned', 'awb_number' => 'AWB-9',
        ]);
        $this->makeItem($order, ['assigned_shop_id' => 5, 'fulfillment_mode' => 'local', 'shipment_id' => $booked->id]);
        $this->makeItem($order); // unassigned → will be planned onto vendor 7

        $res = $this->service(7)->assignAndGroup($order);

        $this->assertSame(1, $res['locked']);
        $this->assertSame(1, $res['assigned']);
        $this->assertNotNull(Shipment::find($booked->id));
        $this->assertSame('AWB-9', Shipment::find($booked->id)->awb_number);
    }

    public function test_rebook_persists_booked_cost_and_clears_cancelled_fields(): void
    {
        config([
            'services.shipping_service.url'     => 'https://ship.test',
            'services.shipping_service.api_key' => 'k',
            'services.shipping_service.enabled' => true,
        ]);
        Http::fake([
            'ship.test/*' => Http::response([
                'partner' => 'porter', 'mode' => 'same_city',
                'provider_order_id' => 'CRN-NEW-2', 'status' => 'pending',
                'booked_cost' => 123.45, 'book_attempt' => 1,
            ], 200),
        ]);

        $order = $this->makeOrder();
        $shipment = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'local',
            'status' => 'cancelled', 'provider_order_id' => 'CRN-OLD-1',
            'cancelled_at' => now(), 'cancelled_reason' => 'operator cancel',
        ]);

        $res = (new ShippingServiceClient())->book($shipment, 'same_city', false, 0.0);

        $this->assertTrue($res['ok']);
        $fresh = $shipment->fresh();
        $this->assertSame('CRN-NEW-2', $fresh->provider_order_id);
        $this->assertEquals(123.45, (float) $fresh->booked_cost);
        $this->assertNull($fresh->cancelled_at, 'rebook must clear cancelled_at');
        $this->assertNull($fresh->cancelled_reason);
    }

    public function test_dispatch_refused_on_live_booked_shipment(): void
    {
        $order = $this->makeOrder();
        $shipment = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'courier',
            'status' => 'assigned', 'awb_number' => 'AWB-1',
        ]);

        $res = (new AlwaysOnCourier())->book($shipment);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('already booked', $res['error']);
    }
}

/** Engine stub: every line's best/only candidate is one fixed vendor. */
class FakeAssignmentEngine extends ItemAssignmentService
{
    public function __construct(private int $shopId)
    {
        parent::__construct();
    }

    public function candidatesFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): array
    {
        return [[
            'shop_id'          => $this->shopId,
            'vendor_name'      => 'Fake Vendor',
            'fulfillment_mode' => 'local',
            'eta_days'         => 2,
            'selling_price'    => 100,
            'shipping_cost'    => 0,
        ]];
    }

    public function bestFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): ?array
    {
        return $this->candidatesFor($productId, $variationOptionId, $qty, $city, $pincode)[0];
    }
}

/** CourierService with the DB-reading constructor skipped and the master switch forced on. */
class AlwaysOnCourier extends CourierService
{
    public function __construct()
    {
    }

    public function enabled(): bool
    {
        return true;
    }

    public function shippingServiceEnabled(): bool
    {
        return true;
    }
}
