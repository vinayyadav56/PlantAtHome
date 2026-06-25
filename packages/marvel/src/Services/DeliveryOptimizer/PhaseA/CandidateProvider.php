<?php

namespace Marvel\Services\DeliveryOptimizer\PhaseA;

use Marvel\Services\DeliveryOptimizer\Contracts\CandidateProviderInterface;
use Marvel\Services\DeliveryOptimizer\Dto\Candidate;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use Marvel\Services\ItemAssignmentService;

/**
 * Phase A — wraps the existing ItemAssignmentService::candidatesFor (the ranked,
 * hard-filtered vendor lane) and maps each raw row into a Candidate DTO.
 *
 * Two things it adds:
 *  - warm(): one batched pre-resolve of the per-city serviceability gate + platform
 *    flags via ItemAssignmentService::warm, so the N candidate calls don't each re-run
 *    the gate (the N+1 the DESIGN flagged).
 *  - per_kg_cost: candidatesFor surfaces only the flat base_cost; we load the vendor's
 *    rate row (memoised per shop) to attach the per-kg term the cost model needs for
 *    weight-aware leg fees.
 */
final class CandidateProvider implements CandidateProviderInterface
{
    public function __construct(private ItemAssignmentService $engine)
    {
    }

    public function warm(array $cartItems, UserLocation $loc): void
    {
        $productIds = [];
        foreach ($cartItems as $item) {
            $productIds[$item->productId] = true;
        }
        if (method_exists($this->engine, 'warm')) {
            $this->engine->warm(array_keys($productIds), $loc->city);
        }
    }

    public function candidates(CartItem $item, UserLocation $loc, int $k = 5): array
    {
        $raw = $this->engine->candidatesFor(
            $item->productId,
            $item->variationOptionId,
            $item->qty,
            $loc->city,
            $loc->pincode,
        );
        if (empty($raw)) {
            return [];
        }
        $raw = array_slice($raw, 0, max(1, $k));

        $out = [];
        foreach ($raw as $c) {
            $shopId = (int) ($c['shop_id'] ?? 0);
            $mode = (string) ($c['fulfillment_mode'] ?? 'local');
            // Reuse the rate rows candidatesFor already batch-loaded (no per-shop N+1 query).
            $perKg = $this->engine->perKgCostFor($shopId, $mode);
            $out[] = Candidate::fromRaw($item->key(), $c, $perKg);
        }
        return $out;
    }
}
