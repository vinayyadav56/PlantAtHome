<?php

namespace Marvel\Services\DeliveryOptimizer\Contracts;

use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/**
 * Prices consolidated legs. Implementations decide estimate-vs-firm:
 *  - firm=false (browse) -> cached firm quote if present, else estimate from rates.
 *  - firm=true  (checkout) -> cached/live firm quote, falling back to estimate on
 *    timeout/error so the optimizer NEVER blocks the user.
 *
 * Behind this seam the firm path can move to a Go/Python worker unchanged.
 */
interface ShippingQuoteClientInterface
{
    public function quote(QuoteRequest $req): QuoteResult;

    /**
     * Price many legs at once (firm path uses a batch endpoint / Http::pool; estimate
     * path is pure arithmetic). Result is keyed by each request's leg key "vendorId|rail".
     *
     * @param QuoteRequest[] $reqs
     * @return array<string, QuoteResult>
     */
    public function quoteMany(array $reqs): array;
}
