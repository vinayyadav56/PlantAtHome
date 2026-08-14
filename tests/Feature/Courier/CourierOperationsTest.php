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
