<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

/**
 * One consolidated delivery leg in the final plan: all items assigned to a single
 * (vendor, rail), priced ONCE. This is what the customer sees as a "shipment" and
 * what we book against the shipping-service.
 */
final class Shipment
{
    /** @param CartItem[] $items */
    public function __construct(
        public readonly string $shipmentId,
        public readonly int $vendorId,
        public readonly string $rail,
        public array $items,
        public float $trueCost = 0.0,
        public int $etaMinDays = 0,
        public int $etaMaxDays = 0,
        public ?string $etaMinDate = null,
        public ?string $etaMaxDate = null,
        public string $quoteSource = 'estimate', // firm | estimate
    ) {
    }

    public function totalWeightG(): int
    {
        $sum = 0;
        foreach ($this->items as $it) {
            $sum += $it->totalWeightG();
        }
        return max(1, $sum);
    }

    /**
     * CUSTOMER-SAFE shape. Deliberately omits vendor_id, rail, and true_cost — exposing those
     * on a public/customer response would break seller anonymity (which vendor fulfils what)
     * and leak the platform's per-leg cost economics.
     */
    public function toArray(): array
    {
        return [
            'shipment_id'  => $this->shipmentId,
            'items'        => array_map(fn (CartItem $i) => [
                'product_id'          => $i->productId,
                'variation_option_id' => $i->variationOptionId,
                'qty'                 => $i->qty,
            ], $this->items),
            'eta_min_date' => $this->etaMinDate,
            'eta_max_date' => $this->etaMaxDate,
        ];
    }

    /** FULL shape for admin / logging / internal use only — never return on a customer response. */
    public function toInternalArray(): array
    {
        return $this->toArray() + [
            'vendor_id'    => $this->vendorId,
            'rail'         => $this->rail,
            'true_cost'    => round($this->trueCost, 2),
            'quote_source' => $this->quoteSource,
        ];
    }
}
