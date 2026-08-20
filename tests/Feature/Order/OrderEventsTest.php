<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderEvent;
use Marvel\Database\Models\Shipment;
use Marvel\Http\Controllers\OrderAssignmentController;
use Marvel\Services\Courier\CourierService;
use Tests\TestCase;

/**
 * Order activity log (order_events): the model observer writes created/status/payment
 * events for REAL changes only; applyNormalizedStatus logs only real forward shipment
 * transitions (replays + terminal-sticky are silent); the events endpoint returns
 * newest-first capped at 50; and record() never throws — even with the table missing.
 */
final class OrderEventsTest extends TestCase
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

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
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

        // Allocation ledger: WHICH UNITS of an order line ride on WHICH shipment. Shipment::items()
        // is a belongsToMany through this table, so every stub that has `shipments` needs it too.
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
            $t->string('mode')->nullable();
            $t->string('status')->default('pending');
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
        });

        // Empty stubs for the Order model's default eager loads.
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

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'tracking_number' => 'T' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'order_status'    => 'order-pending',
            'payment_status'  => 'payment-pending',
            'payment_gateway' => 'RAZORPAY',
        ], $attrs));
    }

    private function eventsFor(Order $order, string $type): \Illuminate\Support\Collection
    {
        return OrderEvent::where('order_id', $order->id)->where('type', $type)->get();
    }

    public function test_order_create_writes_one_created_event(): void
    {
        $order = $this->makeOrder();

        $events = $this->eventsFor($order, 'order.created');
        $this->assertCount(1, $events);
        $this->assertSame('order-pending', $events->first()->meta['order_status']);
    }

    public function test_status_change_writes_from_to_and_noop_save_writes_nothing(): void
    {
        $order = $this->makeOrder();

        $order->order_status = 'order-processing';
        $order->save();

        $events = $this->eventsFor($order, 'order.status');
        $this->assertCount(1, $events);
        $this->assertSame(['from' => 'order-pending', 'to' => 'order-processing'], [
            'from' => $events->first()->meta['from'],
            'to'   => $events->first()->meta['to'],
        ]);

        // Saving without a change must stay silent.
        $order->save();
        $order->touch();
        $this->assertCount(1, $this->eventsFor($order, 'order.status'));
    }

    public function test_payment_status_change_writes_event(): void
    {
        $order = $this->makeOrder();

        $order->payment_status = 'payment-success';
        $order->save();

        $events = $this->eventsFor($order, 'payment.status');
        $this->assertCount(1, $events);
        $this->assertSame('payment-pending', $events->first()->meta['from']);
        $this->assertSame('payment-success', $events->first()->meta['to']);
    }

    public function test_shipment_transition_logs_once_and_replay_is_silent(): void
    {
        $order = $this->makeOrder();
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'assigned']);

        $svc = new BareCourier();
        $svc->applyNormalizedStatus($shipment, ['shipment_status' => 'shipped']);
        $svc->applyNormalizedStatus($shipment->fresh(), ['shipment_status' => 'shipped']); // replay

        $events = $this->eventsFor($order, 'shipment.status');
        $this->assertCount(1, $events, 'a replayed webhook must not duplicate the event');
        $this->assertSame('assigned', $events->first()->meta['from']);
        $this->assertSame('shipped', $events->first()->meta['to']);
    }

    public function test_terminal_sticky_shipment_writes_nothing(): void
    {
        $order = $this->makeOrder();
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'delivered']);

        (new BareCourier())->applyNormalizedStatus($shipment, ['shipment_status' => 'rto']);

        $this->assertCount(0, $this->eventsFor($order, 'shipment.status'));
        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_events_endpoint_returns_newest_first_capped_at_50(): void
    {
        $order = $this->makeOrder(); // writes 1 order.created
        for ($i = 0; $i < 54; $i++) {
            OrderEvent::record($order->id, 'order.status', ['n' => $i]);
        }

        $events = (new OrderAssignmentController())->events($order->id);

        $this->assertCount(50, $events);
        $this->assertSame(53, $events->first()->meta['n'], 'newest first');
        $this->assertTrue($events->first()->id > $events->last()->id);
    }

    public function test_record_never_throws_even_without_the_table(): void
    {
        Schema::drop('order_events');

        OrderEvent::record(1, 'order.created');
        // Reaching here without an exception is the assertion; the order write
        // path must survive a missing/broken audit table.
        $this->assertTrue(true);
    }
}

/** CourierService with the DB-reading constructor skipped — applyNormalizedStatus is inherited real. */
class BareCourier extends CourierService
{
    public function __construct()
    {
    }
}
