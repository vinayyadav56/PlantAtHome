<?php

namespace Marvel\Services\DeliveryOptimizer\Quote;

use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;
use Marvel\Services\DeliveryOptimizer\Support\Rail;

/**
 * The browse-time quoter: pure arithmetic from vendor_shipping_rates
 * (base_cost + per_kg_cost × ceil(kg)), the same row-selection
 * ItemAssignmentService::shippingCost already uses. NEVER touches the network, so it is
 * the always-available fallback when a firm quote times out or the shipping-service is down.
 */
final class EstimatedRateQuoter
{
    public function quote(QuoteRequest $req): QuoteResult
    {
        $kg = (int) ceil(max(1, $req->chargeableWeightG) / 1000);
        $fee = $req->baseCost + ($req->perKgCost * $kg);
        return new QuoteResult($fee, $this->defaultEta($req->rail), QuoteResult::ESTIMATE);
    }

    private function defaultEta(string $rail): int
    {
        return $rail === Rail::COURIER ? 5 : 2;
    }
}
