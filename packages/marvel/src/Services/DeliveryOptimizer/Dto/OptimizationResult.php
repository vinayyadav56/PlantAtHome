<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

/**
 * The optimizer's output: consolidated shipments, the single flat fee the customer
 * pays, the true internal cost (NOT shown to the customer), per-shipment dates, an
 * upsell hint, and the quote provenance.
 */
final class OptimizationResult
{
    /**
     * @param Shipment[] $shipments
     * @param CartItem[] $unfulfillableItems
     * @param array{gap_to_free_delivery: float, threshold: float}|null $upsellHint
     */
    public function __construct(
        public array $shipments,
        public float $customerFlatFee,
        public float $internalTrueCost,
        public float $subtotal,
        public ?array $upsellHint,
        public string $quoteSource,
        public array $unfulfillableItems = [],
    ) {
    }

    /**
     * CUSTOMER-SAFE shape (returned on public/customer responses). Deliberately omits
     * internal_true_cost — the platform's true delivery cost / per-order subsidy — and the
     * per-shipment vendor_id/true_cost (see Shipment::toArray). Customers see consolidated
     * shipments, the single flat fee they pay, dates, the upsell hint, and the subtotal.
     */
    public function toArray(): array
    {
        return [
            'shipments'          => array_map(fn (Shipment $s) => $s->toArray(), array_values($this->shipments)),
            'customer_flat_fee'  => round($this->customerFlatFee, 2),
            'subtotal'           => round($this->subtotal, 2),
            'delivery_estimates' => array_map(fn (Shipment $s) => [
                'shipment_id'  => $s->shipmentId,
                'eta_min_date' => $s->etaMinDate,
                'eta_max_date' => $s->etaMaxDate,
            ], array_values($this->shipments)),
            'upsell_hint'        => $this->upsellHint,
            'quote_source'       => $this->quoteSource,
            'unfulfillable_items' => array_map(fn (CartItem $i) => [
                'product_id'          => $i->productId,
                'variation_option_id' => $i->variationOptionId,
                'quantity'            => $i->qty,
            ], array_values($this->unfulfillableItems)),
        ];
    }

    /** FULL shape (internal_true_cost + per-shipment vendor/cost) for admin / logging only. */
    public function toInternalArray(): array
    {
        return [
            'shipments'           => array_map(fn (Shipment $s) => $s->toInternalArray(), array_values($this->shipments)),
            'customer_flat_fee'   => round($this->customerFlatFee, 2),
            'internal_true_cost'  => round($this->internalTrueCost, 2),
            'subtotal'            => round($this->subtotal, 2),
            'upsell_hint'         => $this->upsellHint,
            'quote_source'        => $this->quoteSource,
        ];
    }
}
