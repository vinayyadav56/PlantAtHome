<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentStatus;
use Marvel\Services\MetricsService;
use Tests\TestCase;

/**
 * What the dashboard counts as revenue.
 *
 * It used to be "delivered orders only". On a store with any delivery lag that hides every
 * paid-but-undelivered order, so the executive cards read Rs 0 while real money had been
 * collected — the reason this was reported as "the cards are not showing figures".
 *
 * The rule now: prepaid counts once payment succeeded, COD counts once delivered (that is when
 * the cash changes hands), and cancelled/failed/refunded never count. The fixture below is the
 * live staging spread that exposed it.
 */
final class RevenueRecognitionTest extends TestCase
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
            $t->bigIncrements('id');
            $t->string('tracking_number')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->decimal('paid_total', 12, 2)->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    private function order(string $orderStatus, string $paymentStatus, float $total): void
    {
        DB::table('orders')->insert([
            'tracking_number' => 'T' . random_int(10000000, 99999999),
            'parent_id'       => null,
            'customer_id'     => 1,
            'order_status'    => $orderStatus,
            'payment_status'  => $paymentStatus,
            'paid_total'      => $total,
            'created_at'      => now()->subDay(),
            'updated_at'      => now()->subDay(),
        ]);
    }

    /** @return float revenue over the last 30 days, as the dashboard computes it */
    private function revenue30d(): float
    {
        $m = new MetricsService();
        $fn = (new \ReflectionClass($m))->getMethod('revenueSince');
        $fn->setAccessible(true);
        return (float) $fn->invoke($m, \Carbon\Carbon::now()->subDays(30));
    }

    public function test_paid_orders_count_before_they_are_delivered(): void
    {
        // The case that made the dashboard read zero: the customer has paid, the parcel is
        // still in transit. That money is ours.
        $this->order(OrderStatus::PROCESSING, PaymentStatus::SUCCESS, 11363.16);
        $this->order(OrderStatus::OUT_FOR_DELIVERY, PaymentStatus::SUCCESS, 1516.58);

        $this->assertSame(12879.74, round($this->revenue30d(), 2));
    }

    public function test_cod_counts_only_once_delivered(): void
    {
        // Cash changes hands on handover — an undelivered COD order is not money yet.
        $this->order(OrderStatus::PROCESSING, PaymentStatus::CASH_ON_DELIVERY, 1516.58);
        $this->assertSame(0.0, round($this->revenue30d(), 2));

        $this->order(OrderStatus::COMPLETED, PaymentStatus::CASH, 1394.28);
        $this->assertSame(1394.28, round($this->revenue30d(), 2));
    }

    public function test_cancelled_and_unpaid_never_count(): void
    {
        $this->order(OrderStatus::CANCELLED, PaymentStatus::FAILED, 4305.13);
        $this->order(OrderStatus::OUT_FOR_DELIVERY, PaymentStatus::PENDING, 9542.75);
        // A refund reverses money that was genuinely collected.
        $this->order(OrderStatus::REFUNDED, PaymentStatus::SUCCESS, 2000.00);
        // ...and a cancelled order does not become revenue just because it was paid for.
        $this->order(OrderStatus::CANCELLED, PaymentStatus::SUCCESS, 3000.00);

        $this->assertSame(0.0, round($this->revenue30d(), 2));
    }

    public function test_the_live_staging_spread_totals_correctly(): void
    {
        // Exactly the 11 orders behind the report, so the number on the card is pinned.
        $this->order(OrderStatus::PROCESSING, PaymentStatus::SUCCESS, 11363.16);       // in
        $this->order(OrderStatus::CANCELLED, PaymentStatus::FAILED, 4305.13);          // out
        $this->order(OrderStatus::COMPLETED, PaymentStatus::CASH, 1394.28);            // in
        $this->order(OrderStatus::OUT_FOR_DELIVERY, PaymentStatus::SUCCESS, 1516.58);  // in
        $this->order(OrderStatus::PROCESSING, PaymentStatus::CASH_ON_DELIVERY, 1516.58); // out
        $this->order(OrderStatus::OUT_FOR_DELIVERY, PaymentStatus::PENDING, 9542.75);  // out

        $this->assertSame(14274.02, round($this->revenue30d(), 2));
    }

    public function test_suborders_are_never_double_counted(): void
    {
        // The parent row carries the full total; children would double it.
        $this->order(OrderStatus::PROCESSING, PaymentStatus::SUCCESS, 1000.00);
        DB::table('orders')->insert([
            'tracking_number' => 'CHILD', 'parent_id' => 1, 'customer_id' => 1,
            'order_status' => OrderStatus::PROCESSING, 'payment_status' => PaymentStatus::SUCCESS,
            'paid_total' => 1000.00, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $this->assertSame(1000.00, round($this->revenue30d(), 2));
    }
}
