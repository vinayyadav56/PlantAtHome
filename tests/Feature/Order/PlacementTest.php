<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

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
use Marvel\Listeners\SendOrderCancelledNotification;
use Marvel\Listeners\SendOrderStatusChangedNotification;
use Marvel\Listeners\SendPaymentFailedNotification;
use Marvel\Services\EmailService;
use Marvel\Traits\PaymentStatusManagerWithOrderTrait;
use Tests\TestCase;

/**
 * Owner rule (2026-10-16): an online-payment checkout is only a payment attempt until it is paid
 * (or switched to Cash on Delivery). It is PLACED — announced to the customer, owner and vendors —
 * exactly once, by whichever of confirm / webhook / reconcile / COD switch gets there first.
 */
final class PlacementTest extends TestCase
{
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
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->string('language')->default('en');
            $t->boolean('is_pinned')->default(false);
            $t->timestamp('placed_at')->nullable()->useCurrent();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        // The Order model's default eager loads, and the settings the invoice email data reads.
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
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => json_encode(['currency' => 'INR']), 'language' => 'en']);
    }

    /** An online checkout nobody has paid for yet, with one child per vertical. */
    private function attempt(array $attrs = [], int $children = 2): Order
    {
        $parent = Order::create($attrs + [
            'tracking_number' => 'T' . random_int(10000000, 99999999),
            'order_status'    => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::PENDING,
            'payment_gateway' => 'RAZORPAY',
            'placed_at'       => null,
        ]);
        for ($i = 0; $i < $children; $i++) {
            Order::create([
                'tracking_number' => $parent->tracking_number . '-' . $i,
                'parent_id'       => $parent->id,
                'order_status'    => $parent->order_status,
                'payment_status'  => $parent->payment_status,
                'payment_gateway' => $parent->payment_gateway,
                'placed_at'       => null,
            ]);
        }
        return $parent->fresh();
    }

    private function payments(): object
    {
        return new class {
            use PaymentStatusManagerWithOrderTrait;

            public function pay(Order $order): void
            {
                $this->paymentSuccess($order);
            }
        };
    }

    public function test_an_order_is_announced_once_however_many_times_it_is_placed(): void
    {
        Event::fake([OrderCreated::class, OrderReceived::class]);
        $order = $this->attempt();

        $this->assertTrue($this->payments()->placeOrder($order));
        $this->assertFalse($this->payments()->placeOrder($order->fresh()), 'confirm, webhook and reconcile all get here');

        Event::assertDispatched(OrderCreated::class, 1);
        Event::assertDispatched(OrderReceived::class, 2);
        $this->assertNotNull($order->fresh()->placed_at);
        $this->assertSame(0, Order::where('parent_id', $order->id)->whereNull('placed_at')->count());
    }

    public function test_a_paid_attempt_is_placed_once_and_a_second_success_changes_nothing(): void
    {
        Event::fake([OrderCreated::class, OrderReceived::class, PaymentSuccess::class, OrderStatusChanged::class]);
        $order = $this->attempt();

        $this->payments()->pay($order);
        $this->payments()->pay($order->fresh()); // the thank-you page, reconcile or a webhook replay

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::SUCCESS, $fresh->payment_status);
        $this->assertNotNull($fresh->placed_at);
        Event::assertDispatched(OrderCreated::class, 1);
        Event::assertDispatched(PaymentSuccess::class, 1);
    }

    public function test_a_closed_or_cod_attempt_is_never_turned_paid_by_a_late_success(): void
    {
        Event::fake([OrderCreated::class, OrderReceived::class, PaymentSuccess::class, OrderStatusChanged::class]);
        $abandoned = $this->attempt(['order_status' => OrderStatus::CANCELLED, 'payment_status' => PaymentStatus::FAILED]);
        $cod = $this->attempt(['payment_gateway' => 'CASH_ON_DELIVERY', 'payment_status' => PaymentStatus::CASH_ON_DELIVERY]);

        $this->payments()->pay($abandoned);
        $this->payments()->pay($cod);

        $this->assertSame(PaymentStatus::FAILED, $abandoned->fresh()->payment_status);
        $this->assertSame(PaymentStatus::CASH_ON_DELIVERY, $cod->fresh()->payment_status);
        Event::assertNotDispatched(PaymentSuccess::class);
        Event::assertNotDispatched(OrderCreated::class);
    }

    public function test_an_unplaced_attempt_sends_no_lifecycle_messages(): void
    {
        // Nobody was told it exists: no "cancelled", "status changed" or vendor "payment failed".
        $this->mock(EmailService::class, fn ($m) => $m->shouldNotReceive('send'));
        $order = $this->attempt(['order_status' => OrderStatus::CANCELLED, 'payment_status' => PaymentStatus::FAILED]);

        app(SendOrderCancelledNotification::class)->handle(new OrderCancelled($order));
        app(SendOrderStatusChangedNotification::class)->handle(new OrderStatusChanged($order));
        app(SendPaymentFailedNotification::class)->handle(new PaymentFailed($order));

        $this->assertNull($order->fresh()->placed_at);
    }
}
