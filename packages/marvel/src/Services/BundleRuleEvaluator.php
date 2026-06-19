<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Enums\ProductStatus;

/**
 * F5 — resolves a bundle rule into its live set of matching plants and the
 * resulting price. Works on a normalised rule array so it serves both saved
 * rules and the unsaved live-preview. Never mutates the catalogue.
 */
class BundleRuleEvaluator
{
    /** Operators allowed in a cost condition. */
    public const OPERATORS = ['<', '<=', '=', '>=', '>'];

    /**
     * The plants a rule currently matches (published only).
     *
     * cost_condition products carry a `min_cost` attribute (the cheapest
     * available vendor cost). Products with no cost data are excluded — never a
     * crash on missing cost.
     */
    public function matchingProducts(array $rule): Collection
    {
        $type = $rule['type'] ?? 'product_set';

        if ($type === 'cost_condition') {
            return $this->matchByCost($rule['eligibility'] ?? []);
        }

        $ids = array_values(array_filter(
            (array) ($rule['eligibility']['product_ids'] ?? []),
            fn ($id) => is_numeric($id)
        ));
        if (empty($ids)) {
            return collect();
        }

        return Product::whereIn('id', $ids)
            ->where('status', ProductStatus::PUBLISH)
            ->get();
    }

    /** Products whose cheapest available vendor cost satisfies the operator/threshold. */
    protected function matchByCost(array $eligibility): Collection
    {
        $operator = $eligibility['operator'] ?? null;
        $threshold = $eligibility['threshold'] ?? null;
        if (!in_array($operator, self::OPERATORS, true) || !is_numeric($threshold)) {
            return collect();
        }

        $today = Carbon::today()->toDateString();

        // Cheapest available cost per product (mirrors PricingService's filters).
        $costSub = VendorProductPrice::query()
            ->select('product_id', DB::raw('MIN(cost_price) as min_cost'))
            ->where('is_available', true)
            ->where('cost_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->groupBy('product_id');

        $query = Product::query()
            ->where('products.status', ProductStatus::PUBLISH)
            ->joinSub($costSub, 'vc', fn ($join) => $join->on('products.id', '=', 'vc.product_id'))
            ->where('vc.min_cost', $operator, (float) $threshold)
            ->select('products.*', 'vc.min_cost');

        if (!empty($eligibility['category_id'])) {
            $categoryId = (int) $eligibility['category_id'];
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $categoryId));
        }

        return $query->orderBy('vc.min_cost')->get();
    }

    /**
     * Illustrative price for a bundle of `quantity_rule.min` plants drawn from
     * the matching pool (cheapest first), with consistent 2-dp rounding.
     *
     * @return array{original_total: float, discount_amount: float, final_price: float, sample_size: int}
     */
    public function priceBreakdown(array $rule, Collection $products): array
    {
        $qty = (int) ($rule['quantity_rule']['min'] ?? 1);
        $qty = max(1, $qty);

        $sample = $products
            ->sortBy(fn ($p) => $this->sellingPrice($p))
            ->take($qty);
        $sampleSize = $sample->count();

        $original = round((float) $sample->sum(fn ($p) => $this->sellingPrice($p)), 2);

        $bundlePrice = $rule['bundle_price'] ?? null;
        $discountType = $rule['discount_type'] ?? null;
        $discountValue = (float) ($rule['discount_value'] ?? 0);

        if (is_numeric($bundlePrice)) {
            $final = round((float) $bundlePrice, 2);
        } elseif ($discountType === 'percentage') {
            $final = round($original - ($original * $discountValue / 100), 2);
        } elseif ($discountType === 'flat') {
            $final = round($original - $discountValue, 2);
        } else {
            $final = $original;
        }

        $final = max(0, $final);
        $discount = round(max(0, $original - $final), 2);

        return [
            'original_total'  => $original,
            'discount_amount' => $discount,
            'final_price'     => $final,
            'sample_size'     => $sampleSize,
        ];
    }

    /** Customer-facing unit price used for pool valuation (falls back across columns). */
    protected function sellingPrice($product): float
    {
        return (float) ($product->price
            ?? $product->min_price
            ?? $product->max_price
            ?? 0);
    }
}
