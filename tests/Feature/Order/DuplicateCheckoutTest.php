<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Repositories\OrderRepository;
use Tests\TestCase;

/**
 * Checkout idempotency — the acceptance criterion: a duplicate POST /orders
 * carrying the same Idempotency-Key must never create a second order.
 *
 * Same approach as CouponRedemptionTest: PHPUnit cannot run true parallel
 * requests, so this proves the enforcement PRIMITIVES the controller relies on:
 *   1. the unique index on orders.idempotency_key rejects the losing insert
 *      with the exact error shape OrderController::store detects (SQLSTATE
 *      23000 + 'idempotency_key' in the message), and
 *   2. findByIdempotencyKey returns the winner, scoped so one customer's key
 *      can never surface another customer's order.
 * On MySQL the unique index serialises racing transactions at commit, so the
 * losing request takes the controller's catch-path and returns the original.
 */
final class DuplicateCheckoutTest extends TestCase
{
    private OrderRepository $repo;

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
            $t->string('tracking_token')->nullable();
            $t->string('idempotency_key', 80)->nullable()->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('language')->default('en');
            $t->float('amount')->nullable();
            $t->float('total')->nullable();
            $t->float('paid_total')->nullable();
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

        $this->repo = app(OrderRepository::class);
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'tracking_number' => 'T' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'order_status'    => 'order-pending',
            'payment_status'  => 'payment-pending',
        ], $attrs));
    }

    /** The losing insert of an idempotency race fails with the shape the controller detects. */
    public function test_duplicate_key_insert_is_rejected_with_detectable_error(): void
    {
        $this->makeOrder(['idempotency_key' => 'attempt-1', 'customer_id' => 7]);

        try {
            $this->makeOrder(['idempotency_key' => 'attempt-1', 'customer_id' => 7]);
            $this->fail('Second insert with the same idempotency key must be rejected');
        } catch (QueryException $e) {
            // Exactly what OrderController::store's race catch matches on.
            $this->assertSame('23000', (string) $e->getCode());
            $this->assertStringContainsString('idempotency_key', $e->getMessage());
        }

        $this->assertSame(1, Order::where('idempotency_key', 'attempt-1')->count());
    }

    public function test_lookup_returns_the_original_order_for_the_same_customer(): void
    {
        $original = $this->makeOrder(['idempotency_key' => 'key-a', 'customer_id' => 7]);

        $found = $this->repo->findByIdempotencyKey('key-a', 7);

        $this->assertNotNull($found);
        $this->assertSame($original->id, $found->id);
    }

    public function test_lookup_never_crosses_customers(): void
    {
        $this->makeOrder(['idempotency_key' => 'key-b', 'customer_id' => 7]);

        $this->assertNull($this->repo->findByIdempotencyKey('key-b', 8), 'another customer must not see the order');
        $this->assertNull($this->repo->findByIdempotencyKey('key-b', null), 'a guest must not see a customer order');
    }

    public function test_guest_keys_scope_to_guest_orders_only(): void
    {
        $guestOrder = $this->makeOrder(['idempotency_key' => 'key-g', 'customer_id' => null]);

        $found = $this->repo->findByIdempotencyKey('key-g', null);
        $this->assertSame($guestOrder->id, $found?->id);
        $this->assertNull($this->repo->findByIdempotencyKey('key-g', 7), 'an authed user must not see the guest order');
    }

    public function test_lookup_ignores_child_orders(): void
    {
        $parent = $this->makeOrder(['customer_id' => 7]);
        // A child never carries the key in practice (createChildOrder builds its
        // own input array) — but the lookup must be parent-only regardless.
        $this->makeOrder(['idempotency_key' => 'key-c', 'customer_id' => 7, 'parent_id' => $parent->id]);

        $this->assertNull($this->repo->findByIdempotencyKey('key-c', 7));
    }

    public function test_orders_without_keys_never_collide(): void
    {
        $this->makeOrder(['customer_id' => 7]);
        $this->makeOrder(['customer_id' => 7]);

        $this->assertSame(2, Order::whereNull('idempotency_key')->count());
    }
}
