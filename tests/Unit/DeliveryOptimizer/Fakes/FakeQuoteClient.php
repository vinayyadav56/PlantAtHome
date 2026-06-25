<?php

namespace Tests\Unit\DeliveryOptimizer\Fakes;

use Marvel\Services\DeliveryOptimizer\Contracts\ShippingQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/**
 * Prices a leg with the SAME arithmetic as CostModel (base + per-kg × ceil(kg)), so the
 * orchestrator's reported fees agree with the search. `source` is configurable to assert
 * quote_source propagation.
 */
final class FakeQuoteClient implements ShippingQuoteClientInterface
{
    public function __construct(private string $source = QuoteResult::ESTIMATE)
    {
    }

    public function quote(QuoteRequest $r): QuoteResult
    {
        $kg = (int) ceil(max(1, $r->chargeableWeightG) / 1000);
        return new QuoteResult($r->baseCost + ($r->perKgCost * $kg), 0, $this->source);
    }

    public function quoteMany(array $reqs): array
    {
        $out = [];
        foreach ($reqs as $r) {
            $out[$r->legKey()] = $this->quote($r);
        }
        return $out;
    }
}
