<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Where to redirect an unauthenticated user.
     *
     * This is a JSON API with no `login` route. The old body called `route('login')` whenever
     * the client did not send `Accept: application/json` — that throws RouteNotFoundException,
     * which is evaluated as a constructor ARGUMENT before AuthenticationException is even built,
     * so the framework never reaches Handler::unauthenticated() (which correctly returns 401).
     * The request fell through to a generic HTTP 500 (`{"message":"Server Error."}`) instead.
     *
     * Returning null unconditionally means "no redirect" — the AuthenticationException is thrown
     * cleanly and Handler::unauthenticated() renders a proper 401 for every auth-guarded route,
     * regardless of the client's Accept header. There is nowhere to redirect a browser to anyway.
     */
    protected function redirectTo($request): ?string
    {
        return null;
    }
}
