<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;
use Marvel\Events\OrderCancelled;
use Marvel\Events\OrderCreated;
use Marvel\Events\OrderProcessed;
use Marvel\Events\OrderReceived;
use Marvel\Events\OrderStatusChanged;
use Marvel\Events\PaymentSuccess;
use Marvel\Payments\Razorpay;
use Tests\TestCase;

/**
 * The payment-failed prompt (owner rule, 2026-10-16): "Payment failed — no worries, you can still
 * place this order with Cash on Delivery. Place order? Yes / No".
 *   Yes → POST orders/{tn}/cash-on-delivery   No → POST orders/{tn}/abandon-payment
 * Razorpay is asked first every time: money that moved is never switched to COD or cancelled.
 */
final class PaymentAttemptEndpointsTest extends TestCase
{
    /** What the stubbed Razorpay says about the attempt's payments: none|authorized|captured|error. */
    private string $razorpay = 'none';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->string('tracking_token')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('coupon_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->string('altered_payment_gateway')->nullable();
            $t->decimal('paid_total', 10, 2)->default(0);
            $t->boolean('inventory_restored')->default(false);
            $t->boolean('is_pinned')->default(false);
            $t->string('language')->default('en');
            $t->timestamp('placed_at')->nullable()->useCurrent();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->boolean('track_stock')->default(false);
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
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('tracking_number')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->text('payment_intent_info')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_wallet_points', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->decimal('amount', 10, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('customer_id');
            $t->integer('total_points')->default(0);
            $t->integer('points_used')->default(0);
            $t->integer('available_points')->default(0);
            $t->timestamps();
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('code');
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('times_used')->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('coupon_usages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('coupon_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('order_id');
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        $this->settings(['useCashOnDelivery' => true]);

        $test = $this;
        $this->app->instance(Razorpay::class, new class ($test) {
            public function __construct(private PaymentAttemptEndpointsTest $test)
            {
            }

            public function paymentState(string $razorpayOrderId): string
            {
                return $this->test->razorpaySays();
            }
        });
        Event::fake([
            OrderCreated::class, OrderReceived::class, OrderProcessed::class, OrderCancelled::class,
            PaymentSuccess::class, OrderStatusChanged::class,
        ]);
    }

    public function razorpaySays(): string
    {
        if ($this->razorpay === 'error') {
            throw new \RuntimeException('Razorpay timed out');
        }
        return $this->razorpay;
    }

    private function settings(array $options): void
    {
        DB::table('settings')->delete();
        DB::table('settings')->insert(['options' => json_encode($options + ['currencyToWalletRatio' => 3]), 'language' => 'en']);
    }

    /** An unpaid online checkout (one child), as POST /orders leaves it, with its Razorpay order. */
    private function attempt(array $attrs = []): Order
    {
        $order = Order::create($attrs + [
            'tracking_number' => '20261016100001',
            'tracking_token'  => 'tok',
            'customer_id'     => 7,
            'order_status'    => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::PENDING,
            'payment_gateway' => 'RAZORPAY',
            'paid_total'      => 388,
            'placed_at'       => null,
        ]);
        Order::create([
            'tracking_number' => '20261016100001-1',
            'parent_id'       => $order->id,
            'order_status'    => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::PENDING,
            'payment_gateway' => 'RAZORPAY',
            'placed_at'       => null,
        ]);
        DB::table('payment_intents')->insert([
            'order_id' => $order->id, 'tracking_number' => $order->tracking_number, 'payment_gateway' => 'Razorpay',
            'payment_intent_info' => json_encode(['payment_id' => 'order_RZP1', 'amount' => 38800]),
        ]);
        return $order;
    }

    private function yes(string $token = 'tok')
    {
        return $this->postJson('/api/orders/20261016100001/cash-on-delivery', ['token' => $token]);
    }

    private function no(string $token = 'tok')
    {
        return $this->postJson('/api/orders/20261016100001/abandon-payment', ['token' => $token]);
    }

    public function test_yes_places_the_attempt_as_cash_on_delivery_and_keeps_what_it_holds(): void
    {
        $order = $this->attempt();
        DB::table('order_wallet_points')->insert(['order_id' => $order->id, 'amount' => 33.33]);

        $this->yes()->assertOk()->assertJson(['status' => 'placed']);
        $this->yes()->assertOk()->assertJson(['status' => 'placed']); // a double tap

        foreach (Order::all() as $row) {
            $this->assertSame('CASH_ON_DELIVERY', $row->payment_gateway);
            $this->assertSame(PaymentStatus::CASH_ON_DELIVERY, $row->payment_status);
            $this->assertSame(OrderStatus::PROCESSING, $row->order_status);
            $this->assertNotNull($row->placed_at);
        }
        $this->assertSame('RAZORPAY', $order->fresh()->altered_payment_gateway);
        Event::assertDispatched(OrderCreated::class, 1);
        Event::assertNotDispatched(OrderProcessed::class); // stock was taken at checkout; never twice
        $this->assertSame(1, DB::table('order_wallet_points')->count(), 'the wallet part still pays: cash due = total − wallet');
        $this->assertSame(1, DB::table('payment_intents')->count(), 'kept, so a late capture is recognised');
    }

    public function test_yes_on_a_payment_that_actually_went_through_places_it_as_paid(): void
    {
        $order = $this->attempt();
        $this->razorpay = 'captured';

        $this->yes()->assertOk()->assertJson(['status' => 'paid']);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::SUCCESS, $fresh->payment_status);
        $this->assertSame('RAZORPAY', $fresh->payment_gateway, 'never COD on money already taken');
        $this->assertNotNull($fresh->placed_at);
        Event::assertDispatched(OrderCreated::class, 1);
    }

    public function test_a_payment_still_settling_is_neither_switched_nor_closed(): void
    {
        $order = $this->attempt();
        $this->razorpay = 'authorized';

        $this->yes()->assertStatus(409)->assertJson(['code' => 'PAYMENT_IN_PROGRESS']);
        $this->no()->assertStatus(409)->assertJson(['code' => 'PAYMENT_IN_PROGRESS']);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PENDING, $fresh->payment_status);
        $this->assertSame(OrderStatus::PENDING, $fresh->order_status);
        $this->assertNull($fresh->placed_at);
    }

    public function test_when_razorpay_cannot_be_asked_nothing_changes(): void
    {
        $order = $this->attempt();
        $this->razorpay = 'error';

        $this->yes()->assertStatus(503)->assertJson(['code' => 'PAYMENT_STATUS_UNKNOWN']);
        $this->no()->assertStatus(503);

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->order_status);
    }

    public function test_yes_is_refused_while_cash_on_delivery_is_switched_off(): void
    {
        $order = $this->attempt();
        $this->settings(['useCashOnDelivery' => false]);

        $this->yes()->assertStatus(422)->assertJson(['code' => 'COD_DISABLED']);

        $this->assertSame('RAZORPAY', $order->fresh()->payment_gateway);
        $this->assertNull($order->fresh()->placed_at);
    }

    public function test_a_wrong_token_looks_like_a_missing_order(): void
    {
        $order = $this->attempt();

        $this->yes('guess')->assertNotFound();
        $this->no('guess')->assertNotFound();

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->order_status);
    }

    public function test_no_closes_the_attempt_and_gives_back_the_coupon_and_wallet_points(): void
    {
        $coupon = Coupon::create(['code' => 'SAVE', 'usage_limit' => 1, 'times_used' => 1]);
        $order = $this->attempt(['coupon_id' => $coupon->id]);
        CouponUsage::create(['coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => 7]);
        // The partial-wallet debit took the whole balance: 100 points, recorded as ₹33.33 at 3 per rupee.
        DB::table('order_wallet_points')->insert(['order_id' => $order->id, 'amount' => 33.33]);
        DB::table('wallets')->insert(['customer_id' => 7, 'total_points' => 100, 'points_used' => 100, 'available_points' => 0]);

        $this->no()->assertOk()->assertJson(['status' => 'abandoned']);
        $this->no()->assertOk()->assertJson(['status' => 'abandoned']); // a double tap

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->order_status);
        $this->assertSame(PaymentStatus::FAILED, $fresh->payment_status);
        $this->assertNull($fresh->placed_at, 'never placed, so never announced');
        $this->assertSame(OrderStatus::CANCELLED, Order::where('parent_id', $order->id)->value('order_status'));
        Event::assertDispatched(OrderCancelled::class, 1); // restock
        $this->assertSame(0, (int) $coupon->fresh()->times_used);
        $wallet = DB::table('wallets')->where('customer_id', 7)->first();
        $this->assertSame(100, (int) $wallet->available_points);
        $this->assertSame(0, (int) $wallet->points_used);

        // Saying Yes after No: the attempt is closed, nothing is switched.
        $this->yes()->assertStatus(409)->assertJson(['code' => 'ATTEMPT_CLOSED']);
    }

    public function test_both_answer_placed_for_an_order_that_is_already_placed(): void
    {
        $this->attempt(['placed_at' => now(), 'payment_status' => PaymentStatus::SUCCESS]);

        $this->yes()->assertOk()->assertJson(['status' => 'placed']);
        $this->no()->assertOk()->assertJson(['status' => 'placed']);
        Event::assertNotDispatched(OrderCancelled::class);
    }
}
