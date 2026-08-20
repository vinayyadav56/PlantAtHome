<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use App\Models\PartnerConsoleOrder;
use App\Models\PartnerWebhookEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The every-minute ledger sweep: webhook mirroring must be idempotent by source id, events must
 * move state only through the lifecycle guard, shipment-owned CRNs must never be tracked here
 * (Porter allows ONE Track per order per minute and the Go service already polls those), and a
 * dead CRN must not be polled forever.
 */
final class ReconcileConsoleOrdersTest extends TestCase
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
            'services.shipping_service.url'     => 'https://ship.test',
            'services.shipping_service.api_key' => 'k',
        ]);
        DB::purge('sqlite');

        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('partner_code', 32);
            $t->string('provider_order_id', 191)->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('origin', 16)->default('console');
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('mode', 32)->nullable();
            $t->unsignedBigInteger('cod_amount_paise')->default(0);
            $t->json('request')->nullable();
            $t->json('response')->nullable();
            $t->string('last_status', 64)->nullable();
            $t->timestamp('last_tracked_at')->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->string('previous_partner_status', 24)->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->string('status_source', 16)->nullable();
            $t->text('tracking_url')->nullable();
            $t->json('latest_tracking_payload')->nullable();
            $t->string('driver_name', 120)->nullable();
            $t->string('driver_phone', 32)->nullable();
            $t->string('vehicle_number', 32)->nullable();
            $t->text('last_error')->nullable();
            $t->json('last_error_payload')->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->unsignedTinyInteger('track_failures')->default(0);
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('live_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('reopened_at')->nullable();
            $t->timestamps();
        });
        Schema::create('partner_webhook_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('source_webhook_log_id')->unique();
            $t->string('partner_code', 32);
            $t->string('porter_order_id', 191)->nullable();
            $t->unsignedBigInteger('partner_console_order_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->string('event_type', 64)->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->boolean('signature_valid')->default(false);
            $t->json('payload')->nullable();
            $t->bigInteger('event_ts')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->string('processing_status', 24)->nullable();
            $t->text('processing_error')->nullable();
            $t->timestamps();
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
            $t->unsignedBigInteger('order_id');
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('status')->default('pending');
            $t->string('last_status')->nullable();
            $t->string('mode', 32)->nullable();
            $t->timestamps();
        });
    }

    /** One webhook page for porter; every other partner's pull answers empty. */
    private function fakeWebhookPage(array $items): void
    {
        Http::fake([
            'ship.test/v1/partners/porter/webhooks*' => Http::response(['partner_code' => 'porter', 'items' => $items], 200),
            'ship.test/v1/partners/*/webhooks*'      => Http::response(['partner_code' => 'x', 'items' => []], 200),
            'ship.test/*'                            => Http::response(['ok' => true], 200),
        ]);
    }

    private function logRow(int $id, string $event, string $crn, array $details = []): array
    {
        return [
            'id'              => $id,
            'event_type'      => $event,
            'signature_valid' => false,
            'processed'       => false,
            'received_at'     => now()->toIso8601String(),
            'raw_body'        => json_encode(['status' => $event, 'order_id' => $crn, 'order_details' => $details]),
        ];
    }

    /**
     * Borzo nests the id under `order.order_id`; Porter sends it top-level. Reading only the top
     * level filed every Borzo callback as "no order_id in payload", so the ledger could never
     * learn anything from Borzo even when a callback arrived and verified.
     *
     * `order_changed` carries no status of its own — the authenticated re-fetch is the authority —
     * so the assertion is that the ROW gets created and linked, ready for the track pass, not
     * that a status was applied.
     */
    public function test_a_borzo_callback_with_a_nested_order_id_is_captured(): void
    {
        Http::fake([
            'ship.test/v1/partners/borzo/webhooks*' => Http::response(['partner_code' => 'borzo', 'items' => [[
                'id'              => 41,
                'event_type'      => 'order_changed',
                'signature_valid' => true,
                'processed'       => false,
                'received_at'     => now()->toIso8601String(),
                // The real Borzo shape — id one level down, not at the root.
                'raw_body'        => json_encode(['event_type' => 'order_changed', 'order' => ['order_id' => 330321, 'status' => 'available']]),
            ]]], 200),
            'ship.test/v1/partners/*/webhooks*' => Http::response(['partner_code' => 'x', 'items' => []], 200),
            'ship.test/*'                       => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        $event = PartnerWebhookEvent::where('source_webhook_log_id', 41)->first();
        $this->assertNotNull($event, 'the Borzo callback was not mirrored at all');
        $this->assertSame('330321', $event->porter_order_id, 'the nested order.order_id was not read');
        $this->assertNotSame(
            'no order_id in payload',
            $event->processing_error,
            'the nested id was still being missed — this is the exact bug the fix targets',
        );

        $row = PartnerConsoleOrder::where('partner_code', 'borzo')->where('provider_order_id', '330321')->first();
        $this->assertNotNull($row, 'a Borzo CRN unknown to both ledgers must be captured, not lost');
        $this->assertSame('webhook', $row->origin);
        $this->assertNotNull($event->partner_console_order_id, 'the event was not linked to its ledger row');
    }

    public function test_webhooks_are_mirrored_and_drive_the_lifecycle(): void
    {
        $this->fakeWebhookPage([
            $this->logRow(11, 'order_accepted', 'CRN9', ['driver_details' => ['driver_name' => 'H Yadav', 'mobile' => '789', 'vehicle_number' => 'MH-04']]),
            $this->logRow(12, 'order_start_trip', 'CRN9'),
        ]);

        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        $this->assertSame(2, PartnerWebhookEvent::count());
        $row = PartnerConsoleOrder::where('provider_order_id', 'CRN9')->first();
        $this->assertNotNull($row, 'a webhook CRN unknown to both ledgers must be captured, not lost');
        $this->assertSame('webhook', $row->origin);
        $this->assertSame('live', $row->partner_status);
        $this->assertSame('H Yadav', $row->driver_name);
        $this->assertNotNull($row->accepted_at);
        $this->assertNotNull($row->live_at);
        $this->assertSame('applied', PartnerWebhookEvent::where('source_webhook_log_id', 11)->value('processing_status'));
    }

    /** Test E at the pipeline level: re-reading the same source rows duplicates nothing. */
    public function test_the_mirror_is_idempotent_across_passes(): void
    {
        $this->fakeWebhookPage([$this->logRow(21, 'order_accepted', 'CRN9')]);
        $this->artisan('console-orders:reconcile')->assertExitCode(0);
        // The command computes its cursor from max(source id); fake the service re-sending the
        // same row anyway (a retry, a cursor bug — either way the unique key must hold).
        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        $this->assertSame(1, PartnerWebhookEvent::count(), 'same source row mirrored twice');
        $this->assertSame(1, PartnerConsoleOrder::count());
    }

    /** Test F at the pipeline level: a stale event after a newer one is recorded but not applied. */
    public function test_an_out_of_order_event_is_kept_in_the_timeline_but_not_applied(): void
    {
        $this->fakeWebhookPage([
            $this->logRow(31, 'order_start_trip', 'CRN9'),
            $this->logRow(32, 'order_accepted', 'CRN9'), // late duplicate of an earlier stage
        ]);
        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        $this->assertSame('live', PartnerConsoleOrder::where('provider_order_id', 'CRN9')->value('partner_status'));
        $this->assertSame('stale', PartnerWebhookEvent::where('source_webhook_log_id', 32)->value('processing_status'));
        $this->assertSame(2, PartnerWebhookEvent::count(), 'the stale event still belongs to the timeline');
    }

    public function test_shipment_crns_are_ingested_but_never_tracked_here(): void
    {
        DB::table('shipments')->insert([
            'order_id' => 217, 'provider' => 'porter', 'provider_order_id' => 'CRN-SHIP',
            'status' => 'assigned', 'last_status' => 'assigned', 'mode' => 'same_city',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeWebhookPage([]);

        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        $row = PartnerConsoleOrder::where('provider_order_id', 'CRN-SHIP')->first();
        $this->assertNotNull($row, 'shipment bookings must appear in the central ledger');
        $this->assertSame('shipment', $row->origin);
        $this->assertSame(217, (int) $row->order_id);
        $this->assertSame('accepted', $row->partner_status, 'status mirrored from the shipment');

        // Porter allows ONE Track per order per minute and the Go service polls shipment
        // bookings already — tracking them here too would guarantee a 429 duet.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/test/track'));
    }

    public function test_dead_rows_hit_the_give_up_rule(): void
    {
        PartnerConsoleOrder::create([
            'partner_code' => 'porter', 'provider_order_id' => 'CRN-DEAD', 'origin' => 'console',
            'track_failures' => 5,
        ]);
        PartnerConsoleOrder::create([
            'partner_code' => 'porter', 'provider_order_id' => 'CRN-OLD', 'origin' => 'console',
            'created_at' => now()->subDays(3),
        ]);
        $this->fakeWebhookPage([]);

        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/test/track'));
    }

    public function test_an_active_row_is_tracked_and_a_swallowed_429_does_not_phase_lock(): void
    {
        PartnerConsoleOrder::create([
            'partner_code' => 'porter', 'provider_order_id' => 'CRN-ACT', 'origin' => 'console',
            'partner_status' => 'accepted',
        ]);
        Http::fake([
            'ship.test/v1/partners/*/webhooks*' => Http::response(['items' => []], 200),
            // ok:true with an empty status — Porter's swallowed-429 shape.
            'ship.test/v1/partners/porter/test/track*' => Http::response(['ok' => true, 'status' => ''], 200),
        ]);

        $this->artisan('console-orders:reconcile')->assertExitCode(0);

        $row = PartnerConsoleOrder::where('provider_order_id', 'CRN-ACT')->first();
        $this->assertNull($row->last_tracked_at, 'stamping last_tracked_at on a 429 would phase-lock the row stale');
        $this->assertSame(1, (int) $row->track_failures);
        $this->assertSame('accepted', $row->partner_status, 'an empty answer must never move state');
    }
}
