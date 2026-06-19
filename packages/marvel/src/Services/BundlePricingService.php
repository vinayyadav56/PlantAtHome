<?php

namespace Marvel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\VendorProductPrice;

/**
 * The ONE place a bundle's price is computed. Used by three callers so they can
 * never disagree: admin live-preview, bundle create/update (persists final_price
 * into products.price), and (indirectly) the order line which reads that stored
 * price. Four modes:
 *   fixed       → pricing_value (absolute bundle price)
 *   percentage  → originalTotal × (1 - v/100)
 *   flat        → originalTotal - v
 *   margin      → vendorCostTotal × (1 + v/100)
 * originalTotal = Σ componentUnitRef × pivot.qty (sale_price ?: price ?: min_price)
 * vendorCostTotal = Σ MIN(cost_price) over each component's effective vendor rows × qty.
 */
class BundlePricingService
{
    /**
     * @param  iterable $lines  each item ['product'=>Product, 'quantity'=>int] — works
     *                          for both a saved bundle (pivot qty) and an unsaved create payload.
     * @return array{original_total:float, discount_amount:float, final_price:float, vendor_cost_total:float, est_margin:float}
     */
    public function breakdown(?string $mode, ?float $value, $lines): array
    {
        $lines = collect($lines);

        $original = round((float) $lines->sum(function ($l) {
            $p = $l['product'];
            $unit = (float) ($p->sale_price ?: $p->price ?: $p->min_price ?: 0);
            $qty  = max(1, (int) ($l['quantity'] ?? 1));
            return $unit * $qty;
        }), 2);

        $vendorCost = round($this->vendorCostTotal($lines), 2);
        $value = (float) ($value ?? 0);

        switch ($mode) {
            case 'fixed':
                $final = $value;
                break;
            case 'percentage':
                $final = $original * (1 - $value / 100);
                break;
            case 'flat':
                $final = $original - $value;
                break;
            case 'margin':
                $final = $vendorCost * (1 + $value / 100);
                break;
            default:
                $final = $original;
        }

        $final = round(max(0, (float) $final), 2);
        $discount = round(max(0, $original - $final), 2);
        $margin = round($final - $vendorCost, 2);

        return [
            'original_total'    => $original,
            'discount_amount'   => $discount,
            'final_price'       => $final,
            'vendor_cost_total' => $vendorCost,
            'est_margin'        => $margin,
        ];
    }

    /** Σ over lines of MIN(cost_price) [effective, available, cost>0] × quantity. */
    public function vendorCostTotal($lines): float
    {
        $lines = collect($lines);
        if ($lines->isEmpty()) {
            return 0.0;
        }

        $today = Carbon::today()->toDateString();
        $ids = $lines->map(fn ($l) => $l['product']->id ?? null)->filter()->all();
        if (empty($ids)) {
            return 0.0;
        }

        $costByProduct = VendorProductPrice::query()
            ->select('product_id', DB::raw('MIN(cost_price) as min_cost'))
            ->whereIn('product_id', $ids)
            ->where('is_available', true)
            ->where('cost_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->groupBy('product_id')
            ->pluck('min_cost', 'product_id');

        $total = 0.0;
        foreach ($lines as $l) {
            $cost = (float) ($costByProduct[$l['product']->id] ?? 0);
            $qty = max(1, (int) ($l['quantity'] ?? 1));
            $total += $cost * $qty;
        }

        return $total;
    }

    /** Breakdown for a saved/loaded bundle product (reads its stored mode/value + pivot qty). */
    public function forBundle(Product $bundle): array
    {
        $items = $bundle->relationLoaded('bundleItems') ? $bundle->bundleItems : $bundle->bundleItems()->get();
        $lines = $items->map(fn ($p) => ['product' => $p, 'quantity' => (int) ($p->pivot->quantity ?: 1)]);

        return $this->breakdown($bundle->pricing_mode ?? 'fixed', $bundle->pricing_value, $lines);
    }

    /** A final price is persistable only if it's a real, strictly-positive offer. */
    public function isValidFinal(float $final): bool
    {
        return is_finite($final) && $final > 0;
    }
}
