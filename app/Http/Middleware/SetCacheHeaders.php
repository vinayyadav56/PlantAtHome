<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\SetCacheHeaders as Base;

/**
 * Laravel's SetCacheHeaders (`cache.headers:etag`), with the ETag always WEAK.
 *
 * The proxy in front of the API (Railway's edge on staging, nginx on EC2) gzips the 200s and
 * weakens their strong ETag to W/"…" as it does. A 304 has no body, so it passes through with the
 * strong "…". A browser that cached W/"x" then revalidates and gets a 304 saying "x", and
 * RFC 9111 §4.3.4 says a cache must not update a stored response from a 304 whose strong validator
 * doesn't match it. Emitting W/ ourselves makes both answers identical, so no proxy or browser has
 * anything to disagree about. (Found while investigating the owner's iPad "network error"
 * annotations, 2026-10-09: the failures coincided with bursts of 304s.)
 *
 * Matching is unchanged: Symfony compares If-None-Match ignoring the W/ prefix on both sides.
 */
class SetCacheHeaders extends Base
{
    public function handle($request, Closure $next, $options = [])
    {
        $response = parent::handle($request, $next, $options);

        $etag = $response->headers->get('ETag');
        if (is_string($etag) && $etag !== '' && !str_starts_with($etag, 'W/')) {
            $response->headers->set('ETag', 'W/' . $etag);
        }

        return $response;
    }
}
