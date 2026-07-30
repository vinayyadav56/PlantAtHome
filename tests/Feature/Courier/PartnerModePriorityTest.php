<?php

namespace Tests\Feature\Courier;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Marvel\Http\Controllers\CourierPartnerProxyController;
use Tests\TestCase;

/**
 * The per-mode preference chain, at the monolith's proxy boundary.
 *
 * A rank decides which partner a real dispatch goes to, so the two things worth pinning here are
 * that a rank reaches the service intact, and that a rank the service would IGNORE is refused
 * rather than reported as saved. The ordering itself is the service's job and is tested there.
 *
 * Dispatched directly like CourierPartnerProxyTest — the live routes need the full ACL schema this
 * DB-less TestCase does not have.
 */
final class PartnerModePriorityTest extends TestCase
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

    private function adminRequest(array $json): Request
    {
        $request = Request::create(
            '/courier/partners/porter/config',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($json)
        );

        $request->setUserResolver(fn () => new class {
            public function hasPermissionTo($permission): bool
            {
                return true;
            }
        });

        return $request;
    }

    private function fakeServiceOk(): void
    {
        Http::fake(['*' => Http::response([
            'code'          => 'porter',
            'environment'   => 'staging',
            'enabled'       => true,
            'mode_priority' => ['same_city' => 10],
        ], 200)]);
    }

    public function test_a_rank_reaches_the_service_as_an_integer_map(): void
    {
        $this->fakeServiceOk();

        $this->controller->update($this->adminRequest([
            'mode_priority' => ['same_city' => '10', 'courier' => 20],
        ]), 'porter');

        Http::assertSent(function ($req) {
            $this->assertSame('PUT', $req->method());
            $this->assertStringContainsString('/v1/partners/porter/config', $req->url());
            // Cast, not passed through: a form field arrives as "10", and the service's decoder
            // rejects a string where it expects an int.
            $this->assertSame(['same_city' => 10, 'courier' => 20], $req->data()['mode_priority']);
            return true;
        });
    }

    public function test_zero_is_refused_because_the_service_reads_it_as_no_preference(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);
        $this->controller->update($this->adminRequest([
            'mode_priority' => ['same_city' => 0],
        ]), 'porter');
    }

    public function test_a_negative_rank_is_refused(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);
        $this->controller->update($this->adminRequest([
            'mode_priority' => ['same_city' => -1],
        ]), 'porter');
    }

    public function test_an_empty_map_clears_every_rank(): void
    {
        $this->fakeServiceOk();

        // Un-ranking is expressed by omitting keys, so an empty map must still be FORWARDED — if it
        // were dropped as "nothing to send", a preference could never be removed once set.
        $this->controller->update($this->adminRequest(['mode_priority' => []]), 'porter');

        Http::assertSent(function ($req) {
            // Asserted on the RAW BODY, not the decoded array: an empty PHP array encodes as `[]`,
            // which the service's object decoder rejects with a 400. Only `{}` clears the ranks,
            // and the decoded form makes the two indistinguishable.
            $this->assertStringContainsString('"mode_priority":{}', $req->body());
            return true;
        });
    }

    public function test_toggles_still_save_without_any_rank(): void
    {
        $this->fakeServiceOk();

        $this->controller->update($this->adminRequest(['quote_enabled' => false]), 'porter');

        Http::assertSent(function ($req) {
            $this->assertFalse($req->data()['quote_enabled']);
            $this->assertArrayNotHasKey('mode_priority', $req->data());
            return true;
        });
    }
}
