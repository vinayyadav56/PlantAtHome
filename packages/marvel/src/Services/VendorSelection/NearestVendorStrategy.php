<?php

namespace Marvel\Services\VendorSelection;

/**
 * Prefer the vendor physically closest to the customer: exact pincode coverage first,
 * then the shortest promised delivery (sla/eta), then cheaper shipping. LOCAL tier
 * still wins overall (see AbstractTieredStrategy).
 */
class NearestVendorStrategy extends AbstractTieredStrategy
{
    public function key(): string
    {
        return 'nearest';
    }

    protected function compareWithinTier(array $a, array $b, array $context): int
    {
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
