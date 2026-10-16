<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\OrderController;
use Tests\TestCase;

/**
 * Who may see which orders, and which LINES of them.
 *
 * Two defects, both found from one annotation ("all orders are showing in vendors dashboard"):
 *
 *  1. fetchOrders() short-circuited on SUPER_ADMIN and returned every parent order on the platform
 *     BEFORE shop_id was read — so the owner opening /[shop]/orders saw the whole platform, not
 *     that vendor. Store owners were always scoped correctly; this was an admin-path hole.
 *
 *  2. Child orders are grouped by VERTICAL, not by vendor, so one child can carry several
 *     suppliers' lines. A vendor legitimately sees such an order, but used to receive it WHOLE —
 *     reading another vendor's products, quantities and prices.
 *
 * Hand-built sqlite tables, the idiom this suite already uses.
 */
final class VendorOrderScopingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('orders', function (Blueprint $t) {
            $t->timestamp('placed_at')->nullable()->useCurrent(); // a placed order unless a test says otherwise
            $t->bigIncrements('id');
            $t->string('tracking_number')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('order_status')->default('order-processing');
            $t->string('payment_status')->default('payment-success');
            $t->string('payment_gateway')->default('CASH_ON_DELIVERY');
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('assigned_shop_id')->nullable();
            $t->timestamps();
        });
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            // The pivot columns the Order model's products relation reads (index() eager-loads it).
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->integer('order_quantity')->nullable();
            $t->string('unit_price')->nullable();
            $t->string('subtotal')->nullable();
            $t->timestamps();
        });
        // index() also eager-loads customers and products, and has_rto filters on shipments.
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        // OrderController's constructor reads Settings::first().
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => '{}', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);

        // Parent order 1 with two lines: product 100 -> vendor 33, product 200 -> vendor 44.
        // Child order 2 holds BOTH (same vertical) — the co-mingling that leaked.
        DB::table('orders')->insert([
            ['id' => 1, 'tracking_number' => 'T1', 'parent_id' => null, 'shop_id' => 12, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'tracking_number' => 'T2', 'parent_id' => 1, 'shop_id' => 12, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'tracking_number' => 'T3', 'parent_id' => null, 'shop_id' => 12, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('order_items')->insert([
            ['order_id' => 1, 'product_id' => 100, 'assigned_shop_id' => 33, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 1, 'product_id' => 200, 'assigned_shop_id' => 44, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('order_product')->insert([
            ['order_id' => 2, 'product_id' => 100],
            ['order_id' => 2, 'product_id' => 200],
        ]);
    }

    /** A super admin, with the shop_id the vendor page always sends. */
    private function adminRequest(array $params = []): Request
    {
        $request = new Request($params);
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public $shops;
            public function __construct() { $this->shops = collect(); }
            public function hasPermissionTo($permission): bool
            {
                return (string) $permission === 'super_admin';
            }
        });
        return $request;
    }

    public function test_super_admin_on_a_vendor_page_sees_only_that_vendor(): void
    {
        $ids = app(OrderController::class)->fetchOrders($this->adminRequest(['shop_id' => 33]))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertSame([2], $ids, 'a vendor-scoped URL must not return the whole platform');
    }

    public function test_super_admin_with_no_shop_still_sees_the_platform(): void
    {
        // /orders — the admin's own screen. Parent orders, unscoped, as before.
        $ids = app(OrderController::class)->fetchOrders($this->adminRequest())
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertContains(1, $ids);
        $this->assertContains(3, $ids, 'the platform-wide admin list must be unchanged');
        $this->assertNotContains(2, $ids, 'child orders belong to the vendor view, not the admin list');
    }

    public function test_a_vendor_with_no_lines_in_the_order_sees_nothing(): void
    {
        $ids = app(OrderController::class)->fetchOrders($this->adminRequest(['shop_id' => 99]))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertSame([], $ids, 'shop 99 supplies nothing in this order');
    }

    /** The ids index() lists — what the admin, vendor and customer screens actually show. */
    private function listed(Request $request): array
    {
        return app(OrderController::class)->index($request)->getCollection()
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function customerRequest(int $customerId, array $params = []): Request
    {
        $request = new Request($params);
        $request->setUserResolver(fn () => new class ($customerId) {
            public function __construct(public int $id)
            {
            }

            public function hasPermissionTo($permission): bool
            {
                return false;
            }
        });
        return $request;
    }

    /** An online checkout nobody has paid for yet: parent 4 (customer 9) and its vendor-33 child 5. */
    private function unpaidAttempt(): void
    {
        $row = ['shop_id' => 12, 'customer_id' => 9, 'placed_at' => null, 'payment_gateway' => 'RAZORPAY',
                'payment_status' => 'payment-pending', 'order_status' => 'order-pending', 'created_at' => now(), 'updated_at' => now()];
        DB::table('orders')->insert([
            ['id' => 4, 'tracking_number' => 'T4', 'parent_id' => null] + $row,
            ['id' => 5, 'tracking_number' => 'T5', 'parent_id' => 4] + $row,
        ]);
        DB::table('order_items')->insert(['order_id' => 4, 'product_id' => 100, 'assigned_shop_id' => 33, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_product')->insert(['order_id' => 5, 'product_id' => 100]);
    }

    public function test_an_unpaid_online_checkout_is_in_no_list(): void
    {
        // Owner rule (2026-10-16): it is only a payment attempt until it is paid or switched to COD.
        $this->unpaidAttempt();
        DB::table('orders')->where('id', 3)->update(['customer_id' => 9]);

        $this->assertEqualsCanonicalizing([1, 3], $this->listed($this->adminRequest()), 'admin: placed orders only');
        $this->assertSame([2], $this->listed($this->adminRequest(['shop_id' => 33])), 'vendor 33: not the unpaid child');
        $this->assertSame([3], $this->listed($this->customerRequest(9)), 'the customer: only what they actually ordered');
    }

    public function test_the_admin_can_still_see_the_attempts_awaiting_payment(): void
    {
        $this->unpaidAttempt();

        $this->assertSame([4], $this->listed($this->adminRequest(['awaiting_payment' => 1])));
        // Only a super admin gets that view; a customer asking for it still sees placed orders only.
        $this->assertSame([], $this->listed($this->customerRequest(9, ['awaiting_payment' => 1])));
    }

    public function test_the_rto_filter_works(): void
    {
        // fetchOrders() returns a query builder, which has no scopeQuery — the filter used to throw.
        DB::table('shipments')->insert(['order_id' => 3, 'status' => 'rto', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([3], $this->listed($this->adminRequest(['has_rto' => 1])));
    }
}
