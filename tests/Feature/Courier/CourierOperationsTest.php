<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Shipment;
use Marvel\Http\Controllers\CourierShipmentController;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\Courier\ShippingServiceClient;
use Tests\TestCase;

/**
 * The post-booking half of courier fulfilment: paperwork (invoice / manifest / label), the two
 * different cancels, courier reassignment, NDR and returns — plus the two rules that make them
 * safe:
 *
 *  1. STATE GUARDS. Every one of these operations needs something to exist at the partner first.
 *     Without a guard the partner answers a raw 422 naming an id it has never seen, which reads to
 *     an operator like an outage instead of "you skipped a step".
 *  2. THE COURIER CHOICE IS NOT REPLAYED. courier_company_id is persisted for display and never
 *     cleared, so reading it back into every later partner request meant a rebook (or the auto-book
 *     listener, which chooses nothing) named a courier off an expired rate card.
 *
 * And the RTO state machine: a bounce has STAGES, and treating the first one as terminal made
 * every later one invisible — a parcel marked "RTO initiated" could never be seen to arrive back.
 */
final class CourierOperationsTest extends TestCase
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

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->text('shipping_address')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->integer('order_quantity')->default(1);
            $t->decimal('unit_price')->default(0);
            $t->timestamps();
        });
        // The simulate-flow proxy stamps both this and the shipment; without the table the
        // console update throws before the assertion is reached.
        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('partner_code', 32);
            $t->string('provider_order_id', 191)->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->unsignedTinyInteger('simulation_flow_type')->nullable();
            $t->timestamp('simulation_started_at')->nullable();
            $t->unsignedBigInteger('simulation_started_by')->nullable();
            $t->timestamps();
            $t->string('origin', 16)->default('console');
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->string('previous_partner_status', 24)->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->string('status_source', 16)->nullable();
            $t->text('tracking_url')->nullable();
            $t->json('latest_tracking_payload')->nullable();
            $t->json('simulation_response')->nullable();
            $t->smallInteger('simulation_http_status')->nullable();
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
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->string('delivery_mode')->nullable();
            $t->string('mode')->nullable();
            $t->string('status')->default('pending');
            $t->decimal('cod_amount')->nullable();
            $t->unsignedInteger('weight_g')->nullable();
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('provider_shipment_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('courier_name')->nullable();
            $t->unsignedInteger('courier_company_id')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('label_url')->nullable();
            $t->string('invoice_url')->nullable();
            $t->string('manifest_url')->nullable();
            $t->timestamp('pickup_scheduled_at')->nullable();
            $t->string('pickup_token')->nullable();
            $t->unsignedTinyInteger('simulation_flow_type')->nullable();
            $t->timestamp('simulation_started_at')->nullable();
            $t->string('ndr_reason')->nullable();
            $t->unsignedTinyInteger('ndr_attempts')->default(0);
            $t->timestamp('ndr_action_at')->nullable();
            $t->timestamp('rto_at')->nullable();
            $t->string('return_awb')->nullable();
            $t->string('return_provider_order_id')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('booked_cost')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->string('cancelled_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('sku')->nullable();
            $t->unsignedInteger('weight')->default(0);
            $t->decimal('length', 6, 2)->default(0);
            $t->decimal('breadth', 6, 2)->default(0);
            $t->decimal('height', 6, 2)->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->string('pickup_location_name')->nullable();
            $t->timestamps();
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
        // The Order model eager-loads products WITH pivot columns — a bare join table makes every
        // test explode in the relation instead of in the code under test.
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
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('title')->nullable();
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->timestamps();
        });

        DB::table('shops')->insert([
            'id' => 5, 'name' => 'Vendor 5',
            'address' => json_encode(['street_address' => '1 Lane', 'city' => 'Delhi', 'zip' => '110001']),
            'pickup_location_name' => 'shop-5',
        ]);
        // The admin master switch. Every courier operation is inert without it.
        DB::table('settings')->insert([
            'language' => 'en',
            'options'  => json_encode(['courier' => ['enabled' => true]]),
        ]);
    }

    // ── state guards ──────────────────────────────────────────────────────────────────────────

    public function test_paperwork_needs_a_booking(): void
    {
        Http::fake(); // nothing may reach the partner
        $c = new CourierShipmentController();
        $unbooked = $this->shipment();

        foreach (['invoice', 'manifest', 'label', 'pickup'] as $op) {
            $res = $c->{$op}(new Request(), $unbooked->id);
            $this->assertSame(409, $res->getStatusCode(), "$op must refuse an unbooked shipment");
            $this->assertStringContainsString('not booked', (string) $res->getData()->error);
        }
        Http::assertNothingSent();
    }

    public function test_a_cancelled_booking_is_named_as_such_rather_than_never_booked(): void
    {
        Http::fake();
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'cancelled']);

        $res = (new CourierShipmentController())->invoice(new Request(), $shipment->id);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('cancelled', (string) $res->getData()->error);
    }

    public function test_cancel_by_awb_needs_an_awb(): void
    {
        Http::fake();
        // Booked, but the courier has not allocated a waybill yet.
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'status' => 'assigned']);

        $res = (new CourierShipmentController())->cancelAwb(new Request(), $shipment->id);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('no AWB', (string) $res->getData()->error);
        Http::assertNothingSent();
    }

    public function test_ndr_action_needs_an_awb(): void
    {
        Http::fake();
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'status' => 'assigned']);

        $res = (new CourierShipmentController())->ndrAction(
            Request::create('/ndr-action', 'POST', ['action' => 'reattempt']),
            $shipment->id,
        );

        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('no AWB', (string) $res->getData()->error);
        Http::assertNothingSent();
    }

    public function test_reassignment_is_refused_once_the_courier_has_the_parcel(): void
    {
        Http::fake();
        $shipment = $this->shipment([
            'provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'shipped',
        ]);

        $res = (new CourierShipmentController())->reassignCourier(
            Request::create('/reassign', 'POST', ['courier_id' => 42]),
            $shipment->id,
        );

        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('already picked this shipment up', (string) $res->getData()->error);
        // Critically: no quote was fetched and no partner call was made.
        Http::assertNothingSent();
    }

    public function test_reassignment_requires_a_courier_id(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'assigned']);

        $res = (new CourierShipmentController())->reassignCourier(Request::create('/reassign', 'POST'), $shipment->id);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_a_bulk_manifest_refuses_the_whole_batch_and_names_the_unbooked(): void
    {
        Http::fake();
        $booked = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'assigned']);
        $unbooked = $this->shipment();

        $res = (new CourierShipmentController())->manifestBulk(
            Request::create('/manifests', 'POST', ['shipment_ids' => [$booked->id, $unbooked->id]]),
        );

        $this->assertSame(409, $res->getStatusCode());
        // A partial sheet would silently omit parcels the driver is actually taking.
        $this->assertStringContainsString((string) $unbooked->id, (string) $res->getData()->error);
        $this->assertStringNotContainsString('http', (string) $res->getData()->error);
        Http::assertNothingSent();
    }

    // ── persistence of the provider identifiers ───────────────────────────────────────────────

    public function test_invoice_manifest_and_pickup_are_persisted(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'assigned']);
        Http::fake([
            '*/invoice'  => Http::response(['ok' => true, 'invoice_url' => 'https://s/i.pdf'], 200),
            '*/manifest' => Http::response(['ok' => true, 'manifest_url' => 'https://s/m.pdf'], 200),
            '*/pickup'   => Http::response(['ok' => true, 'pickup' => [
                'pickup_scheduled_date' => '2026-08-20 10:00:00',
                'pickup_token_number'   => 'TOK-9',
            ]], 200),
        ]);

        $c = new CourierShipmentController();
        $this->assertSame(200, $c->invoice(new Request(), $shipment->id)->getStatusCode());
        $this->assertSame(200, $c->manifest(new Request(), $shipment->id)->getStatusCode());
        $this->assertSame(200, $c->pickup(new Request(), $shipment->id)->getStatusCode());

        $fresh = $shipment->fresh();
        $this->assertSame('https://s/i.pdf', $fresh->invoice_url);
        $this->assertSame('https://s/m.pdf', $fresh->manifest_url, 'manifest_url has had a column and no writer since June');
        $this->assertSame('TOK-9', $fresh->pickup_token);
        $this->assertStringStartsWith('2026-08-20 10:00', (string) $fresh->pickup_scheduled_at);
    }

    /**
     * The wire contract, using the key the Go service ACTUALLY sends.
     *
     * PickupResult (internal/partner/types.go) serialises `scheduled_date`. This side read
     * `scheduled_at ?? pickup_scheduled_date` — neither of which exists — so pickup_scheduled_at
     * was written NULL on every successful pickup, the button never flipped to "Re-schedule" and
     * no date ever rendered. The test above missed it by stubbing one of the aliases rather than
     * the real key, so both sides agreed with each other and neither agreed with the service.
     */
    public function test_pickup_reads_the_key_the_service_actually_sends(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'assigned']);
        Http::fake(['*/pickup' => Http::response(['ok' => true, 'pickup' => [
            'scheduled_date'      => '2026-08-21 09:30:00',
            'pickup_token_number' => 'TOK-REAL',
        ]], 200)]);

        $this->assertSame(200, (new CourierShipmentController())->pickup(new Request(), $shipment->id)->getStatusCode());

        $fresh = $shipment->fresh();
        $this->assertStringStartsWith(
            '2026-08-21 09:30',
            (string) $fresh->pickup_scheduled_at,
            'the service sends scheduled_date; reading only the aliases wrote NULL every time',
        );
        $this->assertSame('TOK-REAL', $fresh->pickup_token);
    }

    /**
     * A Shiprocket order can be CREATED while the waybill is refused. The service says so —
     * last_status 'awb_pending' plus the partner's reason — and this side used to hard-code
     * 'booked' and null the reason, erasing both. That is why every paperwork button failed with
     * nothing on screen to explain why.
     */
    public function test_an_awb_refusal_survives_the_book_write(): void
    {
        $shipment = $this->shipment(['status' => 'pending']);
        $kyc = 'KYC verification is mandated for your account to ship an order.';
        Http::fake(['*/v1/shipments' => Http::response([
            'partner'              => 'shiprocket',
            'mode'                 => 'courier',
            'provider_order_id'    => '1524274089',
            'provider_shipment_id' => '1520000000',
            'awb_number'           => '',
            'status'               => 'assigned',
            'last_status'          => 'awb_pending',
            'failure_reason'       => $kyc,
        ], 200)]);

        (new ShippingServiceClient())->book($shipment, 'courier', false, 0.0);

        $fresh = $shipment->fresh();
        $this->assertSame('awb_pending', $fresh->last_status, "'booked' was hard-coded over the service's own verdict");
        $this->assertSame($kyc, $fresh->failure_reason, 'the only text telling the operator why the parcel is stuck');
    }

    /** A genuinely clean book must still clear a stale reason from an earlier attempt. */
    public function test_a_clean_book_still_clears_a_stale_reason(): void
    {
        $shipment = $this->shipment([
            'status'         => 'pending',
            'failure_reason' => 'booking outcome unknown: porter: restricted_location',
        ]);
        Http::fake(['*/v1/shipments' => Http::response([
            'partner'           => 'shiprocket',
            'provider_order_id' => 'P9',
            'awb_number'        => 'AWB9',
            'status'            => 'assigned',
        ], 200)]);

        (new ShippingServiceClient())->book($shipment, 'courier', false, 0.0);

        $this->assertNull($shipment->fresh()->failure_reason, 'stale text outlives the problem and lies');
    }

    /**
     * The hyperlocal lane is refused outright when either end is 0,0, and roughly 40% of real
     * orders arrive with no coordinates — the customer neither shared GPS nor map-picked. That took
     * Porter, Borzo AND Shiprocket Quick off the options list and reported the route as uncovered,
     * when the address was on the order the whole time.
     */
    public function test_a_drop_without_coordinates_is_geocoded_from_its_address(): void
    {
        config(['location.google_maps_key' => 'test-key']);
        \Illuminate\Support\Facades\Cache::flush();

        $order = Order::create([
            'tracking_number' => 'GEO-1',
            'shipping_address' => json_encode([
                'street_address' => '27, Lajpat Nagar II',
                'city' => 'Delhi', 'state' => 'Delhi', 'zip' => '110024',
            ]),
        ]);
        $shipment = $this->shipment(['order_id' => $order->id, 'mode' => 'same_city']);

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'results' => [['geometry' => ['location' => ['lat' => 28.5677, 'lng' => 77.2433]]]],
            ], 200),
            '*/v1/quotes' => Http::response(['quotes' => [], 'mode' => 'same_city'], 200),
        ]);

        (new ShippingServiceClient())->quoteShipment($shipment->fresh(), 'same_city', false, 0.0);

        $sent = null;
        Http::assertSent(function ($req) use (&$sent) {
            if (!str_contains($req->url(), '/v1/quotes')) {
                return false;
            }
            $sent = $req->data();
            return true;
        });

        $this->assertNotNull($sent, 'no quote request was sent');
        $this->assertSame(28.5677, $sent['drop']['lat'], 'drop lat was left at 0 — the hyperlocal lane is refused at 0,0');
        $this->assertSame(77.2433, $sent['drop']['lng']);
    }

    /**
     * Ordering constraint, not a preference: the Go service allows a partner 25s before returning
     * a considered error. A shorter timeout here abandons the connection first, so that error can
     * never arrive and every slow call reads as a network fault. The Integrations UI exposes this
     * field and staging had it set to 15.
     */
    public function test_the_shipping_timeout_cannot_be_set_below_the_services_own_budget(): void
    {
        config(['services.shipping_service.timeout' => 15]);

        $ref = new \ReflectionMethod(ShippingServiceClient::class, 'defaultTimeout');
        $ref->setAccessible(true);
        $this->assertGreaterThanOrEqual(
            35,
            $ref->invoke(new ShippingServiceClient()),
            'a stored 15s means we hang up 10s before the service can answer',
        );
    }

    /** A Request whose user() is a super admin — the proxy re-checks SUPER_ADMIN itself. */
    private function adminRequest(array $body): Request
    {
        $request = new Request($body);
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($permission): bool
            {
                return true;
            }
        });
        return $request;
    }

    /**
     * Starting a Porter UAT flow must record WHICH flow, on the shipment.
     *
     * The stamp existed but only targeted `partner_console_orders`, and that table only has rows
     * for orders the Integrations console created. The simulator people use runs on a real
     * shipment's CRN, so the update matched zero rows every time and the flow was recorded nowhere
     * durable — which is why a page refresh forgot it and offered "Start" again.
     */
    public function test_starting_a_flow_records_it_on_the_shipment(): void
    {
        $shipment = $this->shipment([
            'provider'          => 'porter',
            'provider_order_id' => 'CRN-SIM-1',
            'status'            => 'assigned',
        ]);
        Http::fake(['*/simulate-flow' => Http::response(['ok' => true], 200)]);

        $req = $this->adminRequest(['provider_order_id' => 'CRN-SIM-1', 'flow_type' => 3]);
        (new \Marvel\Http\Controllers\CourierPartnerProxyController())->simulateFlow($req, 'porter');

        $fresh = $shipment->fresh();
        $this->assertSame(3, (int) $fresh->simulation_flow_type, 'the chosen flow was not recorded');
        $this->assertNotNull($fresh->simulation_started_at);
    }

    /** A refused simulation must not be recorded as running. */
    public function test_a_refused_flow_is_not_stamped(): void
    {
        $shipment = $this->shipment([
            'provider'          => 'porter',
            'provider_order_id' => 'CRN-SIM-2',
            'status'            => 'assigned',
        ]);
        Http::fake(['*/simulate-flow' => Http::response(
            ['ok' => false, 'error' => 'porter: flow already initiated for this order'], 200,
        )]);

        $req = $this->adminRequest(['provider_order_id' => 'CRN-SIM-2', 'flow_type' => 3]);
        (new \Marvel\Http\Controllers\CourierPartnerProxyController())->simulateFlow($req, 'porter');

        $this->assertNull($shipment->fresh()->simulation_flow_type, 'a refused flow was recorded as running');
    }

    public function test_a_partner_refusal_is_a_failure_not_an_empty_success(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'assigned']);
        Http::fake(['*/invoice' => Http::response(['ok' => false, 'error' => 'order not ready'], 200)]);

        $res = (new CourierShipmentController())->invoice(new Request(), $shipment->id);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('order not ready', $res->getData()->error);
        $this->assertNull($shipment->fresh()->invoice_url);
    }

    public function test_a_return_lands_in_its_own_columns(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'FWD1', 'status' => 'delivered']);
        // Pin the FULL path, not `*/return`: a suffix wildcard matched the old route and would have
        // gone on matching a route the service no longer serves. The body is the service's real
        // shape — `{ok, return: Booking}` — so the reverse leg's ids are the Booking's own keys.
        Http::fake(['*/v1/partners/shiprocket/returns' => Http::response([
            'ok' => true,
            'return' => ['awb_number' => 'RET1', 'provider_order_id' => 'RP1'],
        ], 200)]);

        $res = (new CourierShipmentController())->createReturn(
            Request::create('/return', 'POST', ['reason' => 'damaged']),
            $shipment->id,
        );

        $this->assertSame(200, $res->getStatusCode());
        $fresh = $shipment->fresh();
        $this->assertSame('RET1', $fresh->return_awb);
        $this->assertSame('RP1', $fresh->return_provider_order_id);
        // The forward identifiers are the record of what was shipped OUT; a reverse leg must not
        // overwrite them.
        $this->assertSame('FWD1', $fresh->awb_number);
    }

    public function test_a_reassignment_voids_the_paperwork_that_names_the_old_waybill(): void
    {
        $shipment = $this->shipment([
            'provider_order_id' => 'P1', 'awb_number' => 'OLD', 'status' => 'assigned',
            'label_url' => 'https://s/old-label.pdf', 'manifest_url' => 'https://s/old-manifest.pdf',
        ]);
        Http::fake([
            '*/v1/quotes'   => Http::response(['quotes' => [['courier_id' => 42, 'courier_name' => 'Blue Dart']]], 200),
            '*/reassign'    => Http::response(['ok' => true, 'awb_number' => 'NEW', 'courier_name' => 'Blue Dart', 'courier_id' => 42], 200),
        ]);

        $res = (new CourierShipmentController())->reassignCourier(
            Request::create('/reassign', 'POST', ['courier_id' => 42]),
            $shipment->id,
        );

        $this->assertSame(200, $res->getStatusCode());
        $fresh = $shipment->fresh();
        $this->assertSame('NEW', $fresh->awb_number);
        $this->assertNull($fresh->label_url, 'the old label names a waybill the parcel no longer carries');
        $this->assertNull($fresh->manifest_url);
    }

    public function test_a_courier_the_partner_is_not_offering_is_refused(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'OLD', 'status' => 'assigned']);
        Http::fake([
            '*/v1/quotes' => Http::response(['quotes' => [['courier_id' => 7, 'courier_name' => 'Delhivery']]], 200),
            '*/reassign'  => Http::response(['ok' => true, 'awb_number' => 'NEW'], 200),
        ]);

        $res = (new CourierShipmentController())->reassignCourier(
            Request::create('/reassign', 'POST', ['courier_id' => 42]),
            $shipment->id,
        );

        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('OLD', $shipment->fresh()->awb_number);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/reassign'));
    }

    // ── the operator's courier choice must not be replayed ────────────────────────────────────

    public function test_a_stored_courier_choice_is_not_replayed_on_a_later_booking(): void
    {
        // What chooseCourier left behind hours ago, off a rate card that has since expired.
        $shipment = $this->shipment(['courier_company_id' => 999, 'fulfillment_mode' => 'courier']);
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket', 'awb_number' => 'A1'], 200)]);

        (new ShippingServiceClient())->book($this->reload($shipment), 'courier', false, 0.0);

        $this->assertSame(0, $this->lastBody()['courier_id'], 'a day-old courier must not be re-sent against a fresh rate card');
    }

    public function test_the_courier_validated_for_this_dispatch_is_the_one_sent(): void
    {
        $shipment = $this->shipment(['courier_company_id' => 999]);
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket', 'awb_number' => 'A1'], 200)]);

        (new ShippingServiceClient())->book($this->reload($shipment), 'courier', false, 0.0, null, 42);

        $this->assertSame(42, $this->lastBody()['courier_id']);
    }

    // ── the booking wizard's delivery options ────────────────────────────────────────────────

    public function test_delivery_options_reach_the_shipping_service(): void
    {
        $shipment = $this->shipment(['fulfillment_mode' => 'same_city']);
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'borzo', 'provider_order_id' => '1'], 200)]);

        (new ShippingServiceClient())->book($this->reload($shipment), 'same_city', false, 0.0, 'borzo', null, [
            'vehicle_id' => '7', 'insurance_amount' => 5000, 'loaders' => 2, 'collect_amount' => 250,
        ]);

        // Cast because the client sends options as a JSON OBJECT, not an array: an empty PHP
        // array would encode as `[]`, which the Go service cannot decode into its options struct.
        $opts = (array) ($this->lastBody()['options'] ?? []);
        $this->assertSame('7', $opts['vehicle_id'] ?? null, 'the operator\'s vehicle choice never left Laravel');
        $this->assertSame(5000, $opts['insurance_amount'] ?? null);
        $this->assertSame(2, $opts['loaders'] ?? null);
        $this->assertSame(250, $opts['collect_amount'] ?? null);
    }

    public function test_options_are_always_sent_even_when_empty(): void
    {
        $shipment = $this->shipment();
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket'], 200)]);

        (new ShippingServiceClient())->book($this->reload($shipment), 'courier', false, 0.0);

        $this->assertArrayHasKey(
            'options',
            $this->lastBody(),
            'an absent key makes "no options chosen" indistinguishable from "an admin too old to send them"',
        );
    }

    public function test_access_detail_on_the_address_reaches_the_partner_as_separate_fields(): void
    {
        $shipment = $this->shipment();
        $shipment->order->forceFill(['shipping_address' => ['address' => [
            'street_address' => 'H-12 Hauz Khas', 'city' => 'Delhi', 'state' => 'Delhi', 'zip' => '110016',
            'location' => ['lat' => 28.5, 'lng' => 77.1],
            'building' => '12', 'entrance' => '2', 'floor' => '4', 'intercom' => '4421',
            'courier_note' => 'Gate 2, ask security',
        ]]])->save();
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'borzo'], 200)]);

        (new ShippingServiceClient())->book($this->reload($shipment), 'same_city', false, 0.0, 'borzo');

        $drop = $this->lastBody()['drop'] ?? [];
        foreach (['building' => '12', 'entrance' => '2', 'floor' => '4', 'intercom' => '4421', 'note' => 'Gate 2, ask security'] as $k => $want) {
            $this->assertSame($want, $drop[$k] ?? null, "access detail '$k' was flattened away instead of sent as its own field");
        }
    }

    // ── the pickup snapshot ──────────────────────────────────────────────────────────────────

    public function test_booking_freezes_the_pickup_it_actually_used(): void
    {
        $shipment = $this->shipment();
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket'], 200)]);

        (new ShippingServiceClient())->book($this->reload($shipment), 'courier', false, 0.0);

        $snap = $shipment->fresh()->pickup_snapshot;
        $this->assertIsArray($snap, 'no snapshot was taken — history will re-resolve to the vendor\'s current address');
        $sentPickup = $this->lastBody()['pickup'] ?? [];
        // assertEquals, not assertSame: the snapshot round-trips through JSON, which has no
        // int/float distinction, so a 0.0 coordinate returns as 0. Value equality is the claim.
        $this->assertEquals(
            $sentPickup,
            $snap['address'] ?? null,
            'the snapshot must be the address that was SENT, not a re-derivation of it',
        );
    }

    public function test_the_snapshot_does_not_follow_the_vendor_when_they_move(): void
    {
        $shipment = $this->shipment();
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket'], 200)]);
        (new ShippingServiceClient())->book($this->reload($shipment), 'courier', false, 0.0);
        $before = $shipment->fresh()->pickup_snapshot;

        // The vendor relocates after the parcel has already gone out.
        // Query builder, not the model: Shop's sluggable writes a `slug` column this stub schema
        // does not carry. The point of the test is the vendor's address changing, by any route.
        DB::table('shops')->where('id', $shipment->shop_id)->update([
            'address' => json_encode(['street_address' => 'SOMEWHERE ELSE', 'city' => 'Mumbai', 'state' => 'MH', 'zip' => '400001']),
        ]);

        $this->assertSame(
            $before,
            $shipment->fresh()->pickup_snapshot,
            'a vendor address edit rewrote the history of an already-dispatched shipment',
        );
    }

    // ── the courier-order option blocks ──────────────────────────────────────────────────────

    private function dispatchWith(array $options, int $shipmentId)
    {
        // The address gate runs before the options validator (correctly — there is no point
        // validating a delivery's extras when nobody knows where it goes), so give it one.
        $shipment = Shipment::find($shipmentId);
        DB::table('orders')->where('id', $shipment->order_id)->update([
            'shipping_address' => json_encode(['address' => [
                'street_address' => 'H-12 Hauz Khas', 'city' => 'Delhi', 'state' => 'Delhi',
                'zip' => '110016', 'location' => ['lat' => 28.5, 'lng' => 77.1],
            ]]),
        ]);
        $req = Request::create('/x', 'POST', ['options' => $options]);
        $req->setUserResolver(fn () => new class {
            public function hasPermissionTo() { return true; }
            public function can() { return true; }
        });
        return (new CourierShipmentController())->dispatchShipment($req, $shipmentId);
    }

    public function test_a_half_filled_billing_address_is_refused(): void
    {
        $shipment = $this->shipment();
        // Shiprocket stops copying the recipient across the moment a billing block appears, so a
        // partial payer would ship the parcel to a half-filled address.
        $res = $this->dispatchWith(['billing' => ['name' => 'Accounts Dept']], $shipment->id);

        $this->assertSame(422, $res->getStatusCode());
        $errors = $res->getData(true)['errors'] ?? [];
        foreach (['options.billing.address', 'options.billing.city', 'options.billing.pincode'] as $field) {
            $this->assertArrayHasKey($field, $errors, "$field was not demanded alongside a billing block");
        }
    }

    public function test_a_malformed_gstin_is_refused_before_the_partner_sees_it(): void
    {
        $shipment = $this->shipment();
        $res = $this->dispatchWith(['tax' => ['gstin' => 'NOT-A-GSTIN']], $shipment->id);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertArrayHasKey('options.tax.gstin', $res->getData(true)['errors'] ?? []);
    }

    public function test_an_abandoned_section_does_not_reach_the_partner(): void
    {
        $shipment = $this->shipment();
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket'], 200)]);

        // The operator opened Tax and Charges, typed nothing, and booked.
        $this->dispatchWith([
            'tax'     => ['gstin' => '', 'invoice_no' => null],
            'charges' => ['shipping' => null],
        ], $shipment->id);

        $opts = (array) ($this->lastBody()['options'] ?? []);
        $this->assertArrayNotHasKey('tax', $opts, 'an empty tax block still reached the adapter');
        $this->assertArrayNotHasKey('charges', $opts, 'an empty charges block still reached the adapter');
    }

    public function test_quoting_never_filters_by_a_stored_courier(): void
    {
        $shipment = $this->shipment(['courier_company_id' => 999, 'fulfillment_mode' => 'same_city']);
        Http::fake(['*/v1/quotes' => Http::response(['quotes' => []], 200)]);

        (new CourierService())->quoteShipment($this->reload($shipment));

        $this->assertSame(0, $this->lastBody()['courier_id']);
    }

    // ── the RTO state machine ─────────────────────────────────────────────────────────────────

    public function test_rto_stages_advance_in_order_and_the_first_one_does_not_stick(): void
    {
        $shipment = $this->shipment(['status' => 'shipped', 'awb_number' => 'A1']);
        $svc = new CourierService();

        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('rto_initiated'));
        $shipment = $shipment->fresh();
        $this->assertSame('rto', $shipment->status, 'the whole return leg reads as rto to the rest of the system');
        $this->assertSame('rto_initiated', $shipment->last_status);
        $this->assertNotNull($shipment->rto_at);
        $firstSeen = (string) $shipment->rto_at;

        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('rto_in_transit'));
        $shipment = $shipment->fresh();
        $this->assertSame('rto_in_transit', $shipment->last_status, 'the first stage must not be terminal');
        $this->assertSame($firstSeen, (string) $shipment->rto_at, 'rto_at marks when it turned back, once');

        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('rto_delivered'));
        $shipment = $shipment->fresh();
        $this->assertSame('rto_delivered', $shipment->last_status);
        $this->assertSame('rto', $shipment->status);

        // Back at origin IS terminal: an out-of-order replay of an earlier stage changes nothing.
        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('rto_in_transit'));
        $this->assertSame('rto_delivered', $shipment->fresh()->last_status);
    }

    public function test_a_legacy_bare_rto_row_can_still_be_advanced(): void
    {
        // Every row written before the leg was staged holds a bare `rto`. Treating that as
        // finished would leave them frozen at the first thing we ever heard.
        $shipment = $this->shipment(['status' => 'rto', 'last_status' => 'rto']);
        $svc = new CourierService();

        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('rto_delivered'));

        $this->assertSame('rto_delivered', $shipment->fresh()->last_status);
    }

    public function test_a_forward_status_cannot_un_bounce_a_returning_shipment(): void
    {
        $shipment = $this->shipment(['status' => 'shipped']);
        $svc = new CourierService();
        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('rto_initiated'));

        $svc->applyNormalizedStatus($shipment->fresh(), ['shipment_status' => 'out_for_delivery', 'order_status' => null]);

        $this->assertSame('rto_initiated', $shipment->fresh()->last_status);
    }

    public function test_undelivered_is_a_failed_attempt_not_a_delivery(): void
    {
        // "Undelivered" CONTAINS "delivered": this used to complete the order and fire vendor
        // settlement on a parcel still sitting in the courier's van.
        $svc = new CourierService();
        $this->assertSame('ndr', $svc->mapStatus('Undelivered')['shipment_status']);
        $this->assertNull($svc->mapStatus('Undelivered')['order_status']);
        $this->assertSame('rto_delivered', $svc->mapStatus('RTO DELIVERED')['shipment_status']);
        $this->assertSame('rto_in_transit', $svc->mapStatus('RTO IN TRANSIT')['shipment_status']);
        $this->assertSame('delivered', $svc->mapStatus('Delivered')['shipment_status']);
    }

    public function test_an_ndr_interrupts_and_the_reattempt_can_follow_it(): void
    {
        $shipment = $this->shipment(['status' => 'out_for_delivery']);
        $svc = new CourierService();

        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('ndr'));
        $this->assertSame('ndr', $shipment->fresh()->status, 'a failed attempt at the same rank must still register');

        // The reattempt sits at the SAME rank — a strict forward comparison would freeze the
        // shipment at ndr forever.
        $svc->applyNormalizedStatus($shipment->fresh(), ['shipment_status' => 'out_for_delivery', 'order_status' => null]);
        $this->assertSame('out_for_delivery', $shipment->fresh()->status);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private int $nextProductId = 1;

    private function shipment(array $attrs = []): Shipment
    {
        $order = Order::create([
            'tracking_number'  => 'T' . random_int(10000000, 99999999),
            'shipping_address' => json_encode(['city' => 'Delhi', 'zip' => '110001', 'street_address' => '2 Road']),
        ]);
        $productId = $this->nextProductId++;
        DB::table('products')->insert(['id' => $productId, 'name' => 'Areca Palm', 'sku' => 'AP' . $productId]);
        $shipment = Shipment::create(array_merge([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'courier', 'status' => 'pending',
        ], $attrs));
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productId, 'shipment_id' => $shipment->id, 'order_quantity' => 1,
        ]);
        return $shipment;
    }

    private function reload(Shipment $shipment): Shipment
    {
        return $shipment->fresh(['items.product', 'order', 'shop']);
    }

    /** The body of the last request the client actually sent. */
    private function lastBody(): array
    {
        $body = [];
        foreach (Http::recorded() as [$request, $response]) {
            $body = $request->data();
        }
        return $body;
    }

    /**
     * Laravel and Go are two repos with no shared contract, so a renamed route on one side is a 404
     * on the other that no unit test on either side can see. That is not hypothetical: seven of
     * these paths WERE wrong and shipped, because a suffix-wildcard fake happily matched a route
     * the service had stopped serving. This pins every fulfilment path against the list in
     * shipping-service `internal/api/routes_test.go` — the two lists must be edited together.
     */
    public function test_every_fulfilment_call_hits_a_path_the_service_actually_routes(): void
    {
        $shipment = $this->shipment(['provider_order_id' => 'P1', 'awb_number' => 'A1', 'status' => 'assigned']);
        Http::fake();
        $c = new \Marvel\Services\Courier\ShippingServiceClient();

        $c->generateLabel($shipment);
        $c->generateInvoice($shipment);
        $c->generateManifest($shipment);
        $c->schedulePickup($shipment);
        $c->cancel($shipment, null);
        $c->reassignCourier($shipment, 42);
        $c->ndrList();
        $c->ndrDetail('A1');
        $c->ndrAction($shipment, 'reattempt');
        $c->createReturn($shipment, 'damaged');

        // The service addresses a shipment by OUR id — that is the shipment_ref booking registers.
        $ref = (string) $shipment->id;
        $expected = [
            "/v1/shipments/{$ref}/label",
            "/v1/shipments/{$ref}/invoice",
            '/v1/shipments/manifest',
            "/v1/shipments/{$ref}/pickup",
            "/v1/shipments/{$ref}/cancel",
            "/v1/shipments/{$ref}/reassign",
            '/v1/partners/shiprocket/ndr',
            '/v1/partners/shiprocket/ndr/A1',
            '/v1/partners/shiprocket/ndr/A1/action',
            '/v1/partners/shiprocket/returns',
        ];
        $sent = [];
        foreach (Http::recorded() as [$request]) {
            $sent[] = parse_url($request->url(), PHP_URL_PATH);
        }
        $this->assertSame($expected, $sent);
    }
}
