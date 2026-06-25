<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

/**
 * One cart line, reduced to the primitives the optimizer needs. Weight/dims are in
 * the same units the catalog stores (grams; cm) so leg weight is just a sum.
 */
final class CartItem
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $variationOptionId,
        public readonly int $qty,
        public readonly int $unitWeightG = 500,
        public readonly ?float $lengthCm = null,
        public readonly ?float $breadthCm = null,
        public readonly ?float $heightCm = null,
    ) {
    }

    public static function fromArray(array $a, int $defaultWeightG = 500): self
    {
        return new self(
            (int) ($a['product_id'] ?? 0),
            isset($a['variation_option_id']) ? (int) $a['variation_option_id'] : null,
            max(1, (int) ($a['quantity'] ?? $a['qty'] ?? 1)),
            (int) (($a['weight'] ?? $a['unit_weight_g'] ?? 0) ?: $defaultWeightG),
            isset($a['length']) ? (float) $a['length'] : null,
            isset($a['breadth']) ? (float) $a['breadth'] : null,
            isset($a['height']) ? (float) $a['height'] : null,
        );
    }

    /** Stable identity for an item within a cart (product + variation). */
    public function key(): string
    {
        return $this->productId . ':' . ($this->variationOptionId ?? 0);
    }

    /** Total shipping weight for this line (per-unit weight * quantity), floored at 1g. */
    public function totalWeightG(): int
    {
        return max(1, $this->unitWeightG) * max(1, $this->qty);
    }
}
