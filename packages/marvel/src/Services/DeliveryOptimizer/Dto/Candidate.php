<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

use Marvel\Services\DeliveryOptimizer\Support\Rail;

/**
 * One way to fulfil one item: a (vendor, rail) option carrying the vendor's price,
 * SLA, and the shipping-rate primitives (base + per-kg) used to estimate leg fees.
 * Produced by the CandidateProvider from ItemAssignmentService::candidatesFor.
 *
 * The (vendorId, rail) pair is the consolidation key — two items whose chosen
 * candidates share a legKey() ride one delivery fee.
 */
final class Candidate
{
    public function __construct(
        public readonly string $itemKey,
        public readonly int $vendorId,            // shops.id
        public readonly string $rail,             // Rail::INSTANT | Rail::COURIER
        public readonly string $fulfillmentMode,  // local | courier (raw, for quote payloads)
        public readonly int $etaDays,
        public readonly int $slaDays,
        public readonly ?float $sellingPrice,
        public readonly float $baseCost,          // vendor_shipping_rates.base_cost (flat, per leg)
        public readonly float $perKgCost,         // vendor_shipping_rates.per_kg_cost (weight bump)
        public readonly float $score,
        public readonly bool $recommended,
    ) {
    }

    /** Identity of the shipment leg this candidate would join/open. */
    public function legKey(): string
    {
        return $this->vendorId . '|' . $this->rail;
    }

    /**
     * Build from one raw ItemAssignmentService candidate row, attaching the per-kg
     * rate (which candidatesFor does not surface) supplied by the provider.
     */
    public static function fromRaw(string $itemKey, array $c, float $perKgCost): self
    {
        $mode = (string) ($c['fulfillment_mode'] ?? 'local');
        return new self(
            $itemKey,
            (int) ($c['shop_id'] ?? 0),
            Rail::fromFulfillmentMode($mode),
            $mode,
            (int) ($c['eta_days'] ?? 0),
            (int) ($c['sla_days'] ?? ($c['eta_days'] ?? 0)),
            isset($c['selling_price']) && $c['selling_price'] !== null ? (float) $c['selling_price'] : null,
            (float) ($c['shipping_cost'] ?? 0),
            $perKgCost,
            (float) ($c['score'] ?? 0),
            (bool) ($c['recommended'] ?? false),
        );
    }
}
