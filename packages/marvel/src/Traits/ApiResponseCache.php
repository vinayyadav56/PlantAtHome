<?php

namespace Marvel\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Helpers for caching public (anonymous) storefront read endpoints.
 *
 * Two layers, mirroring what CategoryController already does:
 *  - a version-keyed server cache (busted on writes), so even an edge-cache
 *    miss / on-demand SSR is served from cache instead of re-querying;
 *  - an edge-cacheable `Cache-Control: public, s-maxage` header so the Vercel
 *    CDN serves repeat storefront reads in ~50ms instead of hitting origin.
 *
 * Authenticated/admin requests (a real Bearer token) bypass both layers so
 * the dashboard always sees fresh data. The storefront client sends an empty
 * `Authorization: Bearer ` header, which leaves bearerToken() empty.
 */
trait ApiResponseCache
{
    /** Current version for a cache namespace (bumped on writes to invalidate). */
    protected function cacheVersion(string $name): int
    {
        return (int) Cache::get("{$name}:ver", 1);
    }

    /** Invalidate every entry in a cache namespace by bumping its version. */
    protected function bustResponseCache(string $name): void
    {
        Cache::forever("{$name}:ver", $this->cacheVersion($name) + 1);
    }

    /** Storefront (anonymous) reads are cacheable; admin (real Bearer) is not. */
    protected function isPublicCacheable(Request $request): bool
    {
        return empty($request->bearerToken());
    }

    /** Edge/browser cache directive (short browser TTL, longer shared TTL + SWR). */
    protected function cacheControl(int $sMaxAge = 300): string
    {
        return "public, max-age=60, s-maxage={$sMaxAge}, stale-while-revalidate=600";
    }
}
