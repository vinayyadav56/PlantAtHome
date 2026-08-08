<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * An unauthenticated request to an auth-guarded route must return 401, not 500.
 *
 * The old App\Http\Middleware\Authenticate::redirectTo() called route('login') whenever the
 * client did not send Accept: application/json. There is no `login` route, so it threw
 * RouteNotFoundException — evaluated as a constructor argument before AuthenticationException
 * was built — and the request fell through to a generic HTTP 500 ({"message":"Server Error."})
 * instead of the 401 that Handler::unauthenticated() would have rendered.
 *
 * The regression only showed for clients that DON'T advertise JSON (a plain browser, curl with
 * no Accept header, monitoring). Tests using getJson()/postJson() always sent Accept:
 * application/json, so they saw the correct 401 and never caught this — which is exactly why
 * these cases assert with an explicit non-JSON Accept header.
 */
final class UnauthenticatedReturns401Test extends TestCase
{
    /** A protected route with NO Accept header — the exact shape that used to 500. */
    public function test_no_accept_header_yields_401_not_500(): void
    {
        $response = $this->get('/api/me', ['Accept' => 'text/html']);

        $response->assertStatus(401);
        $this->assertNotEquals(500, $response->getStatusCode(), 'auth failure must never surface as a 500');
    }

    /** A JSON client keeps getting a clean 401 too (no regression on the path that worked). */
    public function test_json_client_still_gets_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_wildcard_accept_yields_401(): void
    {
        // Browsers send Accept: * / * ; that must not route into the login-redirect crash either.
        $this->get('/api/me', ['Accept' => '*/*'])->assertStatus(401);
    }
}
