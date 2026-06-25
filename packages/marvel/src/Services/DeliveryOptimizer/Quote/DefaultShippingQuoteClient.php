<?php

namespace Marvel\Services\DeliveryOptimizer\Quote;

use Marvel\Services\DeliveryOptimizer\Contracts\FirmQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\QuoteCacheInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\ShippingQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;
use Marvel\Services\DeliveryOptimizer\Support\Rail;

/**
 * The estimate-first-with-firm-at-checkout policy, in one place:
 *
 *   1. A cached FIRM quote always wins (browse or checkout) — a real carrier price, free.
 *   2. Browse (firm=false, firmQuotesAtBrowse off) → estimate from rates. Zero network.
 *   3. Checkout (firm=true) → live firm quote for the cache miss; write it back so the
 *      next browse is firm-cached; on timeout/error fall back to the estimate.
 *
 * This is what keeps the hot path off Porter/Shiprocket on every cart-add while still
 * charging a real rate at checkout.
 */
final class DefaultShippingQuoteClient implements ShippingQuoteClientInterface
{
    public function __construct(
        private QuoteCacheInterface $cache,
        private EstimatedRateQuoter $estimator,
        private FirmQuoteClientInterface $firm,
        private OptimizerConfigInterface $config,
    ) {
    }

    public function quote(QuoteRequest $req): QuoteResult
    {
        return $this->quoteMany([$req])[$req->legKey()] ?? $this->estimator->quote($req);
    }

    public function quoteMany(array $reqs): array
    {
        $out = [];
        $needFirm = [];

        // First pass: serve cached-firm and estimates without any network.
        $cacheHits = $this->cache->many(array_map(fn (QuoteRequest $r) => $r->cacheKey(), $reqs));
        foreach ($reqs as $r) {
            $cached = $cacheHits[$r->cacheKey()] ?? null;
            if ($cached !== null && $cached->isFirm()) {
                $out[$r->legKey()] = $cached;
                continue;
            }
            if (!($r->firm || $this->config->firmQuotesAtBrowse())) {
                $out[$r->legKey()] = $this->estimator->quote($r); // browse → estimate
                continue;
            }
            $needFirm[] = $r;
        }

        // Second pass: one firm batch for the cache misses on the firm path.
        if (!empty($needFirm)) {
            $firmResults = [];
            try {
                $firmResults = $this->firm->quoteMany($needFirm);
            } catch (\Throwable $e) {
                $firmResults = [];
            }
            foreach ($needFirm as $r) {
                $fr = $firmResults[$r->legKey()] ?? null;
                if ($fr !== null) {
                    $this->cache->put($r->cacheKey(), $fr, $this->ttlFor($r->rail));
                    $out[$r->legKey()] = $fr;
                } else {
                    $out[$r->legKey()] = $this->estimator->quote($r); // firm failed → estimate, flagged
                }
            }
        }

        return $out;
    }

    private function ttlFor(string $rail): int
    {
        return $rail === Rail::COURIER ? $this->config->courierTtl() : $this->config->instantTtl();
    }
}
