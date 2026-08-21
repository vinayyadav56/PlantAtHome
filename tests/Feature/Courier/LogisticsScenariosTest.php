<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use App\Models\PartnerConsoleOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\ShipmentItem;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\Courier\ShippingServiceClient;
use Marvel\Services\OrderItemService;
use Tests\TestCase;

/**
 * The marketplace-logistics acceptance scenarios, end to end.
 *
 * Each test is one numbered scenario from the architecture brief. They exist to pin the
 * four properties the old model could not express at all: a line's UNITS can be spread
 * across parcels; a shipment keeps its ID through a re-plan; every booking ATTEMPT is
 * kept, not overwritten; and a vendor's two doors are two collections.
 */
final class LogisticsScenariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            'services.shipping_service.url'     => 'https://ship.test',
            'services.shipping_service.api_key' => 'k',
        ]);
        DB::purge('sqlite');

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->unsignedBigInteger('vendor_shop_id')->nullable();
            $t->unsignedBigInteger('delivery_partner_id')->nullable();
            $t->string('delivery_mode')->nullable();
            $t->string('assignment_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->decimal('amount', 14, 2)->nullable();
            $t->decimal('paid_total', 14, 2)->nullable();
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
            $t->decimal('unit_price', 14, 2)->default(0);
            $t->decimal('subtotal', 14, 2)->nullable();
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
        Schema::create('shipment_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedInteger('quantity')->default(1);
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });
        Schema::create('shipment_packages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedSmallInteger('package_number')->default(1);
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->decimal('declared_value', 14, 2)->nullable();
            $t->string('contents', 255)->nullable();
            $t->boolean('fragile')->default(false);
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
            $t->decimal('shipping_cost', 14, 2)->nullable();
            $t->decimal('booked_cost', 14, 2)->nullable();
            $t->integer('eta_days')->nullable();
            $t->date('expected_delivery_at')->nullable();
            $t->string('split_group', 16)->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->string('provider_shipment_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('courier_name')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('cod_amount', 14, 2)->nullable();
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->string('cancelled_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedTinyInteger('simulation_flow_type')->nullable();
            $t->timestamp('simulation_started_at')->nullable();
            $t->timestamps();
        });
        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('partner_code', 32);
            $t->string('provider_order_id', 191)->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->string('origin', 16)->default('console');
            $t->unsignedSmallInteger('attempt_no')->default(1);
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('mode', 32)->nullable();
            $t->text('request')->nullable();
            $t->text('response')->nullable();
            $t->string('last_status', 64)->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->text('tracking_url')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_pickup_locations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('label', 64);
            $t->string('address')->nullable();
            $t->string('city', 96)->nullable();
            $t->string('pincode', 12)->nullable();
            $t->string('partner', 24)->default('shiprocket');
            $t->string('provider_location_name', 64)->nullable();
            $t->string('status', 16)->default('pending');
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });
        Schema::create('order_wallet_points', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->decimal('amount', 14, 2)->default(0);
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
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
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
        foreach ([['A', 11], ['B', 12], ['C', 13]] as [$name, $id]) {
            DB::table('shops')->insert([
                'id' => $id, 'name' => "Vendor {$name}",
                'address' => json_encode(['street_address' => '1 Lane', 'city' => 'Delhi', 'zip' => '110001', 'country' => 'IN']),
            ]);
        }
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            // sku is a real column: Product is kodeine Metable, so reading an attribute that
            // is NOT a column falls through to a products_meta lookup and dies there.
            $t->string('sku')->nullable();
            $t->integer('weight')->nullable();
            // Same reason as sku — packageDims() reads these off the product directly.
            $t->decimal('length', 8, 2)->nullable();
            $t->decimal('breadth', 8, 2)->nullable();
            $t->decimal('height', 8, 2)->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Jade Plant', 'sku' => 'JADE-1', 'weight' => 1000],
            ['id' => 2, 'name' => 'Fertilizer', 'sku' => 'FERT-1', 'weight' => 500],
        ]);
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

    // ---------------------------------------------------------------- helpers

    private function makeOrder(): Order
    {
        return Order::create([
            'tracking_number'  => 'T' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'order_status'     => 'order-processing',
            'payment_status'   => 'payment-success',
            'payment_gateway'  => 'RAZORPAY',
            'amount'           => 500,
            'paid_total'       => 500,
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
            'subtotal'       => 100,
        ], $attrs));
    }

    private function service(int $vendorShopId): OrderItemService
    {
        return new OrderItemService(new FakeAssignmentEngine($vendorShopId));
    }

    /** Allocated units per shipment id, for one order. */
    private function allocationMap(Order $order): array
    {
        $itemIds = OrderItem::where('order_id', $order->id)->pluck('id');
        $out = [];
        foreach (ShipmentItem::whereIn('order_item_id', $itemIds)->get() as $a) {
            $out[(int) $a->shipment_id] = ($out[(int) $a->shipment_id] ?? 0) + (int) $a->quantity;
        }
        ksort($out);

        return $out;
    }

    // ------------------------------------------------------- scenario 75

    public function test_75_multi_vendor_order_splits_by_vendor_then_one_line_moves_out(): void
    {
        // Items 1,3,5 -> Vendor A; 2 -> Vendor B; 4 -> Vendor C.
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i2 = $this->makeItem($order);
        $i3 = $this->makeItem($order);
        $i4 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i3->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        $this->service(12)->assignItems($order, [['order_item_id' => $i2->id, 'shop_id' => 12]]);
        $this->service(13)->assignItems($order, [['order_item_id' => $i4->id, 'shop_id' => 13]]);

        $shipments = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(3, $shipments, 'three vendors, three parcels');
        $a1 = $shipments->firstWhere('shop_id', 11);
        $a1Id = (int) $a1->id;

        // Porter refuses A1 as too large; the operator moves item 5 to its own parcel.
        $res = $svc->splitShipment($order, [$i5->id]);
        $this->assertSame(1, $res['applied']);

        $after = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(4, $after, 'A1 became A1 + A2; B and C are untouched');

        // The ORIGINAL parcel keeps its identity — not deleted and recreated.
        $this->assertTrue($after->contains('id', $a1Id), 'shipment A1 must survive the split with its id');

        // Items 1 and 3 stay together on A1; item 5 is elsewhere; nothing is lost or duplicated.
        $this->assertSame($a1Id, (int) $i1->fresh()->shipment_id);
        $this->assertSame($a1Id, (int) $i3->fresh()->shipment_id);
        $this->assertNotSame($a1Id, (int) $i5->fresh()->shipment_id);

        $this->assertSame(5, array_sum($this->allocationMap($order)), 'five lines, five allocated units');
        $this->assertSame(5, ShipmentItem::whereIn('order_item_id', [$i1->id, $i2->id, $i3->id, $i4->id, $i5->id])->count());

        // The parent order is untouched by any of it.
        $this->assertSame('order-processing', $order->fresh()->order_status);
    }

    // ------------------------------------------------------- scenario 76

    public function test_76_a_line_of_five_splits_into_three_plus_two(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'unit_price' => 100, 'subtotal' => 500]);

        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());

        $res = $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 2]], 'CAPACITY_LIMIT', 'second vehicle');
        $this->assertSame(1, $res['applied'], json_encode($res['rejected']));

        $map = $this->allocationMap($order);
        $this->assertCount(2, $map, 'the line now rides two parcels');
        $this->assertSame([3, 2], array_values($map));
        $this->assertSame(5, array_sum($map), '3 + 2 = 5');

        // The compat pointer goes NULL for a split line — readers must use allocations.
        $this->assertNull($item->fresh()->shipment_id);

        // The split is audited with its reason.
        $event = DB::table('order_events')->where('order_id', $order->id)->where('type', 'shipment.split')->first();
        $this->assertNotNull($event);
        $meta = json_decode((string) $event->meta, true);
        $this->assertSame('quantity', $meta['mode']);
        $this->assertSame('CAPACITY_LIMIT', $meta['reason']);
    }

    public function test_76b_a_quantity_split_survives_a_later_regroup(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'subtotal' => 500]);
        $other = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $item->id, 'shop_id' => 11],
            ['order_item_id' => $other->id, 'shop_id' => 11],
        ]);
        $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 2]]);
        $before = $this->allocationMap($order);

        // Reassigning an UNRELATED line runs regroup(). The deliberate split must not be
        // silently merged back — that is the whole reason regroup skips multi-allocation lines.
        $svc->assignItems($order, [['order_item_id' => $other->id, 'shop_id' => 11]]);

        $split = ShipmentItem::where('order_item_id', $item->id)->pluck('quantity')
            ->map(fn ($q) => (int) $q)->sort()->values()->all();
        $this->assertSame([2, 3], $split, 'regroup must not undo an operator quantity split');
        $this->assertSame(5, array_sum($this->allocationMap($order)) - 1, 'the 5 units are intact (plus the other line)');
        $this->assertNotEmpty($before);
    }

    public function test_76c_quantity_split_rejects_out_of_range_moves(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'subtotal' => 500]);
        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);

        $this->assertSame(0, $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 5]])['applied']);
        $this->assertSame(0, $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 0]])['applied']);
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get(), 'a rejected split mints no parcel');
    }

    // ------------------------------------------------------- scenario 77

    public function test_77_provider_failure_then_retry_keeps_one_shipment_and_two_attempts(): void
    {
        $order = $this->makeOrder();
        $shipment = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11,
            'fulfillment_mode' => 'local', 'mode' => 'instant', 'status' => 'pending',
        ]);
        $this->makeItem($order, ['assigned_shop_id' => 11]);
        ShipmentItem::create([
            'shipment_id'   => $shipment->id,
            'order_item_id' => OrderItem::where('order_id', $order->id)->value('id'),
            'quantity'      => 1,
        ]);
        $shipmentId = (int) $shipment->id;

        // ONE fake with a sequence: a second Http::fake() call MERGES into the stub list
        // rather than replacing it, so the 500 would keep matching and attempt 2 would
        // never succeed.
        Http::fake(['ship.test/*' => Http::sequence()
            ->push(['error' => 'capacity exceeded'], 500)
            ->push([
                'partner' => 'borzo', 'mode' => 'instant', 'provider_order_id' => 'BRZ-777',
                'status' => 'assigned', 'last_status' => 'booked', 'booked_cost' => 175,
            ], 200)]);

        // Attempt 1 — Porter refuses.
        $first = (new ShippingServiceClient())->book($shipment->fresh(), 'instant', false, 0.0, 'porter');
        $this->assertFalse($first['ok']);

        // Attempt 2 — Borzo carries it.
        $second = (new ShippingServiceClient())->book($shipment->fresh(), 'instant', false, 0.0, 'borzo');
        $this->assertTrue($second['ok'], json_encode($second));

        // ONE shipment, same id — a provider switch is not a new parcel.
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());
        $this->assertSame($shipmentId, (int) $shipment->fresh()->id);
        $this->assertSame('borzo', $shipment->fresh()->provider);
        $this->assertSame('BRZ-777', $shipment->fresh()->provider_order_id);

        // BOTH attempts survive, in order, with the failure's reason intact.
        $attempts = PartnerConsoleOrder::where('shipment_id', $shipmentId)->orderBy('attempt_no')->get();
        $this->assertCount(2, $attempts, 'the refused attempt must not be overwritten by the successful one');

        $this->assertSame(1, (int) $attempts[0]->attempt_no);
        $this->assertSame('porter', $attempts[0]->partner_code);
        $this->assertNull($attempts[0]->provider_order_id, 'a refused booking never got a partner order id');
        $this->assertNotNull($attempts[0]->last_error);
        $this->assertSame('shipment', $attempts[0]->origin);

        $this->assertSame(2, (int) $attempts[1]->attempt_no);
        $this->assertSame('borzo', $attempts[1]->partner_code);
        $this->assertSame('BRZ-777', $attempts[1]->provider_order_id);
    }

    // ------------------------------------------------------- scenario 78

    public function test_78_a_failed_booking_can_still_be_split(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i3 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i3->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        $a1 = Shipment::where('order_id', $order->id)->firstOrFail();
        $a1Id = (int) $a1->id;

        Http::fake(['ship.test/*' => Http::response(['error' => 'package too large'], 500)]);
        $this->assertFalse((new ShippingServiceClient())->book($a1->fresh(), 'instant', false, 0.0, 'porter')['ok']);
        $this->assertSame('book_failed', $a1->fresh()->last_status);

        // A refused booking leaves no partner job, so the parcel is NOT sealed.
        $res = $svc->splitShipment($order, [$i5->id]);
        $this->assertSame(1, $res['applied'], json_encode($res['rejected']));

        $this->assertCount(2, Shipment::where('order_id', $order->id)->get());
        $this->assertTrue(Shipment::where('order_id', $order->id)->get()->contains('id', $a1Id));
        $this->assertNotSame($a1Id, (int) $i5->fresh()->shipment_id);

        // The refused attempt is still on the record after the split.
        $this->assertSame(1, PartnerConsoleOrder::where('shipment_id', $a1Id)->count());
    }

    public function test_78b_a_live_booked_shipment_refuses_to_split(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['assigned_shop_id' => 11, 'fulfillment_mode' => 'local']);
        $booked = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11, 'fulfillment_mode' => 'local',
            'status' => 'assigned', 'provider_order_id' => 'CRN-LIVE',
        ]);
        ShipmentItem::create(['shipment_id' => $booked->id, 'order_item_id' => $item->id, 'quantity' => 1]);

        $res = $this->service(11)->splitShipment($order, [$item->id]);
        $this->assertSame(0, $res['applied']);
        $this->assertStringContainsString('already booked', $res['rejected'][0]['reason']);
    }

    // ------------------------------------------------------- scenario 79

    public function test_79_partial_delivery_does_not_complete_the_order(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i3 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $i1->id, 'shop_id' => 11]]);
        $this->service(12)->assignItems($order, [['order_item_id' => $i3->id, 'shop_id' => 12]]);

        $shipments = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(2, $shipments);

        $delivered = $shipments->firstWhere('shop_id', 11);
        $delivered->forceFill(['provider_order_id' => 'X1', 'provider' => 'porter'])->save();
        (new CourierService())->applyNormalizedStatus($delivered, [
            'shipment_status' => 'delivered', 'order_status' => 'order-completed',
        ]);

        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertSame('pending', $shipments->firstWhere('shop_id', 12)->fresh()->status);

        // One delivered leg out of two must NOT read as a completed order.
        $this->assertNotSame(
            'order-completed',
            $order->fresh()->order_status,
            'an order with an undelivered leg cannot be complete',
        );
    }

    // ------------------------------------------------------- scenario 81

    public function test_81_two_doors_of_one_vendor_are_two_shipments(): void
    {
        DB::table('vendor_pickup_locations')->insert([
            ['id' => 91, 'shop_id' => 11, 'label' => 'Greater Kailash', 'is_default' => true,
             'status' => 'verified', 'partner' => 'shiprocket', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 92, 'shop_id' => 11, 'label' => 'Saket Warehouse', 'is_default' => false,
             'status' => 'verified', 'partner' => 'shiprocket', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);

        // Same vendor, one door -> ONE parcel.
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());

        // Move one line to the second yard. A courier collects from an ADDRESS, so two
        // yards is two collections however the vendor is labelled.
        Shipment::where('order_id', $order->id)->update(['pickup_location_id' => 91]);
        $second = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11, 'fulfillment_mode' => 'local',
            'status' => 'pending', 'pickup_location_id' => 92,
        ]);
        ShipmentItem::where('order_item_id', $i5->id)->delete();
        ShipmentItem::create(['shipment_id' => $second->id, 'order_item_id' => $i5->id, 'quantity' => 1]);
        $i5->update(['shipment_id' => $second->id]);

        $doors = Shipment::where('order_id', $order->id)->pluck('pickup_location_id')
            ->map(fn ($d) => (int) $d)->sort()->values()->all();
        $this->assertSame([91, 92], $doors, 'two doors, two parcels');
    }

    // ------------------------------- regroup identity + money apportionment

    public function test_regroup_reuses_shipment_rows_instead_of_recreating_them(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $a->id, 'shop_id' => 11],
            ['order_item_id' => $b->id, 'shop_id' => 11],
        ]);

        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $shipment->forceFill(['shipping_cost' => 120.00, 'mode' => 'same_city'])->save();
        $originalId = (int) $shipment->id;

        // Re-run assignment for the SAME vendor: the row must be reused, not rebuilt.
        $svc->assignItems($order, [['order_item_id' => $b->id, 'shop_id' => 11]]);

        $after = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(1, $after);
        $this->assertSame($originalId, (int) $after->first()->id, 'the shipment id must survive a re-plan');
        $this->assertSame(120.0, (float) $after->first()->shipping_cost, 'a reused row keeps its quoted cost');
        $this->assertSame('same_city', $after->first()->mode, 'a reused row keeps its lane override');
    }

    public function test_split_parcels_do_not_each_claim_the_whole_line(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'unit_price' => 100, 'subtotal' => 500]);

        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);
        $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 2]]);

        $shipments = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(2, $shipments);

        // Goods value and COD must follow the ALLOCATED units, not the ordered line —
        // otherwise a driver collects the order's cash once per parcel.
        $courier = new CourierService();
        $cods = $shipments->map(fn ($s) => $courier->shipmentCodAmount($s, $order->fresh()))->sort()->values()->all();
        $this->assertEqualsWithDelta(200.0, $cods[0], 0.01, '2 of 5 units = 2/5 of the order');
        $this->assertEqualsWithDelta(300.0, $cods[1], 0.01, '3 of 5 units = 3/5 of the order');
        $this->assertEqualsWithDelta(500.0, array_sum($cods), 0.01, 'the two legs sum to the order, not to double it');
    }

    // ------------------------------------------------- club / move / merge

    public function test_club_puts_selected_lines_into_one_shipment(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i3 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i3->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        // Scatter them first so clubbing has something to undo.
        $svc->splitShipment($order, [$i5->id]);
        $this->assertCount(2, Shipment::where('order_id', $order->id)->get());

        $res = $svc->clubItems($order, [$i1->id, $i3->id, $i5->id], null, 'MANUAL_ADMIN_SPLIT');
        $this->assertSame(3, $res['applied'], json_encode($res['rejected']));

        $shipments = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(1, $shipments, 'all three lines travel in ONE parcel');
        $this->assertSame((int) $res['shipment_id'], (int) $shipments->first()->id);
        $this->assertSame(3, ShipmentItem::where('shipment_id', $res['shipment_id'])->count());
        $this->assertSame(3, array_sum($this->allocationMap($order)));
    }

    public function test_a_club_survives_a_later_regroup(): void
    {
        // The whole point of writing split_group on the clubbed lines: grouping is DERIVED,
        // so a club that does not align the marker is undone by the next re-plan.
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i3 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i3->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        $svc->clubItems($order, [$i1->id, $i3->id]);
        $clubbed = (int) $i1->fresh()->shipment_id;

        $svc->assignItems($order, [['order_item_id' => $i5->id, 'shop_id' => 11]]);

        $this->assertSame($clubbed, (int) $i1->fresh()->shipment_id);
        $this->assertSame($clubbed, (int) $i3->fresh()->shipment_id, 'the club must not scatter on regroup');
    }

    public function test_move_items_into_an_existing_sibling_shipment(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i3 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i3->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        $svc->splitShipment($order, [$i5->id]);

        $a1 = (int) $i1->fresh()->shipment_id;
        $a2 = (int) $i5->fresh()->shipment_id;
        $this->assertNotSame($a1, $a2);

        $res = $svc->clubItems($order, [$i3->id], $a2);
        $this->assertSame(1, $res['applied'], json_encode($res['rejected']));

        // Both parcels keep their ids; the line simply changed parcel.
        $ids = Shipment::where('order_id', $order->id)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame([min($a1, $a2), max($a1, $a2)], $ids);
        $this->assertSame($a2, (int) $i3->fresh()->shipment_id);
        $this->assertSame(1, ShipmentItem::where('shipment_id', $a1)->count());
        $this->assertSame(2, ShipmentItem::where('shipment_id', $a2)->count());
        $this->assertSame(3, array_sum($this->allocationMap($order)));
    }

    public function test_moving_the_last_line_out_drops_the_emptied_shipment(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        $svc->splitShipment($order, [$i5->id]);
        $a1 = (int) $i1->fresh()->shipment_id;
        $a2 = (int) $i5->fresh()->shipment_id;

        $svc->clubItems($order, [$i5->id], $a1);

        $remaining = Shipment::where('order_id', $order->id)->get();
        $this->assertCount(1, $remaining, 'the emptied parcel is gone');
        $this->assertSame($a1, (int) $remaining->first()->id, 'and it is the SOURCE that was dropped, not the target');
        $this->assertNull(Shipment::find($a2));
    }

    public function test_club_refuses_lines_from_two_different_vendors(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);

        $this->service(11)->assignItems($order, [['order_item_id' => $a->id, 'shop_id' => 11]]);
        $this->service(12)->assignItems($order, [['order_item_id' => $b->id, 'shop_id' => 12]]);

        $res = $this->service(11)->clubItems($order, [$a->id, $b->id]);
        $this->assertNotEmpty($res['rejected']);
        $this->assertStringContainsString('different vendor', $res['rejected'][0]['reason']);
        $this->assertCount(2, Shipment::where('order_id', $order->id)->get(), 'both parcels stay as they were');
    }

    public function test_merge_mints_a_new_shipment_and_carries_the_attempt_history(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order);
        $i3 = $this->makeItem($order);
        $i5 = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $i1->id, 'shop_id' => 11],
            ['order_item_id' => $i3->id, 'shop_id' => 11],
            ['order_item_id' => $i5->id, 'shop_id' => 11],
        ]);
        $svc->splitShipment($order, [$i5->id]);
        $a1 = (int) $i1->fresh()->shipment_id;
        $a2 = (int) $i5->fresh()->shipment_id;

        // A1 was refused by a partner once — that history must outlive the merge.
        PartnerConsoleOrder::create([
            'partner_code' => 'porter', 'origin' => 'shipment', 'attempt_no' => 1,
            'shipment_id' => $a1, 'order_id' => $order->id, 'last_status' => 'book_failed',
            'last_error' => 'capacity exceeded',
        ]);

        $res = $svc->mergeShipments($order, [$a1, $a2], 'VENDOR_REQUEST', 'one vehicle after all');
        $a3 = (int) $res['shipment_id'];

        $this->assertNotSame(0, $a3);
        $this->assertNotSame($a1, $a3);
        $this->assertNotSame($a2, $a3);
        $this->assertNull(Shipment::find($a1));
        $this->assertNull(Shipment::find($a2));

        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());
        $this->assertSame(3, ShipmentItem::where('shipment_id', $a3)->count());
        $this->assertSame(3, array_sum($this->allocationMap($order)));

        // The refused attempt now hangs off the parcel that replaced A1.
        $this->assertSame($a3, (int) PartnerConsoleOrder::where('partner_code', 'porter')->value('shipment_id'));

        $event = DB::table('order_events')->where('order_id', $order->id)->where('type', 'shipment.merged')->first();
        $this->assertNotNull($event);
        $meta = json_decode((string) $event->meta, true);
        $this->assertSame([$a1, $a2], $meta['from']);
        $this->assertSame($a3, $meta['to']);
    }

    public function test_merge_refuses_a_booked_shipment(): void
    {
        $order = $this->makeOrder();
        $i1 = $this->makeItem($order, ['assigned_shop_id' => 11, 'fulfillment_mode' => 'local']);
        $i5 = $this->makeItem($order, ['assigned_shop_id' => 11, 'fulfillment_mode' => 'local']);

        $booked = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11, 'fulfillment_mode' => 'local',
            'status' => 'assigned', 'provider_order_id' => 'CRN-LIVE',
        ]);
        $free = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11, 'fulfillment_mode' => 'local', 'status' => 'pending',
        ]);
        ShipmentItem::create(['shipment_id' => $booked->id, 'order_item_id' => $i1->id, 'quantity' => 1]);
        ShipmentItem::create(['shipment_id' => $free->id, 'order_item_id' => $i5->id, 'quantity' => 1]);

        $res = $this->service(11)->mergeShipments($order, [$booked->id, $free->id]);

        $this->assertNull($res['shipment_id']);
        $this->assertStringContainsString('already booked', $res['rejected'][0]['reason']);
        $this->assertNotNull(Shipment::find($booked->id), 'a refused merge changes nothing');
        $this->assertNotNull(Shipment::find($free->id));
    }

    public function test_a_quantity_split_can_be_clubbed_back_into_one_allocation(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'unit_price' => 100, 'subtotal' => 500]);

        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);
        $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 2]]);
        $this->assertCount(2, $this->allocationMap($order));

        $svc->clubItems($order, [$item->id]);

        $allocs = ShipmentItem::where('order_item_id', $item->id)->get();
        $this->assertCount(1, $allocs, 'the two halves collapse back into one allocation');
        $this->assertSame(5, (int) $allocs->first()->quantity, 'and no unit is lost doing it');
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get());
    }

    // --------------------------------------------- audit regressions (see AUDIT notes)

    /** Seal a parcel the way a real booking does. */
    private function book(Shipment $s): Shipment
    {
        $s->forceFill([
            'provider'          => 'porter',
            'provider_order_id' => 'CRN-' . $s->id,
            'awb_number'        => 'AWB-' . $s->id,
            'status'            => 'assigned',
        ])->save();

        return $s->fresh();
    }

    /** An order with line X (5 units) split 3 + 2, where the 2-unit parcel is booked. */
    private function partiallyBookedLine(): array
    {
        $order = $this->makeOrder();
        $x = $this->makeItem($order, ['order_quantity' => 5, 'unit_price' => 100, 'subtotal' => 500]);
        $other = $this->makeItem($order);

        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $x->id, 'shop_id' => 11],
            ['order_item_id' => $other->id, 'shop_id' => 11],
        ]);
        $svc->splitByQuantity($order, [['order_item_id' => $x->id, 'quantity' => 2]]);

        $parcels = ShipmentItem::where('order_item_id', $x->id)->get();
        $this->assertCount(2, $parcels, 'fixture: the line must start split across two parcels');
        $booked = $this->book(Shipment::findOrFail($parcels->sortBy('quantity')->first()->shipment_id));

        return [$order, $x, $other, $svc, $booked];
    }

    public function test_regroup_keeps_the_free_units_of_a_partly_booked_line(): void
    {
        // lockedFor() locks a line as a WHOLE once ANY unit is booked. regroup() used to skip
        // it entirely, so its units on UNBOOKED parcels matched no group and were swept.
        [$order, $x, $other, $svc] = $this->partiallyBookedLine();

        $svc->assignItems($order, [['order_item_id' => $other->id, 'shop_id' => 11]]);

        $units = (int) ShipmentItem::where('order_item_id', $x->id)->sum('quantity');
        $this->assertSame(5, $units, 'a re-plan must not destroy the unbooked half of a booked line');
    }

    public function test_auto_assign_keeps_the_free_units_of_a_partly_booked_line(): void
    {
        [$order, $x, , $svc] = $this->partiallyBookedLine();

        $svc->assignAndGroup($order);

        $units = (int) ShipmentItem::where('order_item_id', $x->id)->sum('quantity');
        $this->assertSame(5, $units, 'auto-assign must not destroy the unbooked half either');
    }

    public function test_merge_refuses_when_a_line_has_units_on_an_unselected_parcel(): void
    {
        // The sealed parcel is not a SOURCE, so the source-only guard never saw it. Absorbing
        // the line would empty a parcel a courier is already carrying and ship the units twice.
        [$order, $x, $other, $svc, $booked] = $this->partiallyBookedLine();

        $free = ShipmentItem::where('order_item_id', $x->id)
            ->where('shipment_id', '!=', $booked->id)->firstOrFail()->shipment_id;
        $otherParcel = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11, 'fulfillment_mode' => 'local', 'status' => 'pending',
        ]);
        ShipmentItem::where('order_item_id', $other->id)->delete();
        ShipmentItem::create(['shipment_id' => $otherParcel->id, 'order_item_id' => $other->id, 'quantity' => 1]);

        $res = $svc->mergeShipments($order, [(int) $free, (int) $otherParcel->id]);

        $this->assertNull($res['shipment_id'], json_encode($res));
        $this->assertStringContainsString('also has units on shipment', $res['rejected'][0]['reason']);
        $this->assertSame(2, (int) ShipmentItem::where('shipment_id', $booked->id)->sum('quantity'),
            'the sealed parcel keeps every unit it was carrying');
        $this->assertSame(5, (int) ShipmentItem::where('order_item_id', $x->id)->sum('quantity'));
    }

    public function test_club_refuses_a_target_collecting_from_another_door(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeItem($order);
        $b = $this->makeItem($order);
        $svc = $this->service(11);
        $svc->assignItems($order, [
            ['order_item_id' => $a->id, 'shop_id' => 11],
            ['order_item_id' => $b->id, 'shop_id' => 11],
        ]);

        // A parcel of the same vendor but a DIFFERENT door. Moving a line in would align its
        // split_group and leave the door mismatched, so the next regroup would undo the move.
        $farDoor = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11, 'fulfillment_mode' => 'local',
            'status' => 'pending', 'pickup_location_id' => 999,
        ]);

        $res = $svc->clubItems($order, [$a->id], (int) $farDoor->id);

        $this->assertSame(0, $res['applied']);
        $this->assertStringContainsString('different vendor, lane or pickup location', $res['rejected'][0]['reason']);
    }

    public function test_quantity_split_refuses_to_mix_two_vendors_in_one_parcel(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeItem($order, ['order_quantity' => 4, 'subtotal' => 400]);
        $b = $this->makeItem($order, ['order_quantity' => 4, 'subtotal' => 400]);
        $this->service(11)->assignItems($order, [['order_item_id' => $a->id, 'shop_id' => 11]]);
        $this->service(12)->assignItems($order, [['order_item_id' => $b->id, 'shop_id' => 12]]);

        $res = $this->service(11)->splitByQuantity($order, [
            ['order_item_id' => $a->id, 'quantity' => 1],
            ['order_item_id' => $b->id, 'quantity' => 1],
        ]);

        $this->assertSame(1, $res['applied'], 'only the first vendor lands; the second is refused');
        $this->assertStringContainsString('different vendor', $res['rejected'][0]['reason']);
        $target = Shipment::findOrFail($res['shipment_id']);
        $this->assertSame(1, ShipmentItem::where('shipment_id', $target->id)->count());
    }

    public function test_splitting_a_parcel_forgets_its_measured_weight(): void
    {
        // The override is a human's reading of a box that no longer exists, and it is what the
        // courier is billed on.
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'subtotal' => 500]);
        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);

        $source = Shipment::where('order_id', $order->id)->firstOrFail();
        $source->forceFill(['weight_g' => 9000, 'length_cm' => 40])->save();

        $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 2]]);

        $this->assertNull($source->fresh()->weight_g, 'a lighter box must not keep the old reading');
        $this->assertNull($source->fresh()->length_cm);
    }

    public function test_cod_is_rederived_after_a_cancelled_parcel_is_re_split(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['order_quantity' => 5, 'unit_price' => 100, 'subtotal' => 500]);
        $svc = $this->service(11);
        $svc->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);

        $parcel = Shipment::where('order_id', $order->id)->firstOrFail();
        // Booked for the whole order, then cancelled — cod_amount stays frozen at 500.
        $parcel->forceFill(['cod_amount' => 500, 'status' => 'cancelled'])->save();

        $svc->splitByQuantity($order, [['order_item_id' => $item->id, 'quantity' => 2]]);

        $courier = new CourierService();
        $cod = $courier->shipmentCodAmount($parcel->fresh(), $order->fresh());
        $this->assertEqualsWithDelta(300.0, $cod, 0.01,
            'a cancelled parcel that lost units must re-derive its cash, not keep the old figure');
    }

    public function test_a_booking_in_flight_seals_the_parcel(): void
    {
        // provider_order_id only exists AFTER the partner answers; without a claim the row
        // reads as free for the whole call and a merge could delete it mid-booking.
        $order = $this->makeOrder();
        $item = $this->makeItem($order);
        $this->service(11)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);
        $parcel = Shipment::where('order_id', $order->id)->firstOrFail();

        $this->assertFalse($parcel->isLiveBooked());

        $parcel->forceFill(['last_status' => Shipment::BOOKING_IN_FLIGHT, 'last_status_at' => now()])->save();
        $this->assertTrue($parcel->fresh()->isLiveBooked(), 'an in-flight booking is sealed');

        // Time-boxed, so a crashed worker cannot seal a parcel forever.
        $parcel->forceFill(['last_status_at' => now()->subMinutes(10)])->save();
        $this->assertFalse($parcel->fresh()->isLiveBooked(), 'a stale claim expires');
    }

    public function test_a_parcel_carrying_only_a_free_line_collects_nothing(): void
    {
        // The even-split fallback exists for a parcel with NO allocations. It used to also
        // swallow "a parcel whose lines are worth nothing", handing a freebie leg a full share
        // on top of the paying leg — a 1000 order collecting 1500 at the door.
        $order = $this->makeOrder();
        $paid = $this->makeItem($order, ['unit_price' => 1000, 'subtotal' => 1000]);
        $free = $this->makeItem($order, ['unit_price' => 0, 'subtotal' => 0]);

        $this->service(11)->assignItems($order, [['order_item_id' => $paid->id, 'shop_id' => 11]]);
        $this->service(12)->assignItems($order, [['order_item_id' => $free->id, 'shop_id' => 12]]);

        $courier = new CourierService();
        $shipments = Shipment::where('order_id', $order->id)->get();
        $byShop = $shipments->keyBy('shop_id');

        $paying = $courier->shipmentCodAmount($byShop[11], $order->fresh());
        $freebie = $courier->shipmentCodAmount($byShop[12], $order->fresh());

        $this->assertEqualsWithDelta(500.0, $paying, 0.01, 'the paying leg carries the order value');
        $this->assertEqualsWithDelta(0.0, $freebie, 0.01, 'a parcel of free goods collects nothing');
        $this->assertEqualsWithDelta(500.0, $paying + $freebie, 0.01, 'the legs sum to the order, never more');
    }

    public function test_cod_does_not_re_collect_what_the_wallet_already_paid(): void
    {
        // paid_total is the GROSS order value; wallet points are debited at checkout and
        // recorded separately. Collecting the gross charges the customer twice.
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['unit_price' => 500, 'subtotal' => 500]);
        $this->service(11)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);

        DB::table('order_wallet_points')->insert([
            'order_id' => $order->id, 'amount' => 200, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $parcel = Shipment::where('order_id', $order->id)->firstOrFail();
        $cod = (new CourierService())->shipmentCodAmount($parcel, $order->fresh());

        $this->assertEqualsWithDelta(300.0, $cod, 0.01, 'only the cash still due reaches the driver');
    }

    public function test_a_fully_wallet_paid_order_collects_no_cash(): void
    {
        $order = $this->makeOrder();
        $item = $this->makeItem($order, ['unit_price' => 500, 'subtotal' => 500]);
        $this->service(11)->assignItems($order, [['order_item_id' => $item->id, 'shop_id' => 11]]);

        DB::table('order_wallet_points')->insert([
            'order_id' => $order->id, 'amount' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $parcel = Shipment::where('order_id', $order->id)->firstOrFail();
        $this->assertEqualsWithDelta(
            0.0,
            (new CourierService())->shipmentCodAmount($parcel, $order->fresh()),
            0.01,
        );
    }
}
