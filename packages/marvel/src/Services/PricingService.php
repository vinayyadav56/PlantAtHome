<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\VendorProductPrice;

/**
 * Customer-facing price = margin over the nearest AVAILABLE vendor cost (the cost
 * itself is never exposed). Margin precedence: per-product → per-category → global,
 * from settings.options.vendorPricing. cost=0 ⇒ available=false (orderable, shown
 * with the "available within 6h" message). No cost sheet at all ⇒ catalog price.
 *
 * P2 is COARSE: the representative (cheapest available) cost for the product/size.
 * P3 narrows nearestCost() by the customer location (nearest vendor).
 */
class PricingService
{
    private array $vp;

    public function __construct()
    {
        $settings = Settings::getData();
        $this->vp = (array) (($settings->options['vendorPricing'] ?? []) ?: []);
    }

    /**
     * @return array{price: float, available: bool, message: ?string, base_price: float, has_vendor_cost: bool}
     */
    public function sellingPrice(Product $product, ?int $variationOptionId = null, ?array $latLng = null): array
    {
        $basePrice = (float) ($product->sale_price ?: $product->price ?: $product->min_price ?: 0);
        $row = $this->nearestCost($product->id, $variationOptionId, $latLng);

        if (!$row) {
            // No vendor cost sheet → sell at the catalog price (still available).
            return $this->result($basePrice, true, null, $basePrice, false);
        }

        if (!$row->is_available || (float) $row->cost_price <= 0) {
            // Cost present but zero → unavailable but still orderable.
            return $this->result($basePrice, false, $this->unavailableMessage(), $basePrice, true);
        }

        $margin = $this->marginFor($product);
        $price  = round((float) $row->cost_price * (1 + $margin / 100), 2);
        return $this->result($price, true, null, $basePrice, true);
    }

    /** The currently-effective representative cost row for a product/size (P2 coarse). */
    public function nearestCost(int $productId, ?int $variationOptionId, ?array $latLng = null): ?VendorProductPrice
    {
        $today = Carbon::today()->toDateString();
        $query = VendorProductPrice::where('product_id', $productId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));

        if (is_null($variationOptionId)) {
            $query->whereNull('variation_option_id');
        } else {
            $query->where('variation_option_id', $variationOptionId);
        }
        // P3: narrow by nearest vendor to $latLng. P2: representative (cheapest available).
        $available = (clone $query)->where('is_available', true)->where('cost_price', '>', 0)
            ->orderBy('cost_price')->first();

        return $available ?: $query->orderByDesc('id')->first();
    }

    /** True vendor cost for the profit snapshot (null if none / unavailable). */
    public function vendorCost(int $productId, ?int $variationOptionId, ?array $latLng = null): ?float
    {
        $row = $this->nearestCost($productId, $variationOptionId, $latLng);
        return $row && (float) $row->cost_price > 0 ? (float) $row->cost_price : null;
    }

    private function marginFor(Product $product): float
    {
        $productMargins = (array) ($this->vp['productMargins'] ?? []);
        if (array_key_exists($product->id, $productMargins)) {
            return (float) $productMargins[$product->id];
        }
        $categoryMargins = (array) ($this->vp['categoryMargins'] ?? []);
        if ($categoryMargins) {
            $catIds = $product->relationLoaded('categories')
                ? $product->categories->pluck('id')->all()
                : $product->categories()->pluck('categories.id')->all();
            foreach ($catIds as $cid) {
                if (array_key_exists($cid, $categoryMargins)) {
                    return (float) $categoryMargins[$cid];
                }
            }
        }
        return (float) ($this->vp['globalMarginPercent'] ?? 0);
    }

    private function unavailableMessage(): string
    {
        return (string) ($this->vp['unavailableMessage']
            ?? 'We will update the availability of this plant within 6 hours.');
    }

    private function result(float $price, bool $available, ?string $message, float $basePrice, bool $hasCost): array
    {
        return [
            'price'           => $price,
            'available'       => $available,
            'message'         => $message,
            'base_price'      => $basePrice,
            'has_vendor_cost' => $hasCost,
        ];
    }
}
