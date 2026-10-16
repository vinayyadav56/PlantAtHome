<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;
use Marvel\Events\OrderCancelled;
use Marvel\Events\OrderCreated;
use Marvel\Events\OrderReceived;
use Marvel\Events\OrderStatusChanged;
use Marvel\Events\PaymentFailed;
use Marvel\Events\PaymentSuccess;
use Marvel\Facades\Payment;
use Marvel\Database\Models\Settings;
use Marvel\Payments\Razorpay;
use Marvel\Traits\PaymentStatusManagerWithOrderTrait;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Payment-webhook hardening:
 *  - /webhooks/{gateway} endpoints for gateways NOT on the allowlist 404
 *    before any payment code runs (they were 11 unauthenticated aliases into
 *    the live active-gateway handler).
 *  - Razorpay fails closed (404) when its webhook secret isn't configured,
 *    and 400s a request without body+signature (abort, not the old exit()).
 *  - Replays are idempotent: webhookSuccessResponse refuses to touch an order
 *    already in the target state or in a final state (no re-fired events —
 *    which used to mean re-sent emails/SMS per replay).
 */
final class RazorpayWebhookTest extends TestCase
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

        // Settings row for Payments\Base::__construct (currency lookup).
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert([
            'options'  => json_encode([
                'currency'              => 'INR',
                'defaultPaymentGateway' => 'razorpay',
                'paymentGateway'        => [['name' => 'razorpay', 'title' => 'Razorpay']],
            ]),
            'language' => 'en',
        ]);

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->boolean('is_pinned')->default(false);
            $t->string('language')->default('en');
            $t->timestamp('placed_at')->nullable()->useCurrent();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('tracking_number')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->text('payment_intent_info')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        // Order model default eager loads.
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
    }

    public function test_unlisted_gateway_webhooks_are_dead_404s(): void
    {
        config(['shop.enabled_webhook_gateways' => ['razorpay']]);

        $this->postJson('/api/webhooks/xendit', [])->assertStatus(404);
        $this->postJson('/api/webhooks/bkash', [])->assertStatus(404);
        $this->postJson('/api/webhooks/stripe', [])->assertStatus(404);
        $this->postJson('/api/webhooks/flutterwave', [])->assertStatus(404);
    }

    public function test_razorpay_webhook_fails_closed_without_configured_secret(): void
    {
        config([
            'shop.enabled_webhook_gateways' => ['razorpay'],
            'shop.razorpay.webhook_secret'  => null,
        ]);

        $this->postJson('/api/webhooks/razorpay', ['event' => 'payment.captured'])->assertStatus(404);
    }

    public function test_razorpay_webhook_rejects_missing_signature_with_400(): void
    {
        config([
            'shop.enabled_webhook_gateways'   => ['razorpay'],
            'shop.active_payment_gateway'     => 'razorpay',
            'shop.razorpay.key_id'            => 'rzp_test_dummy',
            'shop.razorpay.key_secret'        => 'dummy-secret',
            'shop.razorpay.webhook_secret'    => 'whsec-dummy',
        ]);

        // No X-Razorpay-Signature and no raw body ⇒ the fail-closed branch
        // (now abort(400), previously a bare exit() that skipped the kernel).
        $this->postJson('/api/webhooks/razorpay', ['event' => 'payment.captured'])
            ->assertStatus(400);
    }

    public function test_replayed_webhook_in_target_state_changes_nothing_and_refires_nothing(): void
    {
        config([
            'shop.razorpay.key_id'     => 'rzp_test_dummy',
            'shop.razorpay.key_secret' => 'dummy-secret',
        ]);
        $order = Order::create([
            'tracking_number' => '20260810000001',
            'order_status'    => OrderStatus::PROCESSING,
            'payment_status'  => PaymentStatus::SUCCESS,
            'payment_gateway' => 'RAZORPAY',
        ]);

        Event::fake([PaymentSuccess::class, PaymentFailed::class, OrderStatusChanged::class, OrderCancelled::class]);
        (new Razorpay())->webhookSuccessResponse($order, OrderStatus::PROCESSING, PaymentStatus::SUCCESS);

        Event::assertNotDispatched(PaymentSuccess::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PROCESSING, $fresh->order_status);
        $this->assertSame(PaymentStatus::SUCCESS, $fresh->payment_status);
    }

    public function test_final_state_orders_are_never_touched_by_webhooks(): void
    {
        config([
            'shop.razorpay.key_id'     => 'rzp_test_dummy',
            'shop.razorpay.key_secret' => 'dummy-secret',
        ]);
        $order = Order::create([
            'tracking_number' => '20260810000002',
            'order_status'    => OrderStatus::COMPLETED,
            'payment_status'  => PaymentStatus::SUCCESS,
            'payment_gateway' => 'RAZORPAY',
        ]);

        Event::fake([PaymentSuccess::class, PaymentFailed::class, OrderStatusChanged::class, OrderCancelled::class]);
        // A late/duplicate 'authorized' replay must not regress a completed order.
        (new Razorpay())->webhookSuccessResponse($order, OrderStatus::PENDING, PaymentStatus::PROCESSING);

        Event::assertNotDispatched(PaymentSuccess::class);
        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::COMPLETED, $fresh->order_status);
        $this->assertSame(PaymentStatus::SUCCESS, $fresh->payment_status);
    }

    private function razorpayKeys(): void
    {
        config(['shop.razorpay.key_id' => 'rzp_test_dummy', 'shop.razorpay.key_secret' => 'dummy-secret']);
    }

    /** An unpaid online order with the Razorpay order it was registered under (amount in PAISE, as getIntent stores it). */
    private function attempt(string $tracking, array $attrs = []): Order
    {
        $order = Order::create($attrs + [
            'tracking_number' => $tracking,
            'order_status'    => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::PENDING,
            'payment_gateway' => 'RAZORPAY',
        ]);
        DB::table('payment_intents')->insert([
            'order_id'            => $order->id,
            'tracking_number'     => $tracking,
            'payment_gateway'     => 'Razorpay',
            'payment_intent_info' => json_encode(['payment_id' => 'order_' . $tracking, 'amount' => 49900]),
        ]);
        return $order;
    }

    private function capture(string $tracking, int $paise): Request
    {
        return Request::create('/api/webhooks/razorpay', 'POST', ['payload' => ['payment' => ['entity' => [
            'id' => 'pay_' . $tracking, 'order_id' => 'order_' . $tracking, 'amount' => $paise, 'status' => 'captured',
        ]]]]);
    }

    private function fakeOrderEvents(): void
    {
        Event::fake([PaymentSuccess::class, PaymentFailed::class, OrderStatusChanged::class, OrderCancelled::class, OrderCreated::class, OrderReceived::class]);
    }

    public function test_a_capture_equal_to_the_intent_amount_marks_the_order_paid(): void
    {
        // The intent already holds paise. Multiplying it by 100 again made every capture look
        // 100× underpaid, so the webhook could never confirm a payment.
        $this->razorpayKeys();
        $order = $this->attempt('20261016000001');
        $this->fakeOrderEvents();

        (new Razorpay())->updatePaymentOrderStatus($this->capture('20261016000001', 49900), OrderStatus::PROCESSING, PaymentStatus::SUCCESS);

        $this->assertSame(PaymentStatus::SUCCESS, $order->fresh()->payment_status);
        Event::assertDispatched(PaymentSuccess::class);
    }

    public function test_an_underpaid_capture_never_marks_the_order_paid(): void
    {
        $this->razorpayKeys();
        $order = $this->attempt('20261016000002');
        $this->fakeOrderEvents();

        (new Razorpay())->updatePaymentOrderStatus($this->capture('20261016000002', 49800), OrderStatus::PROCESSING, PaymentStatus::SUCCESS);

        $this->assertSame(PaymentStatus::PENDING, $order->fresh()->payment_status);
        Event::assertNotDispatched(PaymentSuccess::class);
    }

    public function test_a_capture_on_a_closed_attempt_does_not_reopen_it(): void
    {
        // The customer abandoned the attempt (or switched it to COD), then a late payment landed:
        // the money is recorded and flagged for a refund, the order stays as the customer left it.
        $this->razorpayKeys();
        $order = $this->attempt('20261016000003', ['order_status' => OrderStatus::CANCELLED, 'payment_status' => PaymentStatus::FAILED]);
        $this->fakeOrderEvents();

        (new Razorpay())->updatePaymentOrderStatus($this->capture('20261016000003', 49900), OrderStatus::PROCESSING, PaymentStatus::SUCCESS);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->order_status);
        $this->assertSame(PaymentStatus::FAILED, $fresh->payment_status);
        Event::assertNotDispatched(PaymentSuccess::class);
    }

    public function test_a_late_webhook_never_moves_a_paid_order_backwards(): void
    {
        // Gateways deliver webhooks out of order: a payment.failed from an earlier attempt can
        // arrive after the capture.
        $this->razorpayKeys();
        $order = Order::create([
            'tracking_number' => '20261016000004',
            'order_status'    => OrderStatus::PROCESSING,
            'payment_status'  => PaymentStatus::SUCCESS,
            'payment_gateway' => 'RAZORPAY',
        ]);
        $this->fakeOrderEvents();

        (new Razorpay())->webhookSuccessResponse($order, OrderStatus::PENDING, PaymentStatus::FAILED);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::SUCCESS, $fresh->payment_status);
        $this->assertSame(OrderStatus::PROCESSING, $fresh->order_status);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_an_attempted_razorpay_order_is_left_for_the_webhook_to_settle(): void
    {
        // "attempted" also covers an order whose only attempts FAILED. Marking it
        // payment-processing parked failed attempts where no sweep ever looks.
        $order = Order::create([
            'tracking_number' => '20261016000005',
            'order_status'    => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::PENDING,
            'payment_gateway' => 'RAZORPAY',
        ]);
        $order->payment_intent = [(object) ['payment_intent_info' => ['payment_id' => 'order_20261016000005']]];
        Payment::swap(new class {
            public function verify($id): string
            {
                return 'attempted';
            }
        });
        $this->fakeOrderEvents();
        $confirm = new class {
            use PaymentStatusManagerWithOrderTrait;
        };

        $confirm->razorpay($order, Request::create('/', 'POST', ['payment_gateway' => 'razorpay']), new Settings());

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PENDING, $fresh->payment_status);
        $this->assertSame(OrderStatus::PENDING, $fresh->order_status);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }
}
