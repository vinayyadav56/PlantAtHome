<?php

namespace Marvel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Adds baseline security response headers to every /api response (the origin
 * was returning none). Conservative set for a JSON API — no CSP (which belongs
 * on the Next.js admin/shop apps) so nothing here can break API clients.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options'    => 'nosniff',
            'X-Frame-Options'           => 'DENY',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
            'X-XSS-Protection'          => '0',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ];
        foreach ($headers as $key => $value) {
            if (!$response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
