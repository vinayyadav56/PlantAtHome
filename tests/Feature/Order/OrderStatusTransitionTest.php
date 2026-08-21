<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Enums\OrderStatus;
use Marvel\Traits\OrderManagementTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The order status state machine: changeOrderStatus admits ONE rung of
 * OrderStatus::ranks() at a time (plus cancel as an interrupt and the existing terminal
 * rules), and the parent rollup reports the least-advanced live leg without ever
 * regressing a parent the courier seam already advanced.
 */
final class OrderStatusTransitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake(); // order status events fan out to notifications; the state machine is the subject

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
            $t->unsignedBigInteger('delivery_partner_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            // The cancel/refund path rewrites the money columns — stub them so it can run.
            foreach (['amount', 'total', 'paid_total', 'sales_tax', 'delivery_fee', 'cancelled_amount', 'cancelled_tax', 'cancelled_delivery_fee'] as $money) {
                $t->decimal($money, 12, 2)->nullable()->default(0);
            }
            $t->boolean('is_pinned')->default(false);
            $t->timestamp('pinned_at')->nullable();
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

        // Empty stubs for the Order model's default eager loads.
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

    private function makeOrder(string $status, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'tracking_number' => 'T' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'order_status'    => $status,
            'payment_status'  => 'payment-pending',
            'payment_gateway' => 'RAZORPAY',
        ], $attrs));
    }

    private function assertRejects(string $from, string $to): void
    {
        $order = $this->makeOrder($from);
        try {
            (new StateMachine())->changeOrderStatus($order, $to);
            $this->fail("{$from} → {$to} must be rejected");
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($from, $e->getMessage());
            $this->assertStringContainsString($to, $e->getMessage());
        }
        $this->assertSame($from, $order->fresh()->order_status, 'a rejected transition must not persist');
    }

    public function test_one_step_advance_is_allowed(): void
    {
        $order = $this->makeOrder(OrderStatus::PROCESSING);

        (new StateMachine())->changeOrderStatus($order, OrderStatus::AT_LOCAL_FACILITY);

        $this->assertSame(OrderStatus::AT_LOCAL_FACILITY, $order->fresh()->order_status);
    }

    public function test_skipping_a_rung_is_rejected(): void
    {
        $this->assertRejects(OrderStatus::PROCESSING, OrderStatus::COMPLETED);
        $this->assertRejects(OrderStatus::PENDING, OrderStatus::OUT_FOR_DELIVERY);
    }

    public function test_regression_is_rejected(): void
    {
        $this->assertRejects(OrderStatus::OUT_FOR_DELIVERY, OrderStatus::PROCESSING);
        $this->assertRejects(OrderStatus::COMPLETED, OrderStatus::OUT_FOR_DELIVERY);
    }

    public function test_unranked_statuses_do_not_bypass_the_ladder(): void
    {
        // refunded/failed are written by the refund + payment flows directly; they are
        // never reachable by advancing, and nothing advances out of them.
        $this->assertRejects(OrderStatus::PROCESSING, OrderStatus::FAILED);
        $this->assertRejects(OrderStatus::FAILED, OrderStatus::PROCESSING);
    }

    public function test_terminal_rules_are_unchanged(): void
    {
        $this->assertRejects(OrderStatus::CANCELLED, OrderStatus::PROCESSING);
        $this->assertRejects(OrderStatus::REFUNDED, OrderStatus::PROCESSING);

        // completed → refunded stays the one permitted exit from completed.
        $completed = $this->makeOrder(OrderStatus::COMPLETED);
        (new StateMachine())->changeOrderStatus($completed, OrderStatus::REFUNDED);
        $this->assertSame(OrderStatus::REFUNDED, $completed->fresh()->order_status);

        // cancelling interrupts the ladder from any live rung.
        $live = $this->makeOrder(OrderStatus::AT_LOCAL_FACILITY);
        (new StateMachine())->changeOrderStatus($live, OrderStatus::CANCELLED);
        $this->assertSame(OrderStatus::CANCELLED, $live->fresh()->order_status);
    }

    public function test_authoritative_callers_may_skip_rungs(): void
    {
        // A courier partner reports the truth, not every rung: Borzo jumps straight to
        // out_for_delivery and a first webhook can land on delivered. Refusing that would
        // drop a real delivery on the floor.
        $order = $this->makeOrder(OrderStatus::PROCESSING);

        (new StateMachine())->changeOrderStatus($order, OrderStatus::COMPLETED, true);

        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->order_status);
    }

    public function test_a_partner_skip_notifies_the_customer_once(): void
    {
        // Walking the skipped rungs instead would fire one notification per fabricated
        // state — three texts for one delivery.
        $order = $this->makeOrder(OrderStatus::PROCESSING);

        (new StateMachine())->changeOrderStatus($order, OrderStatus::COMPLETED, true);

        Event::assertDispatchedTimes(\Marvel\Events\OrderStatusChanged::class, 1);
    }

    public function test_authoritative_callers_still_cannot_regress_or_resurrect(): void
    {
        // Forward-only and the terminal floor both still apply to partners.
        $live = $this->makeOrder(OrderStatus::OUT_FOR_DELIVERY);
        try {
            (new StateMachine())->changeOrderStatus($live, OrderStatus::PROCESSING, true);
            $this->fail('an authoritative caller must not regress the ladder');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, $live->fresh()->order_status);

        $cancelled = $this->makeOrder(OrderStatus::CANCELLED);
        try {
            (new StateMachine())->changeOrderStatus($cancelled, OrderStatus::COMPLETED, true);
            $this->fail('a late callback must not resurrect a cancelled order');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(OrderStatus::CANCELLED, $cancelled->fresh()->order_status);
    }

    public function test_rollup_reports_all_out_for_delivery_children(): void
    {
        $parent = $this->makeOrder(OrderStatus::PROCESSING);
        $this->makeOrder(OrderStatus::OUT_FOR_DELIVERY, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::OUT_FOR_DELIVERY, ['parent_id' => $parent->id]);

        (new StateMachine())->rollup($parent->id);

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, $parent->fresh()->order_status);
    }

    public function test_rollup_takes_the_least_advanced_live_leg(): void
    {
        $parent = $this->makeOrder(OrderStatus::PROCESSING);
        $this->makeOrder(OrderStatus::COMPLETED, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::AT_LOCAL_FACILITY, ['parent_id' => $parent->id]);
        // An abandoned leg is out of the race — it must not pin the order to pending.
        $this->makeOrder(OrderStatus::CANCELLED, ['parent_id' => $parent->id]);

        (new StateMachine())->rollup($parent->id);

        $this->assertSame(OrderStatus::AT_LOCAL_FACILITY, $parent->fresh()->order_status);
    }

    public function test_rollup_never_regresses_a_courier_advanced_parent(): void
    {
        $parent = $this->makeOrder(OrderStatus::OUT_FOR_DELIVERY);
        $this->makeOrder(OrderStatus::PROCESSING, ['parent_id' => $parent->id]);

        (new StateMachine())->rollup($parent->id);

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, $parent->fresh()->order_status);
    }

    public function test_rollup_resolves_all_terminal_children_to_the_dominant_state(): void
    {
        $parent = $this->makeOrder(OrderStatus::OUT_FOR_DELIVERY);
        $this->makeOrder(OrderStatus::COMPLETED, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::COMPLETED, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::CANCELLED, ['parent_id' => $parent->id]);

        (new StateMachine())->rollup($parent->id);

        $this->assertSame(OrderStatus::COMPLETED, $parent->fresh()->order_status);
    }

    public function test_rollup_never_cancels_a_settled_parent(): void
    {
        // cancelled carries no rank, so the numeric floor cannot see it. Without an explicit
        // guard a majority of cancelled siblings launders a settled order into "cancelled" —
        // the one transition changeOrderStatus forbids — and saveQuietly bypasses that seam.
        $parent = $this->makeOrder(OrderStatus::COMPLETED);
        $this->makeOrder(OrderStatus::COMPLETED, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::CANCELLED, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::CANCELLED, ['parent_id' => $parent->id]);

        (new StateMachine())->rollup($parent->id);

        $this->assertSame(OrderStatus::COMPLETED, $parent->fresh()->order_status);
    }

    public function test_rollup_still_lets_a_completed_parent_reach_refunded(): void
    {
        // The completed guard must not seal the one legal exit.
        $parent = $this->makeOrder(OrderStatus::COMPLETED);
        $this->makeOrder(OrderStatus::REFUNDED, ['parent_id' => $parent->id]);
        $this->makeOrder(OrderStatus::REFUNDED, ['parent_id' => $parent->id]);

        (new StateMachine())->rollup($parent->id);

        $this->assertSame(OrderStatus::REFUNDED, $parent->fresh()->order_status);
    }
}

/** The trait under test, without OrderRepository's DI/permission baggage. */
class StateMachine
{
    use OrderManagementTrait;

    public function rollup($parentId): void
    {
        $this->recomputeParentOrderStatus($parentId);
    }
}
