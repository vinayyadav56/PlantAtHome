<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;
use RuntimeException;
use Tests\TestCase;

/**
 * The two halves of "a status must never be silently lost":
 *
 *  1. POST /api/shipping/callback — the Go outbox relay marks any 2xx as PUBLISHED and drops the
 *     event. So a GENUINE processing failure has to answer 5xx (relay reschedules) while every
 *     legitimate no-op keeps answering 200 (the relay has no attempt cap; retrying a no-op is a
 *     forever-loop).
 *  2. courier:reconcile-shipments — the poll that catches whatever still slipped through. It must
 *     apply a status the partner has moved on to, and do nothing at all when it hasn't.
 *
 * CourierService is faked only where it talks HTTP (shippingServiceEnabled / track); mapping and
 * applyNormalizedStatus stay REAL, so the monotonic seam is what the reconcile assertions exercise.
 */
final class ShipmentReconcileTest extends TestCase
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
            'services.shipping_service.callback_key' => 'callback-secret',
        ]);
        DB::purge('sqlite');

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
            $t->string('delivery_mode')->nullable();
            $t->string('status')->default('pending');
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            // applyNormalizedStatus stamps these on the return leg; without them an RTO callback
            // dies on 'no such column', which is also what happens in production if the API is
            // deployed ahead of the migration that adds them.
            $t->timestamp('rto_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
        });
    }

    private function fake(FakeShippingCourier $courier): FakeShippingCourier
    {
        $this->app->instance(CourierService::class, $courier);

        return $courier;
    }

    private function shipment(array $attrs = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id'          => 1,
            'status'            => 'pending',
            'provider_order_id' => 'PO1',
        ], $attrs));
    }

    private function postCallback(array $data, array $headers = ['x-api-key' => 'callback-secret'])
    {
        return $this->postJson('/api/shipping/callback', ['id' => 77, 'event' => 'shipment.status', 'data' => $data], $headers);
    }

    // ── 1. webhook: 5xx on a real failure ────────────────────────────────────────────────────

    public function test_callback_returns_5xx_when_processing_genuinely_fails(): void
    {
        $this->fake(new FakeShippingCourier())->throwOnApply = true;
        $shipment = $this->shipment();

        $this->postCallback(['shipment_ref' => (string) $shipment->id, 'normalized_status' => 'delivered'])
            ->assertStatus(503);

        // Nothing was applied — which is exactly why the relay must be told to come back.
        $this->assertSame('pending', $shipment->fresh()->status);
    }

    // ── 1b. webhook: 200 on the no-ops (retrying these forever is the other failure mode) ─────

    public function test_callback_returns_200_when_the_shipping_service_is_disabled(): void
    {
        $this->fake(new FakeShippingCourier())->on = false;

        $this->postCallback(['shipment_ref' => '1', 'normalized_status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_callback_returns_200_for_an_unknown_or_malformed_shipment_ref(): void
    {
        $this->fake(new FakeShippingCourier());

        $this->postCallback(['shipment_ref' => '999999', 'normalized_status' => 'delivered'])->assertOk();
        $this->postCallback(['shipment_ref' => '12-x', 'normalized_status' => 'delivered'])->assertOk();
        $this->postCallback(['shipment_ref' => '1', 'normalized_status' => ''])->assertOk();
    }

    public function test_callback_returns_200_on_a_terminal_sticky_replay(): void
    {
        // REAL applyNormalizedStatus: a replayed event against a finished shipment is a no-op,
        // not a failure, so it must be acked and dropped rather than retried for an hour forever.
        $this->fake(new FakeShippingCourier());
        $shipment = $this->shipment(['status' => 'delivered', 'awb_number' => 'AWB1']);

        $this->postCallback(['shipment_ref' => (string) $shipment->id, 'normalized_status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_callback_rejects_a_bad_key(): void
    {
        $this->fake(new FakeShippingCourier())->throwOnApply = true;

        $this->postCallback(['shipment_ref' => '1', 'normalized_status' => 'delivered'], ['x-api-key' => 'nope'])
            ->assertStatus(401);
    }

    // ── 2. reconcile ─────────────────────────────────────────────────────────────────────────

    public function test_reconcile_applies_a_changed_status(): void
    {
        $courier = $this->fake(new FakeShippingCourier());
        $courier->status = 'assigned';
        $shipment = $this->shipment(['status' => 'pending']);

        $this->artisan('courier:reconcile-shipments')->assertSuccessful();

        $fresh = $shipment->fresh();
        $this->assertSame([$shipment->id], $courier->tracked);
        $this->assertSame('assigned', $fresh->status);
        $this->assertSame('assigned', $fresh->last_status);
    }

    public function test_reconcile_is_a_noop_on_an_unchanged_status(): void
    {
        $courier = $this->fake(new FakeShippingCourier());
        $courier->status = 'assigned';
        $shipment = $this->shipment(['status' => 'assigned']);

        $this->artisan('courier:reconcile-shipments')->assertSuccessful();

        $fresh = $shipment->fresh();
        $this->assertSame('assigned', $fresh->status);
        // The seam's no-op branch only stamps last_status_at; last_status stays untouched.
        $this->assertNull($fresh->last_status);
    }

    public function test_reconcile_skips_terminal_unbooked_and_self_delivery_legs(): void
    {
        $courier = $this->fake(new FakeShippingCourier());
        $courier->status = 'delivered';

        $this->shipment(['status' => 'delivered']);
        $this->shipment(['status' => 'rto']);
        $this->shipment(['status' => 'cancelled']);
        $this->shipment(['status' => 'pending', 'provider_order_id' => null]);            // never booked
        $this->shipment(['status' => 'pending', 'delivery_mode' => 'self']);              // vendor's own
        $this->shipment(['status' => 'pending', 'delivery_mode' => 'self', 'provider_order_id' => null]);

        $this->artisan('courier:reconcile-shipments')->assertSuccessful();

        $this->assertSame([], $courier->tracked);
    }

    public function test_reconcile_is_a_noop_when_courier_is_off(): void
    {
        $courier = $this->fake(new FakeShippingCourier());
        $courier->on = false;
        $this->shipment();

        $this->artisan('courier:reconcile-shipments')->assertSuccessful();

        $this->assertSame([], $courier->tracked);
    }

    public function test_reconcile_survives_a_failing_track_and_respects_the_limit(): void
    {
        $courier = $this->fake(new FakeShippingCourier());
        $courier->status = 'assigned';
        $broken = $this->shipment();
        $good   = $this->shipment();
        $courier->throwFor = [$broken->id];

        $this->artisan('courier:reconcile-shipments')->assertSuccessful();

        $this->assertSame([$broken->id, $good->id], $courier->tracked, 'one dead partner must not abort the pass');
        $this->assertSame('pending', $broken->fresh()->status);
        $this->assertSame('assigned', $good->fresh()->status);

        $courier->tracked = [];
        $this->artisan('courier:reconcile-shipments', ['--limit' => 1])->assertSuccessful();
        $this->assertCount(1, $courier->tracked);
    }

    public function test_reconcile_follows_an_rto_through_its_stages(): void
    {
        // Every RTO stage persists as status='rto' with the stage in last_status. Excluding 'rto'
        // outright hid the entire return leg from the poller, so rto_in_transit and rto_delivered
        // could only ever arrive by webhook — the one hop that does not fire for Shiprocket.
        $courier = $this->fake(new FakeShippingCourier());
        $courier->status = 'rto_delivered';

        $inFlight = $this->shipment(['status' => 'rto', 'last_status' => 'rto_initiated']);
        $done     = $this->shipment(['status' => 'rto', 'last_status' => 'rto_delivered']);
        $legacy   = $this->shipment(['status' => 'rto']);   // no stage = legacy terminal

        $this->artisan('courier:reconcile-shipments')->assertSuccessful();

        $this->assertSame([$inFlight->id], $courier->tracked, 'only the in-flight RTO stage is polled');
        $this->assertSame('rto_delivered', $done->fresh()->last_status);
        $this->assertSame('rto', $legacy->fresh()->status);
    }

}

/**
 * Fakes ONLY the HTTP edges (enabled + track). mapServiceStatus and applyNormalizedStatus are the
 * real ones, so these tests pin the actual status seam rather than a mock of it.
 */
class FakeShippingCourier extends CourierService
{
    public bool $on = true;
    public bool $throwOnApply = false;
    public string $status = 'assigned';
    /** @var int[] */
    public array $tracked = [];
    /** @var int[] */
    public array $throwFor = [];

    public function __construct()
    {
        // Deliberately skip parent::__construct() — it reads Settings + courier_partner_configs.
    }

    public function enabled(): bool
    {
        return $this->on;
    }

    public function shippingServiceEnabled(): bool
    {
        return $this->on;
    }

    public function track(Shipment $shipment): array
    {
        $this->tracked[] = (int) $shipment->id;
        if (in_array((int) $shipment->id, $this->throwFor, true)) {
            throw new RuntimeException('shipping service unreachable');
        }

        return ['ok' => true, 'status' => 200, 'data' => ['status' => $this->status], 'error' => null];
    }

    public function applyNormalizedStatus(Shipment $shipment, array $map): array
    {
        if ($this->throwOnApply) {
            throw new RuntimeException('database has gone away');
        }

        return parent::applyNormalizedStatus($shipment, $map);
    }

}
