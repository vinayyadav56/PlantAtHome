<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

/**
 * The priced result of a single leg quote — a flat fee, an ETA, and the provenance
 * (`firm` from the carrier vs `estimate` from vendor_shipping_rates). `source` is what
 * surfaces in OptimizationResult.quote_source so the storefront/ops can tell a firm
 * checkout price from a browse estimate.
 */
final class QuoteResult
{
    public const FIRM = 'firm';
    public const ESTIMATE = 'estimate';

    public function __construct(
        public readonly float $fee,
        public readonly int $etaDays,
        public readonly string $source = self::ESTIMATE,
        public readonly ?string $partner = null,
    ) {
    }

    public function isFirm(): bool
    {
        return $this->source === self::FIRM;
    }
}
