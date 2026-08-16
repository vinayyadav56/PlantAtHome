<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Models\VendorServiceArea;

/**
 * PlantAtHome pricing: the customer-facing selling price for a product (+variant) in a
 * city is
 *
 *     MAX(vendor rate among available vendors covering the city) × (1 + margin%)
 *
 * where the margin resolves city+vertical → city → vertical → global (MarginResolver).
 * "Vendor rate" is the vendor's quoted supply rate (vendor_selling_price — what the
 * vendor RECEIVES when assigned), or margin-over-cost for legacy cost-only sheets.
 * Basing the price on the HIGHEST rate guarantees any covering vendor can be assigned
 * without selling below their quote; assignment prefers the cheapest vendor, so the
 * realised platform take is ≥ the configured margin. The vendor is never named.
 *
 * No vendor mapping at all ⇒ catalog price. Mapped-but-unavailable ⇒ orderable with
 * the "available within 6h" message. Cost prices are never exposed.
 */
class PricingService
{
    private array $vp;

    public function __construct(private ?MarginResolver $margins = null)
    {
        $settings = Settings::getData();
        $this->vp = (array) (($settings->options['vendorPricing'] ?? []) ?: []);
        $this->margins = $margins ?: new MarginResolver();
    }

    /**
     * @return array{price: float, available: bool, message: ?string, base_price: float, has_vendor_cost: bool, max_vendor_rate: ?float, margin_percent: ?float}
     */
    public function sellingPrice(Product $product, ?int $variationOptionId = null, ?array $latLng = null, ?string $city = null): array
    {
        $basePrice = (float) ($product->sale_price ?: $product->price ?: $product->min_price ?: 0);
        $cityKey = $this->cityKey($city);

        $rows = $this->coveredRows($product->id, $variationOptionId, $cityKey);
        if ($rows->isNotEmpty()) {
            $typeId  = $product->type_id ? (int) $product->type_id : null;
            $maxRate = (float) $rows->max(fn ($r) => $this->vendorRate($product, $r));
            // The margin formula (percent OR flat-₹) lives in MarginResolver::apply
            // — never reintroduce `* (1 + margin/100)` here.
            $price   = $this->margins->apply($maxRate, $cityKey, $typeId);
            $margin  = $this->margins->effectivePercent($maxRate, $cityKey, $typeId);
            return $this->result($price, true, null, $basePrice, true, $maxRate, $margin);
        }

        // Nothing available. Is there any effective row at all (priced but unavailable)?
        $any = $this->nearestCost($product->id, $variationOptionId, $latLng);
        if (!$any) {
            // No vendor mapping → sell at the catalog price (still available).
            return $this->result($basePrice, true, null, $basePrice, false);
        }
        // Mapped but unavailable → orderable, shown with the "available within 6h" message.
        return $this->result($basePrice, false, $this->unavailableMessage(), $basePrice, true);
    }

    /**
     * The vendor's supply rate for one row: their quoted vendor_selling_price when set,
     * else margin-over-cost (legacy cost-only sheets). This is what the vendor RECEIVES
     * when assigned — never exposed to customers as-is (the city margin goes on top).
     */
    public function vendorRate(Product $product, VendorProductPrice $row): float
    {
        $sell = (float) ($row->vendor_selling_price ?? 0);
        if ($sell > 0) {
            return round($sell, 2);
        }
        return $this->priceFromCost($product, (float) $row->cost_price);
    }

    /** @deprecated semantics changed — the per-row figure is now the vendor RATE. */
    public function effectivePrice(Product $product, VendorProductPrice $row): float
    {
        return $this->vendorRate($product, $row);
    }

    /**
     * Available vendor rows for a product/variant, narrowed to vendors whose active
     * service area covers the city. When a city is given but NO vendor covers it, fall
     * back to all available rows (national-courier tier — mirrors
     * ItemAssignmentService::matchArea) so a fulfillable line is never unpriced.
     */
    public function coveredRows(int $productId, ?int $variationOptionId, ?string $cityKey)
    {
        $rows = $this->availableRowsQuery($productId, $variationOptionId)->get();
        if ($rows->isEmpty() || !$cityKey) {
            return $rows;
        }
        // Match every raw spelling of this city, not just the canonical key. `vendor_service_areas`
        // stores the vendor's own words ("Gurgaon", "South Delhi"), so an equality test against the
        // canonical key silently found no covering vendor — and because the fallback below returns
        // ALL rows when nothing matches, the line was then priced off the national pool instead.
        // Wrong price, no error. Same shape as AvailabilityService::availableVendorRows.
        $variants = AvailabilityService::canonicalCityVariants($cityKey);
        $coveredShopIds = VendorServiceArea::whereIn('shop_id', $rows->pluck('shop_id')->unique()->values())
            ->where('is_active', true)
            ->whereIn(DB::raw('LOWER(TRIM(city))'), $variants)
            ->pluck('shop_id')
            ->flip();
        $covered = $rows->filter(fn ($r) => isset($coveredShopIds[$r->shop_id]));
        return $covered->isNotEmpty() ? $covered->values() : $rows;
    }

    /** Effective, available, priced vendor rows for a product/size (vendor-set OR cost). */
    private function availableRowsQuery(int $productId, ?int $variationOptionId)
    {
        $today = Carbon::today()->toDateString();
        $query = VendorProductPrice::where('product_id', $productId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->where('is_available', true)
            ->where(fn ($q) => $q->where('vendor_selling_price', '>', 0)->orWhere('cost_price', '>', 0))
            // Exclude TRACKED sold-out vendors so the charged/displayed price can never come
            // from a vendor the browse listing already dropped. Mirrors AvailabilityService
            // exactly: an untracked row (track_stock = false) is always sellable; a tracked
            // row is sellable only while (stock_qty - reserved_qty) > 0.
            ->where(fn ($q) => $q->where('track_stock', false)->orWhereRaw('(stock_qty - reserved_qty) > 0'));

        if (is_null($variationOptionId)) {
            $query->whereNull('variation_option_id');
        } else {
            $query->where('variation_option_id', $variationOptionId);
        }
        return $query;
    }

    /** The currently-effective representative cost row for a product/size (unavailability probe). */
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
        $available = (clone $query)->where('is_available', true)->where('cost_price', '>', 0)
            ->orderBy('cost_price')->first();

        return $available ?: $query->orderByDesc('id')->first();
    }

    /**
     * Server-authoritative repricing of cart lines. For products that carry vendor
     * inventory (and are available), overrides unit_price + subtotal with the city
     * selling price (max rate + margin); products WITHOUT a vendor mapping are left
     * exactly as the client sent them. Tamper-proof — the price is computed here,
     * never trusted from the client — and consistent with the displayed location price.
     */
    public function repriceLines(array $products, ?array $latLng = null, ?string $city = null): array
    {
        foreach ($products as &$line) {
            $pid = $line['product_id'] ?? null;
            if (!$pid) {
                continue;
            }
            $product = Product::find((int) $pid);
            if (!$product) {
                continue;
            }
            // A bundle carries a stored offer price (BundlePricingService) and has
            // no vendor cost sheet — never reprice it from vendor rates.
            if ($product->product_type === \Marvel\Enums\ProductType::BUNDLE) {
                continue;
            }
            $vo = $line['variation_option_id'] ?? null;
            $r = $this->sellingPrice($product, $vo !== null ? (int) $vo : null, $latLng, $city);
            if (!empty($r['has_vendor_cost']) && !empty($r['available'])) {
                $qty = (int) ($line['order_quantity'] ?? 1);
                $line['unit_price'] = $r['price'];
                $line['subtotal']   = round($r['price'] * max($qty, 1), 2);
            }
        }
        return $products;
    }

    /**
     * Expected vendor payout for one line (hidden profit basis): the LOWEST rate among
     * covering vendors — assignment prefers the cheapest vendor, so this is what the
     * platform expects to pay out. Null when the product has no available vendor rows.
     */
    public function vendorCost(int $productId, ?int $variationOptionId, ?array $latLng = null, ?string $city = null): ?float
    {
        $product = Product::find($productId);
        if (!$product) {
            return null;
        }
        $rows = $this->coveredRows($productId, $variationOptionId, $this->cityKey($city));
        if ($rows->isEmpty()) {
            return null;
        }
        return (float) $rows->min(fn ($r) => $this->vendorRate($product, $r));
    }

    /** Legacy margin-over-cost rate for cost-only rows (never exposes the cost). */
    public function priceFromCost(Product $product, float $cost): float
    {
        return round($cost * (1 + $this->marginFor($product) / 100), 2);
    }

    /** Normalize a customer city to the canonical projection key (alias-aware, memoized). */
    private function cityKey(?string $city): ?string
    {
        if ($city === null || trim($city) === '') {
            return null;
        }
        static $memo = [];
        if (!array_key_exists($city, $memo)) {
            $memo[$city] = (new AvailabilityService($this))->normalizeCityKey($city);
        }
        return $memo[$city];
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

    private function result(
        float $price,
        bool $available,
        ?string $message,
        float $basePrice,
        bool $hasCost,
        ?float $maxRate = null,
        ?float $marginPercent = null
    ): array {
        return [
            'price'           => $price,
            'available'       => $available,
            'message'         => $message,
            'base_price'      => $basePrice,
            'has_vendor_cost' => $hasCost,
            'max_vendor_rate' => $maxRate,
            'margin_percent'  => $marginPercent,
        ];
    }
}
