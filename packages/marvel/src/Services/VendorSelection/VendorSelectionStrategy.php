<?php

namespace Marvel\Services\VendorSelection;

/**
 * Strategy for choosing WHICH vendor fulfils an order line. Candidates are the
 * already-filtered + enriched vendor rows from ItemAssignmentService::candidatesFor
 * (each: shop_id, vendor_rate, selling_price, fulfillment_mode, sla_days, eta_days,
 * rating, priority, pincode_covered, shipping_cost, score). The strategy only RANKS
 * / PICKS — the hard filters (stock, city coverage) already ran. Swap strategies via
 * settings.options.assignment.strategy without touching the assignment pipeline.
 */
interface VendorSelectionStrategy
{
    /** Stable config key (e.g. 'cheapest', 'nearest', 'priority', 'rating', 'round_robin'). */
    public function key(): string;

    /**
     * Return the candidates re-ordered best-first for this strategy (non-destructive).
     *
     * @param array $candidates
     * @param array $context  ['product_id'=>int, 'variation_option_id'=>?int, 'qty'=>int, 'city'=>?string, 'pincode'=>?string]
     * @return array
     */
    public function rank(array $candidates, array $context): array;

    /** The single chosen vendor candidate, or null when none. */
    public function select(array $candidates, array $context): ?array;
}
