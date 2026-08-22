<?php

namespace Tests\Feature\Courier;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Shipment;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Services\Courier\CourierService;
use Tests\TestCase;

/**
 * Checkout accepts CASH_ON_DELIVERY, COD and CASH interchangeably and stores whichever the client
 * sent (CheckoutRepository::463). CourierService compared with `=== CASH_ON_DELIVERY`, so a 'CASH'
 * order was handed to the courier as PREPAID: the rider was never told to collect and the entire
 * order value was lost, silently, with no error anywhere.
 *
 * Two layers are pinned here on purpose. The predicate test fails if someone edits the accepted set;
 * the wire test fails if someone reintroduces a direct `===` comparison at a call site. Only the
 * second would have caught the original bug.
 *
 * DB-less, following CourierPartnerProxyTest: unsaved models with hand-wired relations, the Go
 * service faked at the HTTP layer.
 */
final class CodGatewayTest extends TestCase
{
    // book() persists the shipment on BOTH the success and failure branch, so the wire tests need
    // real rows — there is no HTTP response that reaches the request without touching the database.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shipping_service.url'     => 'https://shipping.test',
            'services.shipping_service.api_key' => 'svc-secret-key',
        ]);
    }

    /** @dataProvider gatewayProvider */
    public function test_cash_on_delivery_predicate(string|null $gateway, bool $expected): void
    {
        $this->assertSame(
            $expected,
            PaymentGatewayType::isCashOnDelivery($gateway),
            sprintf('gateway %s should %sbe treated as cash-on-delivery', var_export($gateway, true), $expected ? '' : 'NOT ')
        );
    }

    public static function gatewayProvider(): array
    {
        return [
            'canonical'          => ['CASH_ON_DELIVERY', true],
            'short form'         => ['COD', true],
            'bare cash'          => ['CASH', true],
            'lowercase'          => ['cash', true],
            'padded'             => ['  cod  ', true],
            'razorpay'           => ['RAZORPAY', false],
            'stripe'             => ['STRIPE', false],
            // Prepaid from wallet balance — must never be treated as cash at the door.
            'wallet'             => ['FULL_WALLET_PAYMENT', false],
            'empty'              => ['', false],
            'null'               => [null, false],
        ];
    }

    /**
     * The regression that matters: a CASH order must reach the shipping service with cod=true.
     *
     * cod_amount is set on the shipment so shipmentCodAmount() short-circuits before it touches the
     * items relation, which keeps this free of the database.
     */
    public function test_a_cash_order_is_booked_as_cod_on_the_wire(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'partner_code' => 'borzo', 'provider_order_id' => 'X1'], 200)]);

        $this->bookShipmentWithGateway('CASH');

        Http::assertSent(function ($request) {
            $this->assertTrue($request['cod'], 'a CASH order must be booked as COD — the rider has to collect');
            $this->assertSame(500.0, (float) $request['cod_amount']);

            return true;
        });
    }

    public function test_a_prepaid_order_is_not_booked_as_cod(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'partner_code' => 'porter', 'provider_order_id' => 'CRN1'], 200)]);

        $this->bookShipmentWithGateway('RAZORPAY');

        Http::assertSent(function ($request) {
            $this->assertFalse($request['cod'], 'a prepaid order must never ask the rider to collect');
            $this->assertSame(0, $request['cod_amount']);

            return true;
        });
    }

    /**
     * Porter documents apartment_address / street_address2 / landmark and the shipping service
     * forwards them, but the monolith never populated them — so on every real order the rider got a
     * street and a pin and nothing else. Only the debug console could send them, which made the
     * integration look complete when it was not.
     */
    public function test_address_detail_reaches_the_shipping_service(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'partner_code' => 'porter', 'provider_order_id' => 'CRN1'], 200)]);

        $this->bookShipmentWithGateway('RAZORPAY', dropDetail: [
            'apartment' => 'Flat 402',
            'line2'     => '16th Main, BTM Layout',
            'landmark'  => 'Opposite the water tank',
        ]);

        Http::assertSent(function ($request) {
            $drop = $request['drop'] ?? [];
            $this->assertSame('Flat 402', $drop['apartment'] ?? null, 'apartment must reach the service');
            $this->assertSame('16th Main, BTM Layout', $drop['line2'] ?? null, 'line2 must reach the service');
            $this->assertSame('Opposite the water tank', $drop['landmark'] ?? null, 'landmark must reach the service');

            return true;
        });
    }

    /** Blank detail is omitted entirely rather than sent as empty strings. */
    public function test_blank_address_detail_is_omitted_not_sent_empty(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'partner_code' => 'porter', 'provider_order_id' => 'CRN2'], 200)]);

        $this->bookShipmentWithGateway('RAZORPAY');

        Http::assertSent(function ($request) {
            foreach (['apartment', 'line2', 'landmark'] as $k) {
                $this->assertArrayNotHasKey($k, $request['drop'] ?? [], "blank {$k} should be absent, not empty");
            }

            return true;
        });
    }

    /** Builds a same-city shipment for the given gateway and books it through the real service. */
    private function bookShipmentWithGateway(string $gateway, array $dropDetail = []): void
    {
        // The master switch lives in settings.options.courier.enabled; stub the resolved value so
        // the test exercises the COD decision rather than the settings lookup.
        $service = new class extends CourierService {
            public function shippingServiceEnabled(): bool
            {
                return true;
            }
        };

        $userId = DB::table('users')->insertGetId([
            'name' => 'Pilot Owner', 'email' => 'pilot-' . uniqid() . '@example.test', 'password' => 'x',
        ]);
        $shopId = DB::table('shops')->insertGetId([
            'owner_id' => $userId,
            'name'     => 'Pilot Nursery',
            'slug'     => 'pilot-nursery-' . uniqid(),
            // A real vendor always has a contact number, and the hyperlocal lane refuses a pickup
            // without one — absent it, the booking is blocked before the COD decision under test.
            'mobile'   => '9998887777',
            // Real coordinates: addressFromShop() falls through to 0,0 when these are absent, and a
            // 0,0 pickup is a different bug than the one under test.
            'lat'      => 12.9393917,
            'lng'      => 77.6262946,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'tracking_number' => 'COD-TEST-' . uniqid(),
            'amount'          => 500,
            'paid_total'      => 500,
            'payment_gateway' => $gateway,
            'shipping_address' => json_encode([
                'street_address' => 'BTM Layout',
                'city'           => 'Bengaluru',
                'state'          => 'Karnataka',
                'zip'            => '560029',
                'location'       => ['lat' => 12.9165757, 'lng' => 77.6101163],
            ] + $dropDetail),
        ]);
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id'         => $orderId,
            'shop_id'          => $shopId,
            'fulfillment_mode' => 'local',
            'cod_amount'       => 500,
        ]);

        $service->book(Shipment::findOrFail($shipmentId));
    }
}
