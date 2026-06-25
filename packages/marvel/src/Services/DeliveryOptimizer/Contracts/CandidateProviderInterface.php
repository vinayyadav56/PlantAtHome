<?php

namespace Marvel\Services\DeliveryOptimizer\Contracts;

use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;

/**
 * Phase A — candidate generation. Today this wraps ItemAssignmentService; behind this
 * seam it can become an RPC call to a Go/Python worker without changing callers.
 */
interface CandidateProviderInterface
{
    /**
     * Batch pre-load everything Phase A needs for the WHOLE cart in one shot
     * (shops/areas/rates + the per-city serviceability gate + platform flags), so the
     * per-line candidate calls do O(1) memo reads instead of re-running the gate N times.
     *
     * @param CartItem[] $cartItems
     */
    public function warm(array $cartItems, UserLocation $loc): void;

    /**
     * Top-K ranked candidates for one item. Returns [] when nobody can fulfil the line
     * at this city/pincode/qty (an unfulfillable item).
     *
     * @return \Marvel\Services\DeliveryOptimizer\Dto\Candidate[]
     */
    public function candidates(CartItem $item, UserLocation $loc, int $k = 5): array;
}
