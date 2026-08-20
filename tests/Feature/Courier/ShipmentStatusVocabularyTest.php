<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;
use Tests\TestCase;

/**
 * ONE vocabulary, handled at every layer.
 *
 * A status crosses four translation tables on its way to the screen — the Go adapters normalize
 * the partner's word, CourierService::mapServiceStatus turns that into a shipment status,
 * applyNormalizedStatus ranks it, and the admin renders it. Every table was written separately
 * and none of them covered the full set, so a word missing from one silently became "nothing
 * happened": `mapServiceStatus` had no case for `pending` or `reopened`, so a Porter reopen (the
 * assigned rider cancelled and it is searching again) was recorded in the partner ledger and
 * never on the shipment. The drawer went on showing a rider who had already dropped the job.
 *
 * VOCABULARY below is ground truth, taken from the Go adapters' status mappers:
 *   porter.go     → pending assigned reopened out_for_delivery delivered cancelled
 *   borzo.go      → assigned out_for_delivery delivered cancelled
 *   shiprocket.go → pending assigned shipped out_for_delivery ndr delivered cancelled
 *                   rto rto_initiated rto_in_transit rto_delivered
 *
 * If a partner adapter learns a new word, add it here FIRST — this test is what makes the gap
 * fail loudly instead of rendering as stage zero.
 */
final class ShipmentStatusVocabularyTest extends TestCase
{
    /** Every normalized status the shipping service can emit. */
    private const VOCABULARY = [
        'pending',
        'assigned',
        'reopened',
        'shipped',
        'out_for_delivery',
        'ndr',
        'delivered',
        'cancelled',
        'rto',
        'rto_initiated',
        'rto_in_transit',
        'rto_delivered',
    ];

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

        // CourierService reads the courier options out of settings in its constructor.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert([
            ['options' => json_encode([]), 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Present but EMPTY on purpose: OrderEvent::record needs a non-null order_id, while
        // resolving $shipment->order to null keeps the customer-order advance (a separate seam,
        // covered by ShipmentReconcileTest) out of these assertions.
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('order_status')->default('order-processing');
            $t->timestamps();
            $t->softDeletes();
        });

        // Allocation ledger: WHICH UNITS of an order line ride on WHICH shipment. Shipment::items()
        // is a belongsToMany through this table, so every stub that has `shipments` needs it too.
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
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->decimal('declared_value', 14, 2)->nullable();
            $t->string('contents', 255)->nullable();
            $t->boolean('fragile')->default(false);
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('status')->default('pending');
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->timestamp('rto_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
        });
    }

    /**
     * The order row is deliberately absent. This file is about the STATUS vocabulary — whether a word survives
     * the trip from the Go adapter to the shipment row. Advancing the customer order off the back
     * of that is a separate seam with its own coverage (ShipmentReconcileTest), and wiring the
     * whole Order model in here would only test Eloquent's eager loading.
     */
    private function shipment(string $status = 'pending'): Shipment
    {
        return Shipment::create(['order_id' => 1, 'status' => $status, 'provider_order_id' => 'PO1']);
    }

    /**
     * The gap that started this: two of the twelve fell through to `default` and were discarded.
     */
    public function test_every_emitted_status_is_mapped(): void
    {
        $svc = new CourierService();
        foreach (self::VOCABULARY as $status) {
            $mapped = $svc->mapServiceStatus($status);
            $this->assertNotNull(
                $mapped['shipment_status'],
                "`{$status}` reaches mapServiceStatus's default branch and is silently discarded",
            );
        }
    }

    /** A word nobody has taught the system must stay a no-op — audible, but never guessed at. */
    public function test_an_unknown_status_is_still_a_no_op(): void
    {
        $mapped = (new CourierService())->mapServiceStatus('teleported');
        $this->assertNull($mapped['shipment_status']);
        $this->assertNull($mapped['order_status']);
    }

    /**
     * Mapping a status is only half the job — applyNormalizedStatus has its own rank table, and a
     * status missing from THAT is accepted and then ranked 0, which reads as "back to the start".
     */
    public function test_every_mapped_status_is_applied_to_the_shipment(): void
    {
        $svc = new CourierService();
        foreach (self::VOCABULARY as $status) {
            $mapped = $svc->mapServiceStatus($status);
            // From `pending`, every forward status must land. `pending` itself is the state the
            // shipment already starts in, so it is legitimately a no-op.
            $shipment = $this->shipment();
            $svc->applyNormalizedStatus($shipment, $mapped);
            $fresh = $shipment->fresh();

            if ($status === 'pending') {
                $this->assertSame('pending', $fresh->status);
                continue;
            }
            $this->assertSame(
                $status,
                (string) $fresh->last_status,
                "`{$status}` was mapped but not applied — it is missing from applyNormalizedStatus's rank table",
            );
        }
    }

    /**
     * The reopen semantics Porter actually has: a rider accepted, dropped, and a second rider
     * accepted. Every one of those three transitions has to land, or the drawer shows a rider
     * who is no longer coming.
     */
    public function test_a_porter_reopen_walks_backwards_once_and_then_forward_again(): void
    {
        $svc = new CourierService();
        $shipment = $this->shipment();

        foreach (['assigned', 'reopened', 'assigned', 'out_for_delivery', 'delivered'] as $step) {
            $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus($step));
            $shipment = $shipment->fresh();
            $this->assertSame($step, (string) $shipment->last_status, "the shipment refused `{$step}`");
        }
    }

    /** The reopen must not drag the CUSTOMER order backwards — only the shipment reopens. */
    public function test_a_reopen_does_not_downgrade_the_order(): void
    {
        $this->assertNull((new CourierService())->mapServiceStatus('reopened')['order_status']);
    }

    /**
     * The interrupt states are the ones a shipment can enter mid-journey and must be able to
     * LEAVE at the same rank. Getting this wrong freezes a shipment there forever, which is
     * exactly what `ndr` used to do before it was special-cased — and `reopened` now shares the
     * same generalised escape.
     */
    public function test_a_shipment_can_leave_an_interrupt_state(): void
    {
        $svc = new CourierService();

        $ndr = $this->shipment();
        foreach (['out_for_delivery', 'ndr', 'out_for_delivery', 'delivered'] as $step) {
            $svc->applyNormalizedStatus($ndr, $svc->mapServiceStatus($step));
            $ndr = $ndr->fresh();
        }
        $this->assertSame('delivered', (string) $ndr->last_status, 'a reattempted NDR never completed');
    }

    /** Terminal is still sticky — the vocabulary work must not have unlocked a finished shipment. */
    public function test_terminal_statuses_remain_sticky(): void
    {
        $svc = new CourierService();

        $delivered = $this->shipment('delivered');
        $svc->applyNormalizedStatus($delivered, $svc->mapServiceStatus('reopened'));
        $this->assertSame('delivered', (string) $delivered->fresh()->status, 'a reopen resurrected a delivered shipment');

        $cancelled = $this->shipment('cancelled');
        $svc->applyNormalizedStatus($cancelled, $svc->mapServiceStatus('assigned'));
        $this->assertSame('cancelled', (string) $cancelled->fresh()->status);
    }
}
