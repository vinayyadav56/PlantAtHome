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

    /**
     * Cache::remember with stampede protection.
     *
     * A plain Cache::remember lets EVERY concurrent request that arrives during a cold/expired
     * key run the full closure (here: a heavy product query + serialize). Under load that is a
     * cache stampede — the mechanism behind the observed multi-second /api/products tail, where
     * many simultaneous misses each hammered the DB at once. This serialises the rebuild: the
     * first miss takes a short lock and repopulates while the others wait briefly and then read
     * the fresh value.
     *
     * Deliberately defensive so it is correct on every cache store:
     *  - warm key  → a single get(), no lock taken (the overwhelming common case);
     *  - lock unsupported / lock table absent / lock timeout → fall back to computing once
     *    directly, so a request never fails just because locking is unavailable.
     */
    protected function rememberWithLock(string $key, int $ttl, \Closure $callback)
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $lock = Cache::lock($key . ':lock', 10);
        } catch (\Throwable $e) {
            // Store has no lock provider — behave like a plain remember.
            return Cache::remember($key, $ttl, $callback);
        }

        try {
            // Wait up to 5s for whichever request is already rebuilding.
            $lock->block(5);

            // It may have finished while we waited.
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            $value = $callback();
            Cache::put($key, $value, $ttl);
            return $value;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Nobody released in time — compute once ourselves rather than block the request.
            return $callback();
        } finally {
            optional($lock)->release();
        }
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
