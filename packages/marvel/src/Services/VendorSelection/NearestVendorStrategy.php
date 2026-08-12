<?php

namespace Marvel\Services\VendorSelection;

/**
 * Prefer the vendor physically closest to the customer: real straight-line distance
 * when both points are known, then exact pincode coverage, then the shortest promised
 * delivery (sla/eta), then cheaper shipping. LOCAL tier still wins overall (see
 * AbstractTieredStrategy).
 *
 * `distance_km` only arrives when the caller passes the delivery pin AND the vendor has
 * been pinned; until this cycle no candidate carried it at all, so "nearest" silently
 * meant "best pincode coverage, then fastest SLA" — a proxy, not proximity. The proxy
 * remains the fallback for unpinned vendors rather than dropping them to last.
 */
class NearestVendorStrategy extends AbstractTieredStrategy
{
    public function key(): string
    {
        return 'nearest';
    }

    protected function compareWithinTier(array $a, array $b, array $context): int
    {
        $da = $a['distance_km'] ?? null;
        $db = $b['distance_km'] ?? null;
        // Only decide on distance when BOTH are known — comparing a real distance
        // against a missing one would rank on who happens to have been pinned.
        if (is_numeric($da) && is_numeric($db) && (float) $da !== (float) $db) {
            return (float) $da <=> (float) $db;
        }

        $pa = !empty($a['pincode_covered']) ? 0 : 1;
        $pb = !empty($b['pincode_covered']) ? 0 : 1;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $sa = (int) ($a['sla_days'] ?? $a['eta_days'] ?? 99);
        $sb = (int) ($b['sla_days'] ?? $b['eta_days'] ?? 99);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        return (float) ($a['shipping_cost'] ?? INF) <=> (float) ($b['shipping_cost'] ?? INF);
    }
}
