<?php

namespace Tests\Unit\DeliveryOptimizer\Fakes;

use Marvel\Services\DeliveryOptimizer\Contracts\QuoteCacheInterface;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/** In-memory QuoteCache for unit tests. */
final class FakeQuoteCache implements QuoteCacheInterface
{
    /** @var array<string, QuoteResult> */
    public array $store = [];

    public function get(string $key): ?QuoteResult
    {
        return $this->store[$key] ?? null;
    }

    public function put(string $key, QuoteResult $result, int $ttlSeconds): void
    {
        $this->store[$key] = $result;
    }

    public function many(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (isset($this->store[$k])) {
                $out[$k] = $this->store[$k];
            }
        }
        return $out;
    }
}
