<?php

namespace Marvel\Services\DeliveryOptimizer\Cache;

use Marvel\Services\DeliveryOptimizer\PhaseB\PlanState;

/**
 * Holds the live assignment state for a cart so add/remove can recompute incrementally
 * instead of re-solving from scratch.
 *
 * Scope note: this is an in-process (per-request) memo, which is exactly right for the
 * CURRENT flow — the cart is server-seen only at /checkout/estimate and checkout, both
 * single requests. The deferred browse fleet (50–80k events/s across stateless workers)
 * is where this would swap to a Redis-backed store keyed by cart hash; the interface here
 * is the seam for that, with no caller change. PlanState is pure data, so a Redis impl is
 * a serialize()/unserialize() away.
 */
final class CartMemoStore
{
    /** @var array<string, PlanState> */
    private array $store = [];

    public function get(string $cartHash): ?PlanState
    {
        return $this->store[$cartHash] ?? null;
    }

    public function put(string $cartHash, PlanState $state): void
    {
        $this->store[$cartHash] = $state;
    }

    public function forget(string $cartHash): void
    {
        unset($this->store[$cartHash]);
    }

    /** Stable hash of a cart's item identities + quantities (order-independent). */
    public static function hashOf(array $itemKeysWithQty): string
    {
        ksort($itemKeysWithQty);
        return md5(json_encode($itemKeysWithQty));
    }
}
