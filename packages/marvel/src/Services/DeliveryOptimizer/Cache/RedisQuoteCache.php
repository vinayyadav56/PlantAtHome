<?php

namespace Marvel\Services\DeliveryOptimizer\Cache;

use Illuminate\Support\Facades\Cache;
use Marvel\Services\DeliveryOptimizer\Contracts\QuoteCacheInterface;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/**
 * Bucketed quote cache over the Laravel Cache facade. Version-namespaced (no tags) so it
 * is driver-agnostic — works on the file driver in dev and Redis in prod — and bustable
 * in O(1) by bumping `deliveryoptimizer:ver`. Only FIRM quotes are stored (estimates are
 * free arithmetic), so a cache hit is always a real carrier price.
 */
final class RedisQuoteCache implements QuoteCacheInterface
{
    public function get(string $key): ?QuoteResult
    {
        $raw = Cache::get($this->versioned($key));
        return is_array($raw) ? $this->hydrate($raw) : null;
    }

    public function put(string $key, QuoteResult $result, int $ttlSeconds): void
    {
        Cache::put($this->versioned($key), $this->dehydrate($result), max(1, $ttlSeconds));
    }

    public function many(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $versionedToOriginal = [];
        foreach ($keys as $k) {
            $versionedToOriginal[$this->versioned($k)] = $k;
        }
        $raw = Cache::many(array_keys($versionedToOriginal));
        $out = [];
        foreach ($raw as $vk => $val) {
            if (is_array($val)) {
                $out[$versionedToOriginal[$vk]] = $this->hydrate($val);
            }
        }
        return $out;
    }

    private function versioned(string $key): string
    {
        $ver = (int) Cache::get('deliveryoptimizer:ver', 1);
        return 'do:v' . $ver . ':' . $key;
    }

    private function dehydrate(QuoteResult $r): array
    {
        return ['f' => $r->fee, 'e' => $r->etaDays, 's' => $r->source, 'p' => $r->partner];
    }

    private function hydrate(array $a): QuoteResult
    {
        return new QuoteResult(
            (float) ($a['f'] ?? 0),
            (int) ($a['e'] ?? 0),
            (string) ($a['s'] ?? QuoteResult::ESTIMATE),
            $a['p'] ?? null,
        );
    }
}
