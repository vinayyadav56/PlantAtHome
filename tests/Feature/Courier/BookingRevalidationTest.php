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
use Marvel\Database\Models\ShipmentItem;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\OrderItemService;
use Tests\TestCase;

/**
 * Pre-booking vendor-inventory revalidation.
 *
 * An assignment is a snapshot taken at checkout. A vendor can soft-delete the inventory
 * row, zero the stock, lose review approval or drop the service area before the courier is
 * ever called — and nothing upstream repairs order_items when they do. These tests pin the
 * behaviour at the booking funnel, which is the last point where that can be caught.
 */
final class BookingRevalidationTest extends TestCase
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
            $t->decimal('unit_price')->default(0);
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
            $t->decimal('cod_amount')->nullable();
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
        // Presence is all the revalidation schema guard asks of this table.
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->timestamps();
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
            $t->string('mobile')->nullable();
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
                // Hyperlocal refuses a pickup with no contact phone; these fixtures are about
                // re-validation, so give them the number a real vendor would have.
                'mobile' => '9998887777',
                'address' => json_encode(['street_address' => '1 Lane', 'city' => 'Delhi', 'zip' => '110001', 'country' => 'IN']),
            ]);
        }
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
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
            $t->string('sku')->nullable();
            $t->integer('weight')->nullable();
            $t->decimal('length', 8, 2)->nullable();
            $t->decimal('breadth', 8, 2)->nullable();
            $t->decimal('height', 8, 2)->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Jade Plant', 'sku' => 'JADE', 'weight' => 500, 'length' => null, 'breadth' => null, 'height' => null],
            ['id' => 2, 'name' => 'Snake Plant', 'sku' => 'SNAKE', 'weight' => 500, 'length' => null, 'breadth' => null, 'height' => null],
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

        Http::fake(['ship.test/*' => Http::response([
            'partner' => 'porter', 'mode' => 'instant', 'provider_order_id' => 'CRN-1',
            'status' => 'assigned', 'last_status' => 'booked',
        ], 200)]);
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

    /** A line already assigned to $shopId and riding $shipment at $qty units. */
    private function makeLine(Order $order, Shipment $shipment, int $shopId, int $productId = 1, int $qty = 1): OrderItem
    {
        $item = OrderItem::create([
            'order_id'         => $order->id,
            'product_id'       => $productId,
            'order_quantity'   => $qty,
            'unit_price'       => 100,
            'subtotal'         => 100 * $qty,
            'assigned_shop_id' => $shopId,
            'fulfillment_mode' => 'local',
            'shipment_id'      => $shipment->id,
            'item_status'      => 'assigned',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id, 'order_item_id' => $item->id, 'quantity' => $qty,
        ]);

        return $item;
    }

    private function makeShipment(Order $order, int $shopId, array $attrs = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id'         => $order->id,
            'shop_id'          => $shopId,
            'fulfillment_mode' => 'local',
            'mode'             => 'instant',
            'status'           => 'pending',
        ], $attrs));
    }

    /** Bind an OrderItemService whose engine answers from a product => [shopIds] map. */
    private function bindEngine(array $map, array $secondCallMap = null): void
    {
        $engine = new MapEngine($map, $secondCallMap);
        $this->app->instance(OrderItemService::class, new OrderItemService($engine));
    }

    private function courier(): CourierService
    {
        return new RevalAlwaysOnCourier();
    }

    private function events(Order $order, string $type): array
    {
        return DB::table('order_events')->where('order_id', $order->id)->where('type', $type)->get()->all();
    }

    /**
     * Events written by REVALIDATION only. A successful booking records its own
     * `shipment.booked`, which is not evidence that the fast path wrote anything.
     */
    private function revalEventCount(Order $order): int
    {
        return DB::table('order_events')->where('order_id', $order->id)
            ->whereIn('type', ['assignment.revalidated', 'assignment.stale', 'items.assigned'])
            ->count();
    }

    // ---------------------------------------------------------------- tests

    public function test_a_still_valid_assignment_books_with_zero_writes(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $this->makeLine($order, $shipment, 11);
        $this->bindEngine([1 => [11]]);

        $res = $this->courier()->book($shipment);

        $this->assertTrue((bool) ($res['ok'] ?? false), json_encode($res));
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get(), 'no re-plan');
        $this->assertSame(0, $this->revalEventCount($order), 'the fast path writes nothing');
        $this->assertArrayNotHasKey('replanned', $res);
    }

    public function test_a_stale_sole_line_is_reassigned_and_the_emptied_parcel_is_reported(): void
    {
        // Vendor A dropped the product after the order was placed; vendor B has it.
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $item = $this->makeLine($order, $shipment, 11);
        $this->bindEngine([1 => [12]]);

        $res = $this->courier()->book($shipment);

        $this->assertFalse((bool) ($res['ok'] ?? true));
        $this->assertSame('REASSIGNED', $res['code'] ?? null);
        $this->assertNotEmpty($res['replanned']);
        $this->assertSame(12, (int) $item->fresh()->assigned_shop_id, 'the line moved to vendor B');
        $this->assertNull(Shipment::find($shipment->id), 'the emptied original is gone');

        $new = Shipment::findOrFail($res['replanned'][0]);
        $this->assertSame(12, (int) $new->shop_id);
        $this->assertSame(1, ShipmentItem::where('shipment_id', $new->id)->count());
    }

    public function test_a_partial_reassignment_books_the_survivors_and_names_the_new_parcel(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $keeps = $this->makeLine($order, $shipment, 11, productId: 1);
        $moves = $this->makeLine($order, $shipment, 11, productId: 2);
        // Vendor A still has product 1, but not product 2.
        $this->bindEngine([1 => [11], 2 => [12]]);

        $res = $this->courier()->book($shipment);

        $this->assertTrue((bool) ($res['ok'] ?? false), json_encode($res));
        $this->assertNotEmpty($res['replanned'], 'the caller is told about the parcel it must also dispatch');
        $this->assertSame(11, (int) $keeps->fresh()->assigned_shop_id);
        $this->assertSame(12, (int) $moves->fresh()->assigned_shop_id);
        $this->assertNotNull(Shipment::find($shipment->id), 'the original survives with its id');
        $this->assertSame(1, ShipmentItem::where('shipment_id', $shipment->id)->count(), 'and shrinks to one line');
    }

    public function test_a_sibling_vendors_parcel_is_not_touched(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeShipment($order, 11);
        $c = $this->makeShipment($order, 13);
        $this->makeLine($order, $a, 11, productId: 1);
        $cLine = $this->makeLine($order, $c, 13, productId: 2);
        // Vendor C is stale too — but we are booking A, so C must be left alone.
        $this->bindEngine([1 => [11], 2 => [12]]);

        $res = $this->courier()->book($a);

        $this->assertTrue((bool) ($res['ok'] ?? false), json_encode($res));
        $this->assertSame(13, (int) $cLine->fresh()->assigned_shop_id, "the sibling parcel's assignment is untouched");
        $this->assertSame($c->id, (int) $cLine->fresh()->shipment_id);
    }

    public function test_no_eligible_vendor_blocks_the_booking_and_keeps_the_assignment(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $item = $this->makeLine($order, $shipment, 11);
        $this->bindEngine([1 => []]); // nobody has it

        $res = $this->courier()->book($shipment);

        $this->assertFalse((bool) ($res['ok'] ?? true));
        $this->assertSame('STALE_ASSIGNMENT', $res['code'] ?? null);
        $this->assertSame(1, count($res['items']));
        $this->assertSame(11, (int) $item->fresh()->assigned_shop_id, 'the stale assignment stays VISIBLE, not silently cleared');
        $this->assertSame($shipment->id, (int) $item->fresh()->shipment_id);
        $this->assertSame(1, ShipmentItem::where('shipment_id', $shipment->id)->count(), 'the line stays on its parcel');
        Http::assertNothingSent();

        $meta = json_decode((string) $this->events($order, 'assignment.stale')[0]->meta, true);
        $this->assertTrue($meta['blocked']);
    }

    public function test_a_line_sealed_under_a_live_booking_warns_but_still_books(): void
    {
        // The line's units also ride a booked sibling parcel, so nothing can move it.
        $order = $this->makeOrder();
        $booked = $this->makeShipment($order, 11, [
            'provider_order_id' => 'CRN-LIVE', 'awb_number' => 'AWB-LIVE', 'status' => 'assigned',
        ]);
        $target = $this->makeShipment($order, 11, ['split_group' => '9911']);
        $item = $this->makeLine($order, $booked, 11, qty: 3);
        ShipmentItem::create(['shipment_id' => $target->id, 'order_item_id' => $item->id, 'quantity' => 2]);
        $this->bindEngine([1 => []]); // vendor A is stale, and nobody replaces it

        $res = $this->courier()->book($target);

        $this->assertTrue((bool) ($res['ok'] ?? false), 'a sealed line must not dead-end the operator: ' . json_encode($res));
        $this->assertSame(11, (int) $item->fresh()->assigned_shop_id);
        $meta = json_decode((string) $this->events($order, 'assignment.stale')[0]->meta, true);
        $this->assertFalse($meta['blocked']);
        $this->assertTrue($meta['locked']);
    }

    public function test_a_second_attempt_after_a_replan_is_a_clean_no_op(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $this->makeLine($order, $shipment, 11, productId: 1);
        $this->makeLine($order, $shipment, 11, productId: 2);
        $this->bindEngine([1 => [11], 2 => [12]]);

        $first = $this->courier()->book($shipment);
        $this->assertTrue((bool) ($first['ok'] ?? false));
        $newId = (int) $first['replanned'][0];
        $eventsAfterFirst = $this->revalEventCount($order);

        // Book the sibling: its own revalidation must find vendor B valid and write nothing.
        $res = $this->courier()->book(Shipment::findOrFail($newId));

        $this->assertTrue((bool) ($res['ok'] ?? false), json_encode($res));
        $this->assertArrayNotHasKey('replanned', $res);
        $this->assertSame(
            $eventsAfterFirst,
            $this->revalEventCount($order),
            'the second pass is idempotent — no further re-plan, no further events',
        );
    }

    public function test_a_replacement_that_vanishes_during_the_recheck_fails_closed(): void
    {
        // Detection sees vendor B; assignItems' own re-check (the second engine call) sees
        // nobody. The known-bad assignment must NOT be dispatched.
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $item = $this->makeLine($order, $shipment, 11);
        $this->bindEngine([1 => [12]], secondCallMap: [1 => []]);

        $res = $this->courier()->book($shipment);

        $this->assertFalse((bool) ($res['ok'] ?? true));
        $this->assertSame('STALE_ASSIGNMENT', $res['code'] ?? null);
        $this->assertSame(11, (int) $item->fresh()->assigned_shop_id, 'the old assignment is left intact');
        Http::assertNothingSent();
    }

    public function test_validation_is_skipped_when_the_schema_predates_it(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeShipment($order, 11);
        $this->makeLine($order, $shipment, 11);
        $this->bindEngine([1 => []]); // would block, if validation ran

        Schema::drop('vendor_product_prices');
        $res = $this->courier()->book($shipment);

        $this->assertTrue((bool) ($res['ok'] ?? false), 'a mid-migration box books exactly as before');
        $this->assertSame(0, $this->revalEventCount($order), 'and validates nothing');
    }
}

/** CourierService with the DB-reading constructor skipped and the master switch forced on. */
class RevalAlwaysOnCourier extends CourierService
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

/**
 * Engine stub answering from a product_id => [eligible shop ids] map — the fixed-vendor
 * FakeAssignmentEngine cannot express "vendor A is gone, vendor B has it, nobody has it".
 * An optional second map models a vendor disappearing between detection and re-check.
 */
class MapEngine extends ItemAssignmentService
{
    private int $calls = 0;

    public function __construct(private array $map, private ?array $secondCallMap = null)
    {
        parent::__construct();
    }

    public function candidatesFor(
        int $productId,
        ?int $variationOptionId,
        int $qty,
        ?string $city,
        ?string $pincode = null,
        ?array $customer = null
    ): array {
        $this->calls++;
        $map = ($this->secondCallMap !== null && $this->calls > 1) ? $this->secondCallMap : $this->map;

        return array_map(fn ($shopId) => [
            'shop_id'          => $shopId,
            'vendor_name'      => "Vendor {$shopId}",
            'fulfillment_mode' => 'local',
            'eta_days'         => 2,
            'selling_price'    => 100,
            'shipping_cost'    => 0,
        ], $map[$productId] ?? []);
    }

    public function bestFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): ?array
    {
        return $this->candidatesFor($productId, $variationOptionId, $qty, $city, $pincode)[0] ?? null;
    }
}
