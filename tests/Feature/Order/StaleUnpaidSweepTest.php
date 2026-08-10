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
use Marvel\Events\OrderCancelled;
use Tests\TestCase;

/**
 * orders:cancel-stale-unpaid — prepaid orders left unpaid past the threshold
 * are cancelled through the standard machinery (OrderCancelled ⇒ idempotent
 * restock listener) and their coupon slot is released. COD/wallet, paid,
 * young, and child orders are never touched.
 *
 * OrderCancelled is faked: the restock listener's idempotency is guaranteed by
 * its own orders.inventory_restored guard (existing behavior); this test pins
 * the sweep's SELECTION and state transitions.
 */
final class StaleUnpaidSweepTest extends TestCase
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
            $t->unsignedBigInteger('coupon_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->boolean('is_pinned')->default(false);
            $t->boolean('inventory_restored')->default(false);
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
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
            $t->unique(['coupon_id', 'order_id']);
        });
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'tracking_number' => 'T' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'order_status'    => 'order-pending',
            'payment_status'  => 'payment-pending',
            'payment_gateway' => 'RAZORPAY',
            'created_at'      => now()->subHours(30),
            'updated_at'      => now()->subHours(30),
        ], $attrs));
    }

    public function test_stale_prepaid_order_is_cancelled_with_children_and_coupon_released(): void
    {
        Event::fake([OrderCancelled::class]);

        $coupon = Coupon::create(['code' => 'SAVE', 'usage_limit' => 1, 'times_used' => 1]);
        $parent = $this->makeOrder(['coupon_id' => $coupon->id, 'customer_id' => 7]);
        CouponUsage::create(['coupon_id' => $coupon->id, 'order_id' => $parent->id, 'user_id' => 7]);
        $child = $this->makeOrder(['parent_id' => $parent->id]);

        $this->artisan('orders:cancel-stale-unpaid')->assertSuccessful();

        $this->assertSame('order-cancelled', $parent->fresh()->order_status);
        $this->assertSame('payment-failed', $parent->fresh()->payment_status);
        $this->assertSame('order-cancelled', $child->fresh()->order_status);
        Event::assertDispatched(OrderCancelled::class, 1);
        $this->assertSame(0, (int) $coupon->fresh()->times_used, 'the coupon slot must return');
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_cod_paid_young_and_child_orders_are_untouched(): void
    {
        Event::fake([OrderCancelled::class]);

        $cod = $this->makeOrder(['payment_gateway' => 'CASH_ON_DELIVERY']);
        $paid = $this->makeOrder(['payment_status' => 'payment-success']);
        $young = $this->makeOrder(['created_at' => now()->subHours(2)]);
        $shipped = $this->makeOrder(['order_status' => 'order-out-for-delivery']);

        $this->artisan('orders:cancel-stale-unpaid')->assertSuccessful();

        $this->assertSame('order-pending', $cod->fresh()->order_status);
        $this->assertSame('order-pending', $paid->fresh()->order_status);
        $this->assertSame('order-pending', $young->fresh()->order_status);
        $this->assertSame('order-out-for-delivery', $shipped->fresh()->order_status);
        Event::assertNotDispatched(OrderCancelled::class);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Event::fake([OrderCancelled::class]);
        $stale = $this->makeOrder();

        $this->artisan('orders:cancel-stale-unpaid --dry-run')->assertSuccessful();

        $this->assertSame('order-pending', $stale->fresh()->order_status);
        Event::assertNotDispatched(OrderCancelled::class);
    }

    public function test_sweep_is_idempotent_across_runs(): void
    {
        Event::fake([OrderCancelled::class]);
        $this->makeOrder();

        $this->artisan('orders:cancel-stale-unpaid')->assertSuccessful();
        $this->artisan('orders:cancel-stale-unpaid')->assertSuccessful();

        // Second run finds nothing (status now cancelled) — event fired once.
        Event::assertDispatched(OrderCancelled::class, 1);
    }
}
