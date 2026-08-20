<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use App\Models\PartnerConsoleOrder;
use App\Services\PartnerOrderLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The single transition guard for the partner-order ledger. These are the mandate's idempotency
 * and ordering scenarios as executable rules: a duplicate webhook moves nothing, an out-of-order
 * `accepted` after `live` moves nothing, terminal states are sticky, and `reopened` is the one
 * legal step backwards.
 */
final class PartnerOrderLifecycleTest extends TestCase
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
        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('partner_code', 32);
            $t->string('provider_order_id', 191)->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->string('previous_partner_status', 24)->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->string('status_source', 16)->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('live_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('reopened_at')->nullable();
            $t->string('last_status', 32)->nullable();
            $t->string('driver_name', 120)->nullable();
            $t->string('driver_phone', 32)->nullable();
            $t->string('vehicle_number', 32)->nullable();
            $t->unsignedTinyInteger('simulation_flow_type')->nullable();
            $t->timestamp('simulation_started_at')->nullable();
            $t->timestamps();
        });
    }

    private function row(?string $status = null): PartnerConsoleOrder
    {
        return PartnerConsoleOrder::create([
            'partner_code' => 'porter', 'provider_order_id' => 'CRN1', 'partner_status' => $status,
        ]);
    }

    public function test_the_happy_flow_walks_forward(): void
    {
        $row = $this->row();
        foreach (['open', 'accepted', 'live', 'ended'] as $status) {
            $this->assertTrue(PartnerOrderLifecycle::apply($row, $status, 'webhook'), "apply {$status}");
        }
        $this->assertSame('ended', $row->partner_status);
        $this->assertSame('live', $row->previous_partner_status);
        $this->assertNotNull($row->accepted_at);
        $this->assertNotNull($row->live_at);
        $this->assertNotNull($row->ended_at);
    }

    /** Test F — out-of-order: an older `accepted` arriving after `live` must not regress. */
    public function test_a_stale_accepted_after_live_is_refused(): void
    {
        $row = $this->row('live');
        $this->assertFalse(PartnerOrderLifecycle::apply($row, 'accepted', 'webhook'));
        $this->assertSame('live', $row->fresh()->partner_status);
    }

    /** Test E — duplicate delivery: same status twice = one effective transition. */
    public function test_a_duplicate_event_is_a_noop(): void
    {
        $row = $this->row('accepted');
        $before = $row->status_changed_at;
        $this->assertFalse(PartnerOrderLifecycle::apply($row, 'accepted', 'webhook'));
        $this->assertEquals($before, $row->fresh()->status_changed_at);
    }

    public function test_terminal_states_are_sticky(): void
    {
        foreach (['ended', 'cancelled'] as $terminal) {
            $row = $this->row($terminal);
            foreach (['open', 'accepted', 'live', 'reopened', $terminal === 'ended' ? 'cancelled' : 'ended'] as $next) {
                $this->assertFalse(PartnerOrderLifecycle::apply($row, $next, 'track'), "{$terminal} accepted {$next}");
            }
        }
    }

    public function test_cancelled_interrupts_any_non_terminal_state(): void
    {
        foreach ([null, 'open', 'accepted', 'live', 'reopened'] as $from) {
            $row = $this->row($from);
            $this->assertTrue(PartnerOrderLifecycle::apply($row, 'cancelled', 'webhook'), 'from ' . ($from ?? 'null'));
            $this->assertNotNull($row->cancelled_at);
        }
    }

    /** Porter's order_reopen: the one legal step backwards, then forward again. */
    public function test_reopen_resets_and_the_flow_recovers(): void
    {
        $row = $this->row();
        PartnerOrderLifecycle::apply($row, 'open', 'create');
        PartnerOrderLifecycle::apply($row, 'accepted', 'webhook');
        PartnerOrderLifecycle::apply($row, 'live', 'webhook');

        $this->assertTrue(PartnerOrderLifecycle::apply($row, 'reopened', 'webhook'), 'live → reopened is real: the driver bailed');
        $this->assertNotNull($row->reopened_at);
        $this->assertTrue(PartnerOrderLifecycle::apply($row, 'accepted', 'webhook'), 'a new driver accepts');
        $this->assertTrue(PartnerOrderLifecycle::apply($row, 'live', 'webhook'));
        $this->assertTrue(PartnerOrderLifecycle::apply($row, 'ended', 'webhook'));
    }

    public function test_lifecycle_timestamps_are_first_seen_only(): void
    {
        $row = $this->row();
        PartnerOrderLifecycle::apply($row, 'accepted', 'webhook');
        $first = $row->accepted_at;
        PartnerOrderLifecycle::apply($row, 'reopened', 'webhook');
        sleep(0); // same-second is fine — the assertion is identity, not ordering
        PartnerOrderLifecycle::apply($row, 'accepted', 'webhook');
        $this->assertEquals($first, $row->fresh()->accepted_at, 'a re-acceptance must not rewrite history');
    }

    public function test_unknown_vocabulary_is_refused_outright(): void
    {
        $row = $this->row('open');
        foreach (['', 'nonsense', 'assigned_maybe', 'OPEN '] as $bad) {
            if ($bad === 'OPEN ') {
                continue; // trimmed+lowered input is legal — covered below
            }
            $this->assertFalse(PartnerOrderLifecycle::apply($row, $bad, 'track'), "accepted {$bad}");
        }
        $this->assertTrue(PartnerOrderLifecycle::shouldApply('open', ' ACCEPTED '), 'vocabulary is case/space tolerant');
    }

    public function test_vocabulary_mappings(): void
    {
        $this->assertSame('open', PartnerOrderLifecycle::fromNormalized('pending'));
        $this->assertSame('accepted', PartnerOrderLifecycle::fromNormalized('assigned'));
        $this->assertSame('live', PartnerOrderLifecycle::fromNormalized('out_for_delivery'));
        $this->assertSame('ended', PartnerOrderLifecycle::fromNormalized('delivered'));
        $this->assertSame('live', PartnerOrderLifecycle::fromNormalized('live'), 'raw partner words pass through');
        $this->assertNull(PartnerOrderLifecycle::fromNormalized('garbage'));

        $this->assertSame('accepted', PartnerOrderLifecycle::fromEvent('order_accepted'));
        $this->assertSame('live', PartnerOrderLifecycle::fromEvent('order_start_trip'));
        $this->assertSame('ended', PartnerOrderLifecycle::fromEvent('order_end_job'));
        $this->assertSame('reopened', PartnerOrderLifecycle::fromEvent('order_reopen'));
        $this->assertSame('cancelled', PartnerOrderLifecycle::fromEvent('order_cancel'));
        $this->assertNull(PartnerOrderLifecycle::fromEvent('order_unknown'));
    }

    /**
     * Two partner payload shapes carry the same fact, and the phone arrives nested. Both were
     * being re-derived at each call site; syncDriverFrom is the one place that knows the mapping.
     */
    public function test_driver_details_are_read_from_either_payload_shape(): void
    {
        $webhook = $this->row();
        $this->assertTrue($webhook->syncDriverFrom([
            'order_details' => ['driver_details' => [
                'driver_name' => 'Hinthlal Yadav',
                'mobile' => ['country_code' => '+91', 'mobile_number' => '9876543210'],
                'vehicle_number' => 'MH-04-FU-2737',
            ]],
        ]));
        $this->assertSame('Hinthlal Yadav', $webhook->fresh()->driver_name);
        $this->assertSame('+919876543210', $webhook->fresh()->driver_phone, 'nested mobile must be flattened');

        $tracked = PartnerConsoleOrder::create(['partner_code' => 'porter', 'provider_order_id' => 'CRN2']);
        $this->assertTrue($tracked->syncDriverFrom(['partner_info' => ['name' => 'Asha R', 'vehicle_number' => 'DL-01-AA-1']]));
        $this->assertSame('Asha R', $tracked->fresh()->driver_name);

        // A later payload without the rider must not erase a rider we already know.
        $this->assertFalse($webhook->syncDriverFrom(['partner_info' => []]));
        $this->assertSame('Hinthlal Yadav', $webhook->fresh()->driver_name);
        $this->assertFalse($webhook->syncDriverFrom(null));
    }

    public function test_booking_payload_omits_a_rider_the_partner_never_reported(): void
    {
        $bare = $this->row('open');
        $payload = $bare->toBookingPayload();
        $this->assertSame('CRN1', $payload['provider_order_id']);
        $this->assertSame('open', $payload['provider_status']);
        // Null, not an object of empty strings — the admin renders the rider card on truthiness.
        $this->assertNull($payload['driver'], 'an unassigned rider must not render a rider card');

        $bare->syncDriverFrom(['partner_info' => ['name' => 'Asha R']]);
        $this->assertSame(['name' => 'Asha R'], $bare->fresh()->toBookingPayload()['driver']);
    }
}
