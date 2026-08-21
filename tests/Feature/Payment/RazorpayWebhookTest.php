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
use Marvel\Events\OrderStatusChanged;
use Marvel\Events\PaymentFailed;
use Marvel\Events\PaymentSuccess;
use Marvel\Payments\Razorpay;
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
}
