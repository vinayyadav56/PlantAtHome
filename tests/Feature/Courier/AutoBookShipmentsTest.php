<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shipment;
use Marvel\Events\OrderProcessed;
use Marvel\Events\PaymentSuccess;
use Marvel\Listeners\BookCourierShipments;
use Marvel\Services\Courier\CourierService;
use RuntimeException;
use Tests\TestCase;

/**
 * BookCourierShipments auto-book listener + courier:sweep-undispatched alarm.
 *
 * CourierService is faked (its real book() calls the Go shipping-service over HTTP). The fake
 * records which shipments it was asked to book and, on success, stamps provider_order_id/awb the
 * way the real client does — so the idempotency guard (skip already-booked legs) is exercised for
 * real. Pins: COD books at OrderProcessed, prepaid waits for PaymentSuccess, duplicate events book
 * once, a booking failure throws (retry) without touching the committed order, and courier-off is
 * a no-op.
 */
final class AutoBookShipmentsTest extends TestCase
{
    private FakeCourier $courier;

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
            $t->string('delivery_mode')->nullable();
            $t->string('mode')->nullable();
            $t->string('status')->default('pending');
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->timestamps();
        });

        // Empty stubs for the Order model's default eager loads + the listener's Shipment::with('items').
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
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->timestamps();
        });
        // The REAL CourierService constructor reads Settings (self-delivery guard test below).
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });

        $this->courier = new FakeCourier();
        $this->app->instance(CourierService::class, $this->courier);
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

    private function makeShipment(Order $order, array $attrs = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id'         => $order->id,
            'status'           => 'pending',
            'fulfillment_mode' => 'courier',
        ], $attrs));
    }

    private function book(object $event): void
    {
        (new BookCourierShipments())->handle($event);
    }

    public function test_cod_order_books_every_leg_at_order_processed(): void
    {
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $s1 = $this->makeShipment($order);
        $s2 = $this->makeShipment($order);

        $this->book(new OrderProcessed($order));

        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $this->courier->booked);
        $this->assertNotNull($s1->fresh()->provider_order_id);
        $this->assertNotNull($s2->fresh()->awb_number);
    }

    public function test_duplicate_event_books_each_leg_only_once(): void
    {
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $this->makeShipment($order);
        $this->makeShipment($order);

        $this->book(new OrderProcessed($order));
        $this->book(new OrderProcessed($order)); // replay

        $this->assertCount(2, $this->courier->booked, 'a replayed event must not re-book already-booked legs');
    }

    public function test_prepaid_pending_waits_then_books_on_payment_success(): void
    {
        $order = $this->makeOrder(['payment_gateway' => 'RAZORPAY', 'payment_status' => 'payment-pending']);
        $shipment = $this->makeShipment($order);

        // OrderProcessed fires while payment is still pending — must NOT book.
        $this->book(new OrderProcessed($order));
        $this->assertSame([], $this->courier->booked);
        $this->assertNull($shipment->fresh()->provider_order_id);

        // Payment settles.
        $order->forceFill(['payment_status' => 'payment-success'])->save();
        $this->book(new PaymentSuccess($order));

        $this->assertSame([$shipment->id], $this->courier->booked);
        $this->assertNotNull($shipment->fresh()->provider_order_id);
    }

    public function test_booking_failure_throws_for_retry_without_touching_the_order(): void
    {
        $this->courier->fail = true;
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $shipment = $this->makeShipment($order);

        $threw = false;
        try {
            $this->book(new OrderProcessed($order));
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a booking failure must throw so the queue retries → failed_jobs');
        $this->assertSame([$shipment->id], $this->courier->booked, 'it attempted the booking');
        // The already-committed order and the shipment are untouched by the failed job.
        $this->assertSame('order-processing', $order->fresh()->order_status);
        $this->assertNull($shipment->fresh()->provider_order_id);
        $this->assertSame('pending', $shipment->fresh()->status);
    }

    public function test_one_vendor_group_stays_booked_when_a_sibling_fails(): void
    {
        // Vendor groups book independently: A must not be rolled back because B's
        // partner was down, and the retry must re-attempt ONLY B. Both the admin's
        // "Book selected (N)" retry and the queue replay lean on this — re-sending
        // an already-booked leg comes back "already booked, cancel first", which
        // would turn a partial success into a permanent failure.
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $ok     = $this->makeShipment($order);
        $broken = $this->makeShipment($order);
        $this->courier->failFor = [$broken->id];

        try {
            $this->book(new OrderProcessed($order));
        } catch (RuntimeException $e) {
            // expected — the queue retries the job
        }

        $this->assertNotNull($ok->fresh()->provider_order_id, 'the good leg keeps its booking');
        $this->assertNull($broken->fresh()->provider_order_id);

        $this->courier->booked = [];
        $this->courier->failFor = [];
        $this->book(new OrderProcessed($order));

        $this->assertSame([$broken->id], $this->courier->booked, 'the retry re-attempts only the leg that failed');
        $this->assertNotNull($broken->fresh()->awb_number);
    }

    public function test_courier_off_is_a_noop(): void
    {
        $this->courier->on = false;
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $shipment = $this->makeShipment($order);

        $this->book(new OrderProcessed($order));

        $this->assertSame([], $this->courier->booked);
        $this->assertNull($shipment->fresh()->provider_order_id);
    }

    public function test_sweep_alarms_only_on_payable_stuck_unbooked_legs(): void
    {
        config(['shop.undispatched_shipment_alert_minutes' => 120]);
        $old = now()->subHours(3);
        $young = now()->subMinutes(10);

        $codOrder = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $stuck = $this->makeShipment($codOrder, ['created_at' => $old, 'updated_at' => $old]);

        // Not alarmed: too young / already booked / order cancelled.
        $this->makeShipment($this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY']), ['created_at' => $young, 'updated_at' => $young]);
        $this->makeShipment($codOrder, ['created_at' => $old, 'updated_at' => $old, 'provider_order_id' => 'PO9', 'awb_number' => 'AWB9']);
        $cancelled = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-cancelled']);
        $this->makeShipment($cancelled, ['created_at' => $old, 'updated_at' => $old]);

        Log::spy();
        $this->artisan('courier:sweep-undispatched')->assertSuccessful();

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($msg, $ctx) use ($stuck) {
            return $msg === 'courier.undispatched.sweep'
                && $ctx['count'] === 1
                && $ctx['shipment_ids'] === [$stuck->id];
        });
    }

    public function test_sweep_is_noop_when_courier_off(): void
    {
        $this->courier->on = false;
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY']);
        $this->makeShipment($order, ['created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

        Log::spy();
        $this->artisan('courier:sweep-undispatched')->assertSuccessful();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_self_delivery_legs_are_never_auto_booked(): void
    {
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $self = $this->makeShipment($order, ['delivery_mode' => 'self', 'fulfillment_mode' => 'local']);
        $platform = $this->makeShipment($order);

        // Must NOT throw: the self leg is not a failure, it's the vendor's to fulfil.
        $this->book(new OrderProcessed($order));

        $this->assertSame([$platform->id], $this->courier->booked, 'only the platform leg books');
        $this->assertNull($self->fresh()->provider_order_id);
        $this->assertNull($self->fresh()->awb_number);
    }

    public function test_sweep_never_alarms_on_self_delivery_legs(): void
    {
        config(['shop.undispatched_shipment_alert_minutes' => 120]);
        $old = now()->subHours(3);
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY', 'order_status' => 'order-processing']);
        $this->makeShipment($order, ['delivery_mode' => 'self', 'created_at' => $old, 'updated_at' => $old]);

        Log::spy();
        $this->artisan('courier:sweep-undispatched')->assertSuccessful();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_courier_service_book_refuses_a_self_delivery_shipment(): void
    {
        // REAL CourierService — the self guard must fire before any config/HTTP,
        // protecting the admin book/dispatch buttons and any future caller.
        $order = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY']);
        $self = $this->makeShipment($order, ['delivery_mode' => 'self']);

        $res = (new CourierService())->book($self);

        $this->assertFalse((bool) ($res['ok'] ?? true));
        $this->assertSame('SELF_DELIVERY', $res['code'] ?? null);
    }
}

/**
 * Stand-in for CourierService: skips the DB-reading constructor, is always "enabled" unless told
 * otherwise, and mimics the real client's book() (records the call + stamps provider ids on success).
 */
class FakeCourier extends CourierService
{
    /** @var int[] shipment ids we were asked to book, in order (includes retries). */
    public array $booked = [];
    public bool $on = true;
    public bool $fail = false;
    /** @var int[] shipment ids that fail while the rest succeed. */
    public array $failFor = [];

    public function __construct()
    {
        // Deliberately skip parent::__construct() — it reads Settings + courier_partner_configs.
    }

    public function enabled(): bool
    {
        return $this->on;
    }

    // $courierId: the auto-book listener never passes one — a courier chosen off a quote is only
    // ever sent by the dispatch that just validated it against a fresh rate card.
    public function book(Shipment $shipment, ?string $partnerCode = null, ?int $courierId = null, array $options = []): array
    {
        $this->booked[] = (int) $shipment->id;
        if ($this->fail || in_array((int) $shipment->id, $this->failFor, true)) {
            return ['ok' => false, 'error' => 'shipping service down'];
        }
        $shipment->forceFill([
            'provider_order_id' => 'PO' . $shipment->id,
            'awb_number'        => 'AWB' . $shipment->id,
            'status'            => 'assigned',
        ])->save();
        return ['ok' => true];
    }
}
