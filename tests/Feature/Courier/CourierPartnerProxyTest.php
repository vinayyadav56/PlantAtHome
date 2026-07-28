<?php

namespace Tests\Feature\Courier;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Marvel\Http\Controllers\CourierPartnerProxyController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Super-admin proxy to the Go shipping-service partner DEBUG CONSOLE:
 * courier/partners/{code}/webhooks + /test/{quote,track,book,cancel}.
 *
 * The live routes sit behind permission:SUPER_ADMIN + auth:sanctum, which needs the full
 * marvel/spatie ACL schema — absent from this DB-less TestCase — so the controller is dispatched
 * DIRECTLY (real Request objects, the exact instance the routes resolve to) with a super-admin user
 * resolver, exactly as CoverageApiTest does. The Go service is faked at the HTTP layer, and the
 * route table is asserted separately for the throttles.
 *
 * The load-bearing guarantee proven here: `confirm` is the CALLER's string, forwarded byte-for-byte
 * and never invented by the monolith — the destructive guard belongs to the service alone.
 */
final class CourierPartnerProxyTest extends TestCase
{
    private CourierPartnerProxyController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shipping_service.url'     => 'https://shipping.test',
            'services.shipping_service.api_key' => 'svc-secret-key',
            'services.shipping_service.timeout' => 5,
        ]);

        $this->controller = new CourierPartnerProxyController();
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    /** A Request whose user() is a super admin (no ACL tables needed). */
    private function adminRequest(string $method, string $uri, array $query = [], ?array $json = null): Request
    {
        $request = $json === null
            ? Request::create($uri, $method, $query)
            : Request::create($uri, $method, $query, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($json));

        $request->setUserResolver(fn () => new class {
            public function hasPermissionTo($permission): bool
            {
                return true;
            }
        });

        return $request;
    }

    private function payload($response): array
    {
        return json_decode($response->getContent(), true);
    }

    /** The service's console envelope: partner failures are 200 + ok:false so the UI can show them. */
    private function envelope(array $extra = []): array
    {
        return array_merge([
            'ok'           => true,
            'partner_code' => 'porter',
            'exchange'     => [
                'request'  => [
                    'method'  => 'POST',
                    'url'     => 'https://pfe.porter.in/v1/get_quote',
                    'headers' => ['X-API-KEY' => 'hp_****1234'],
                    'body'    => '{}',
                ],
                'response'    => ['status' => 200, 'headers' => [], 'body' => '{}'],
                'duration_ms' => 123,
            ],
        ], $extra);
    }

    /* ── allow-list ───────────────────────────────────────────────────── */

    public function test_unknown_partner_code_404s_before_any_upstream_call(): void
    {
        Http::fake();

        foreach (['webhooks', 'testQuote', 'testTrack', 'testBook', 'testCancel'] as $action) {
            // A body that would ALSO fail validation — the 404 must win, proving the allow-list
            // runs before anything else can reach the wire.
            $request = $this->adminRequest('POST', '/courier/partners/dhl/x', [], []);
            try {
                $this->controller->{$action}($request, 'dhl');
                $this->fail("Expected a 404 for an unknown partner from {$action}()");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode(), $action);
            }
        }

        Http::assertNothingSent();
    }

    public function test_allow_list_is_case_insensitive_for_the_three_real_partners(): void
    {
        Http::fake(['*' => Http::response(['partner_code' => 'borzo', 'items' => [], 'next_before' => null], 200)]);

        $res = $this->controller->webhooks($this->adminRequest('GET', '/x'), 'BORZO');

        $this->assertSame('borzo', $this->payload($res)['partner_code']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/partners/borzo/webhooks'));
    }

    /* ── pass-through ─────────────────────────────────────────────────── */

    public function test_webhooks_passes_paging_through_and_returns_the_service_body_verbatim(): void
    {
        $body = [
            'partner_code' => 'porter',
            'items'        => [[
                'id'              => 123,
                'event_type'      => 'order_status',
                'signature_valid' => true,
                'processed'       => true,
                'error'           => null,
                'received_at'     => '2026-07-28T10:00:00Z',
                'http_status'     => 200,
                'raw_headers'     => ['X-Porter-Signature' => 'abc'],
                'raw_body'        => '{"status":"delivered"}',
            ]],
            'next_before' => 100,
        ];
        Http::fake(['*' => Http::response($body, 200)]);

        $res = $this->controller->webhooks(
            $this->adminRequest('GET', '/courier/partners/porter/webhooks', ['limit' => 50, 'before' => 200]),
            'porter'
        );

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($body, $this->payload($res)); // byte-for-byte, incl. next_before
        Http::assertSent(function ($req) {
            $this->assertSame('GET', $req->method());
            $this->assertStringContainsString('/v1/partners/porter/webhooks', $req->url());
            $this->assertStringContainsString('limit=50', $req->url());
            $this->assertStringContainsString('before=200', $req->url());
            $this->assertSame('svc-secret-key', $req->header('X-Api-Key')[0]);
            return true;
        });
    }

    public function test_test_quote_forwards_the_normalised_leg_payload_and_returns_the_exchange(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['quote' => ['amount_paise' => 9900]]), 200)]);

        $res = $this->controller->testQuote($this->adminRequest('POST', '/x', [], [
            'pickup'           => ['lat' => 28.4595, 'lng' => 77.0266, 'address' => 'Sector 44', 'pincode' => '122003'],
            'drop'             => ['lat' => 28.5355, 'lng' => 77.3910, 'address' => 'Noida',     'pincode' => '201301'],
            'weight_grams'     => 1000,
            'cod_amount_paise' => 0,
        ]), 'porter');

        $payload = $this->payload($res);
        $this->assertTrue($payload['ok']);
        $this->assertSame(9900, $payload['quote']['amount_paise']);
        // The masked credential from the service survives the hop untouched — and is still masked.
        $this->assertSame('hp_****1234', $payload['exchange']['request']['headers']['X-API-KEY']);
        $this->assertSame(123, $payload['exchange']['duration_ms']);

        Http::assertSent(function ($req) {
            $this->assertSame('POST', $req->method());
            $this->assertStringContainsString('/v1/partners/porter/test/quote', $req->url());
            $sent = $req->data();
            $this->assertEqualsWithDelta(28.4595, $sent['pickup']['lat'], 0.00001);
            $this->assertSame('201301', $sent['drop']['pincode']);
            $this->assertSame(1000, $sent['weight_grams']);
            $this->assertSame(0, $sent['cod_amount_paise']);
            $this->assertArrayNotHasKey('confirm', $sent); // read-only call: nothing to confirm
            return true;
        });
    }

    public function test_partner_failure_arrives_as_200_ok_false_so_the_console_can_show_it(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['ok' => false, 'error' => 'porter: 401 unauthorized']), 200)]);

        $res = $this->controller->testTrack(
            $this->adminRequest('GET', '/x', ['provider_order_id' => 'CRN-9']),
            'porter'
        );

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($this->payload($res)['ok']);
        $this->assertSame('porter: 401 unauthorized', $this->payload($res)['error']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/partners/porter/test/track')
            && str_contains($req->url(), 'provider_order_id=CRN-9'));
    }

    /* ── the confirmation guard belongs to the service ────────────────── */

    public function test_book_forwards_the_callers_confirm_string_verbatim(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['provider_order_id' => 'CRN-77']), 200)]);

        $res = $this->controller->testBook($this->adminRequest('POST', '/x', [], [
            'pickup'  => ['lat' => 28.4595, 'lng' => 77.0266],
            'drop'    => ['lat' => 28.5355, 'lng' => 77.3910],
            'confirm' => 'CREATE-REAL-ORDER',
        ]), 'porter');

        $this->assertSame('CRN-77', $this->payload($res)['provider_order_id']);
        Http::assertSent(fn ($req) => $req->data()['confirm'] === 'CREATE-REAL-ORDER');
    }

    /**
     * The monolith must never be able to satisfy the guard on its own: a missing confirm goes
     * upstream MISSING (not defaulted), and a wrong one goes upstream WRONG (not corrected). The
     * service's 400 is then handed back with its own status so the console shows the real reason.
     */
    public function test_book_never_injects_or_repairs_the_confirmation(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'confirmation required'], 400)]);

        $base = [
            'pickup' => ['lat' => 28.4595, 'lng' => 77.0266],
            'drop'   => ['lat' => 28.5355, 'lng' => 77.3910],
        ];

        // (a) omitted entirely → the key must not appear upstream at all
        $res = $this->controller->testBook($this->adminRequest('POST', '/x', [], $base), 'porter');
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('confirmation required', $this->payload($res)['error']);
        Http::assertSent(fn ($req) => !array_key_exists('confirm', $req->data()));

        // (b) wrong/typo'd → forwarded exactly as typed, never normalised to the real literal
        $this->controller->testBook(
            $this->adminRequest('POST', '/x', [], $base + ['confirm' => 'create-real-order']),
            'porter'
        );
        Http::assertSent(fn ($req) => ($req->data()['confirm'] ?? null) === 'create-real-order');

        // (c) empty string → still forwarded as an empty string, never dropped-and-defaulted
        $this->controller->testBook(
            $this->adminRequest('POST', '/x', [], $base + ['confirm' => '']),
            'porter'
        );
        Http::assertSent(fn ($req) => ($req->data()['confirm'] ?? null) === '');

        // The guard literal must not live in the monolith at all — grep the shipped source.
        $sources = [
            __DIR__ . '/../../../packages/marvel/src/Http/Controllers/CourierPartnerProxyController.php',
            __DIR__ . '/../../../packages/marvel/src/Services/Courier/ShippingServiceClient.php',
        ];
        foreach ($sources as $file) {
            $code = file_get_contents($file);
            $this->assertStringNotContainsString('CREATE-REAL-ORDER', $code, basename($file));
            $this->assertStringNotContainsString('CANCEL-REAL-ORDER', $code, basename($file));
        }
    }

    public function test_cancel_forwards_provider_order_id_and_the_callers_confirm(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['ok' => true]), 200)]);

        $this->controller->testCancel($this->adminRequest('POST', '/x', [], [
            'provider_order_id' => 'CRN-77',
            'confirm'           => 'CANCEL-REAL-ORDER',
        ]), 'shiprocket');

        Http::assertSent(function ($req) {
            $this->assertStringContainsString('/v1/partners/shiprocket/test/cancel', $req->url());
            $this->assertSame('CRN-77', $req->data()['provider_order_id']);
            $this->assertSame('CANCEL-REAL-ORDER', $req->data()['confirm']);
            return true;
        });
    }

    /* ── error mapping ────────────────────────────────────────────────── */

    public function test_unconfigured_service_503s_without_touching_the_wire(): void
    {
        config(['services.shipping_service.url' => '', 'services.shipping_service.api_key' => '']);
        Http::fake();

        try {
            $this->controller->webhooks($this->adminRequest('GET', '/x'), 'porter');
            $this->fail('Expected a 503 when the shipping service is not configured');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_upstream_5xx_becomes_502(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        try {
            $this->controller->webhooks($this->adminRequest('GET', '/x'), 'porter');
            $this->fail('Expected a 502 for an upstream 5xx');
        } catch (HttpException $e) {
            $this->assertSame(502, $e->getStatusCode());
        }
    }

    public function test_upstream_401_becomes_502_and_never_reaches_the_browser(): void
    {
        // A rejected X-Api-Key is OUR infra fault; a 401 relayed to the admin app reads as a dead
        // session and logs the operator out.
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        try {
            $this->controller->webhooks($this->adminRequest('GET', '/x'), 'porter');
            $this->fail('Expected a 502 for an upstream 401');
        } catch (HttpException $e) {
            $this->assertSame(502, $e->getStatusCode());
        }
    }

    /* ── routing ──────────────────────────────────────────────────────── */

    public function test_console_routes_are_registered_super_admin_only_and_throttled(): void
    {
        $expected = [
            'GET|courier/partners/{code}/webhooks'    => 'throttle:60,1',
            'POST|courier/partners/{code}/test/quote' => 'throttle:20,1',
            'GET|courier/partners/{code}/test/track'  => 'throttle:20,1',
            'POST|courier/partners/{code}/test/book'  => 'throttle:5,1',
            'POST|courier/partners/{code}/test/cancel' => 'throttle:5,1',
        ];

        foreach ($expected as $key => $throttle) {
            [$method, $uri] = explode('|', $key);
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === 'api/' . $uri && in_array($method, $r->methods(), true)
            );
            $this->assertNotNull($route, $key . ' is not registered');

            $middleware = $route->gatherMiddleware();
            $this->assertContains($throttle, $middleware, $key . ' throttle');
            $this->assertContains('auth:sanctum', $middleware, $key . ' auth');
            $this->assertContains('permission:super_admin', $middleware, $key . ' permission');
        }
    }
}
