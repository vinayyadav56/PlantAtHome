<?php

namespace Marvel\Services\DeliveryOptimizer\Contracts;

use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/**
 * The bucketed quote cache. Keys come from QuoteRequest::cacheKey(). The default impl
 * is driver-agnostic (Laravel Cache facade, version-namespaced, no tags) so it works on
 * the file driver in dev and Redis in prod.
 */
interface QuoteCacheInterface
{
    public function get(string $key): ?QuoteResult;

    public function put(string $key, QuoteResult $result, int $ttlSeconds): void;

    /**
     * Bulk read (MGET on Redis). Returns only the keys that were present.
     *
     * @param string[] $keys
     * @return array<string, QuoteResult>
     */
    public function many(array $keys): array;
}
