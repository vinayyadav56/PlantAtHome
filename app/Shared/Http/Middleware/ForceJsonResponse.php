<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces JSON content negotiation for /api/v1 requests: sets Accept to
 * application/json so the framework treats every request as an API call. Without
 * this, a client that omits the Accept header gets HTML/redirect behaviour on
 * errors (e.g. a validation failure 302-redirects) instead of the JSON envelope.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
