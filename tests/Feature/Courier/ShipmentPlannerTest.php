<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\ShipmentItem;
use Marvel\Services\ShipmentPlanner;
use Tests\TestCase;

/**
 * The planning layer: what a proposed parcel weighs, and how it could be broken up when
 * nothing will carry it.
 *
 * The planner is deliberately provider-independent — it is handed a capacity and answers
 * with groups. Nothing here talks to a partner, which is the point: the decision about
 * what travels together is PlantAtHome's.
 */
final class ShipmentPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->integer('order_quantity')->default(1);
            $t->decimal('unit_price', 14, 2)->default(0);
            $t->decimal('subtotal', 14, 2)->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->string('item_status')->default('pending');
            $t->timestamps();
        });
        Schema::create('shipment_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedInteger('quantity')->default(1);
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });
        Schema::create('shipment_packages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedSmallInteger('package_number')->default(1);
            $t->unsignedInteger('weight_g')->nullable();
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->string('last_status')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('sku')->nullable();
            $t->integer('weight')->nullable();
            $t->decimal('length', 8, 2)->nullable();
            $t->decimal('breadth', 8, 2)->nullable();
            $t->decimal('height', 8, 2)->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->timestamps();
        });

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Jade Plant',      'weight' => 12000, 'length' => 40, 'breadth' => 30, 'height' => 50],
            ['id' => 2, 'name' => 'Snake Plant',     'weight' => 8000,  'length' => 30, 'breadth' => 20, 'height' => 60],
            ['id' => 3, 'name' => 'Ceramic Planter', 'weight' => 3000,  'length' => 25, 'breadth' => 25, 'height' => 25],
            // Every row carries the SAME keys: sqlite refuses a multi-row insert whose
            // tuples differ in length ("all VALUES must have the same number of terms").
            ['id' => 4, 'name' => 'Unweighed Pot',   'weight' => null,  'length' => null, 'breadth' => null, 'height' => null],
        ]);
    }

    private function shipmentWith(array $productQty): Shipment
    {
        $order = Order::create(['tracking_number' => 'T' . random_int(10000000, 99999999)]);
        $shipment = Shipment::create([
            'order_id' => $order->id, 'shop_id' => 11,
            'fulfillment_mode' => 'local', 'status' => 'pending',
        ]);
        foreach ($productQty as $productId => $qty) {
            $item = OrderItem::create([
                'order_id' => $order->id, 'product_id' => $productId,
                'order_quantity' => $qty, 'unit_price' => 100,
            ]);
            ShipmentItem::create([
                'shipment_id' => $shipment->id, 'order_item_id' => $item->id, 'quantity' => $qty,
            ]);
        }

        return $shipment->fresh();
    }

    public function test_summarize_totals_units_and_weight_from_product_data(): void
    {
        $shipment = $this->shipmentWith([1 => 1, 2 => 2]);

        $summary = (new ShipmentPlanner())->summarize($shipment);

        $this->assertSame(3, $summary['units']);
        $this->assertSame(12000 + 8000 * 2, $summary['weight_g']);
        // Dimensions take the LARGEST box on each axis, never a sum.
        $this->assertSame(40.0, $summary['length_cm']);
        $this->assertSame(30.0, $summary['breadth_cm']);
        $this->assertSame(60.0, $summary['height_cm']);
        $this->assertFalse($summary['estimated']);
    }

    public function test_summarize_counts_only_the_units_allocated_to_this_parcel(): void
    {
        // A line of 5 with only 2 units on THIS parcel must weigh 2 units, not 5 —
        // the same apportionment the courier payload makes.
        $shipment = $this->shipmentWith([3 => 5]);
        ShipmentItem::where('shipment_id', $shipment->id)->update(['quantity' => 2]);

        $summary = (new ShipmentPlanner())->summarize($shipment->fresh());

        $this->assertSame(2, $summary['units']);
        $this->assertSame(6000, $summary['weight_g']);
    }

    public function test_an_operator_weight_override_wins_over_the_product_sum(): void
    {
        $shipment = $this->shipmentWith([1 => 1]);
        $shipment->forceFill(['weight_g' => 9999])->save();

        $this->assertSame(9999, (new ShipmentPlanner())->summarize($shipment->fresh())['weight_g']);
    }

    public function test_a_product_with_no_weight_falls_back_and_is_flagged_estimated(): void
    {
        $summary = (new ShipmentPlanner())->summarize($this->shipmentWith([4 => 2]));

        $this->assertSame(2, $summary['units']);
        $this->assertSame(1000, $summary['weight_g'], 'two units at the 500 g default');
        $this->assertTrue($summary['estimated'], 'nobody weighed this — the UI must not imply otherwise');
    }

    public function test_propose_split_breaks_an_overweight_parcel_into_groups_that_fit(): void
    {
        // 12 kg + 8 kg against a 15 kg vehicle: they cannot ride together.
        $shipment = $this->shipmentWith([1 => 1, 2 => 1]);

        $proposal = (new ShipmentPlanner())->proposeSplit($shipment, 15.0);

        $this->assertFalse($proposal['fits']);
        $this->assertCount(2, $proposal['groups']);
        $this->assertSame(12000, $proposal['groups'][0]['weight_g'], 'heaviest first');
        $this->assertSame(8000, $proposal['groups'][1]['weight_g']);
        foreach ($proposal['groups'] as $group) {
            $this->assertLessThanOrEqual(15000, $group['weight_g']);
        }
    }

    public function test_propose_split_keeps_a_parcel_that_already_fits_in_one_group(): void
    {
        $shipment = $this->shipmentWith([2 => 1, 3 => 1]); // 8 kg + 3 kg

        $proposal = (new ShipmentPlanner())->proposeSplit($shipment, 15.0);

        $this->assertTrue($proposal['fits'], 'a refusal that is not about weight must not invent a split');
        $this->assertCount(1, $proposal['groups']);
        $this->assertSame(11000, $proposal['groups'][0]['weight_g']);
    }

    public function test_a_single_line_heavier_than_the_vehicle_is_still_shown_alone(): void
    {
        // Splitting cannot help here; isolating it is what makes that legible to the operator.
        $proposal = (new ShipmentPlanner())->proposeSplit($this->shipmentWith([1 => 1]), 5.0);

        $this->assertCount(1, $proposal['groups']);
        $this->assertSame(12000, $proposal['groups'][0]['weight_g']);
        $this->assertGreaterThan(5000, $proposal['groups'][0]['weight_g']);
    }

    public function test_a_single_line_heavier_than_the_vehicle_does_not_report_fits(): void
    {
        // Deriving 'fits' from the group count alone said yes here: the greedy loop always
        // opens a group for the heaviest line, so one over-capacity line still yields one group.
        $proposal = (new ShipmentPlanner())->proposeSplit($this->shipmentWith([1 => 1]), 5.0);

        $this->assertFalse($proposal['fits'], 'a 12 kg line cannot fit a 5 kg vehicle');
        $this->assertCount(1, $proposal['groups'], 'and splitting cannot help, so it stands alone');
    }

    public function test_summarize_prefers_the_operators_measured_box(): void
    {
        // Booking prefers the override (packageDims); a preview that ignored it showed a box
        // the courier would never be quoted on.
        $shipment = $this->shipmentWith([1 => 1]);
        $shipment->forceFill(['length_cm' => 11, 'breadth_cm' => 12, 'height_cm' => 13])->save();

        $summary = (new ShipmentPlanner())->summarize($shipment->fresh());

        $this->assertSame(11.0, $summary['length_cm']);
        $this->assertSame(12.0, $summary['breadth_cm']);
        $this->assertSame(13.0, $summary['height_cm']);
    }

    public function test_a_partial_dimension_override_is_ignored_all_or_nothing(): void
    {
        // Booking requires all three axes before it trusts the override. Falling back per-axis
        // would mix a measured length with a default height — a box that exists nowhere.
        $shipment = $this->shipmentWith([1 => 1]);
        $shipment->forceFill(['length_cm' => 99, 'breadth_cm' => null, 'height_cm' => null])->save();

        $summary = (new ShipmentPlanner())->summarize($shipment->fresh());

        $this->assertSame(40.0, $summary['length_cm'], 'falls through to the product box, not 99');
        $this->assertSame(30.0, $summary['breadth_cm']);
        $this->assertSame(50.0, $summary['height_cm']);
    }

    public function test_a_product_with_no_dimensions_falls_back_all_or_nothing(): void
    {
        $summary = (new ShipmentPlanner())->summarize($this->shipmentWith([4 => 1]));

        // 20x15x15 is the configured default — not a mix of zeros and defaults.
        $this->assertSame(20.0, $summary['length_cm']);
        $this->assertSame(15.0, $summary['breadth_cm']);
        $this->assertSame(15.0, $summary['height_cm']);
    }
}
