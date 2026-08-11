<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * courier:margin-report — flags parent orders whose Σ shipment booked_cost exceeds the
 * delivery fee charged. Report-only (logs); child orders and non-booked shipments are ignored.
 */
final class MarginReportTest extends TestCase
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
            $t->string('tracking_number');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->double('delivery_fee')->nullable();
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->decimal('booked_cost', 14, 2)->nullable();
            $t->timestamps();
        });
    }

    private function order(string $tn, ?float $fee, ?int $parent = null): int
    {
        return (int) DB::table('orders')->insertGetId([
            'tracking_number' => $tn, 'delivery_fee' => $fee, 'parent_id' => $parent,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shipment(int $orderId, ?float $cost): void
    {
        DB::table('shipments')->insert([
            'order_id' => $orderId, 'booked_cost' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_flags_only_orders_where_cost_exceeds_charged(): void
    {
        // A: charged 30, cost 20+25=45 → leak 15.
        $a = $this->order('LEAK-A', 30.0);
        $this->shipment($a, 20.0);
        $this->shipment($a, 25.0);
        // B: charged 100, cost 40 → healthy.
        $b = $this->order('OK-B', 100.0);
        $this->shipment($b, 40.0);
        // Child order (parent_id set) → ignored even if leaking.
        $c = $this->order('CHILD-C', 0.0, $a);
        $this->shipment($c, 50.0);

        Log::spy();
        $this->artisan('courier:margin-report')->assertSuccessful();

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($msg, $ctx) {
            return $msg === 'courier.margin.leak'
                && $ctx['orders_with_leak'] === 1
                && (float) $ctx['total_leak'] === 15.0
                && $ctx['worst'][0]['tracking_number'] === 'LEAK-A';
        });
    }

    public function test_no_leak_logs_nothing(): void
    {
        $b = $this->order('OK-ONLY', 100.0);
        $this->shipment($b, 40.0);

        Log::spy();
        $this->artisan('courier:margin-report')->assertSuccessful();
        Log::shouldNotHaveReceived('warning');
    }
}
