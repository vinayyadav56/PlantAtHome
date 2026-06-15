<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductCityAvailability;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Models\VendorServiceArea;

/**
 * Marketplace availability: which vendors supply a master product, and which master
 * products are available in a city. The master catalog stays the single source of
 * truth — vendors only map onto it (vendor_product_prices) and declare where they
 * serve (vendor_service_areas). Customer-facing reads use the denormalized
 * product_city_availability projection that recomputeForProduct() maintains.
 */
class AvailabilityService
{
    public function __construct(private ?PricingService $pricing = null)
    {
        $this->pricing = $pricing ?: new PricingService();
    }

    /** Scope a vendor_product_prices query to rows effective today. */
    private function effective($query)
    {
        $today = Carbon::today()->toDateString();
        return $query
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }

    /**
     * Admin / order screen: every vendor supplying a master product (optionally a
     * size), with price (cost + selling), stock, availability, fulfillment mode and
     * the vendor's served cities. Admin-only — never exposed to customers.
     */
    public function vendorsForProduct(int $productId, ?int $variationOptionId = null): array
    {
        $q = VendorProductPrice::with('shop:id,name')->where('product_id', $productId);
        if (!is_null($variationOptionId)) {
            $q->where('variation_option_id', $variationOptionId);
        }
        $rows = $this->effective($q)->get();

        $shopIds = $rows->pluck('shop_id')->unique()->values()->all();
        $areas = VendorServiceArea::whereIn('shop_id', $shopIds)->where('is_active', true)->get()->groupBy('shop_id');
        $product = Product::find($productId);

        return $rows->map(function (VendorProductPrice $r) use ($areas, $product) {
            $cities = ($areas[$r->shop_id] ?? collect())
                ->map(fn ($a) => ['city' => $a->city, 'fulfillment_mode' => $a->fulfillment_mode, 'eta_days' => $a->eta_days])
                ->values()->all();
            return [
                'shop_id'             => $r->shop_id,
                'vendor_name'         => optional($r->shop)->name,
                'variation_option_id' => $r->variation_option_id,
                'cost_price'          => (float) $r->cost_price,
                'selling_price'       => ($product && (float) $r->cost_price > 0) ? $this->pricing->priceFromCost($product, (float) $r->cost_price) : null,
                'stock_qty'           => (int) ($r->stock_qty ?? 0),
                'available_qty'       => (int) $r->available_qty,
                'is_available'        => (bool) $r->is_available && (float) $r->cost_price > 0,
                'fulfillment_mode'    => $r->fulfillment_mode,
                'cities'              => $cities,
            ];
        })->all();
    }

    /**
     * Rebuild the product_city_availability rows for one master product from its
     * available + in-stock vendor inventory crossed with each vendor's service areas.
     * City is stored normalized (lowercase) so customer-city lookups match reliably.
     */
    public function recomputeForProduct(int $productId): void
    {
        $rows = $this->effective(
            VendorProductPrice::where('product_id', $productId)
                ->where('is_available', true)
                ->where('cost_price', '>', 0)
                ->where('stock_qty', '>', 0)
        )->get();

        $product = Product::find($productId);
        $cities = [];
        if ($rows->isNotEmpty() && $product) {
            $shopIds = $rows->pluck('shop_id')->unique()->values()->all();
            $areas = VendorServiceArea::whereIn('shop_id', $shopIds)->where('is_active', true)->get();
            foreach ($areas as $area) {
                $vendorRows = $rows->where('shop_id', $area->shop_id);
                if ($vendorRows->isEmpty()) {
                    continue;
                }
                $key = strtolower(trim((string) $area->city));
                if ($key === '') {
                    continue;
                }
                if (!isset($cities[$key])) {
                    $cities[$key] = ['has_local' => false, 'has_courier' => false, 'vendors' => [], 'min_cost' => null];
                }
                $mode = $area->fulfillment_mode;
                if ($mode === 'local' || $mode === 'both') {
                    $cities[$key]['has_local'] = true;
                }
                if ($mode === 'courier' || $mode === 'both') {
                    $cities[$key]['has_courier'] = true;
                }
                $cities[$key]['vendors'][$area->shop_id] = true;
                $minCost = (float) $vendorRows->min('cost_price');
                $cities[$key]['min_cost'] = is_null($cities[$key]['min_cost']) ? $minCost : min($cities[$key]['min_cost'], $minCost);
            }
        }

        $keep = array_keys($cities);
        ProductCityAvailability::where('product_id', $productId)
            ->when(!empty($keep), fn ($q) => $q->whereNotIn('city', $keep))
            ->delete();

        $now = Carbon::now();
        foreach ($cities as $city => $info) {
            ProductCityAvailability::updateOrCreate(
                ['product_id' => $productId, 'city' => $city],
                [
                    'has_local'    => $info['has_local'],
                    'has_courier'  => $info['has_courier'],
                    'min_price'    => ($product && !is_null($info['min_cost'])) ? $this->pricing->priceFromCost($product, $info['min_cost']) : null,
                    'vendor_count' => count($info['vendors']),
                    'updated_at'   => $now,
                ]
            );
        }
    }

    /** Master product ids available in a city (city-first storefront). */
    public function availableProductIdsInCity(string $city, bool $localOnly = false): array
    {
        $key = strtolower(trim($city));
        if ($key === '') {
            return [];
        }
        $q = ProductCityAvailability::where('city', $key);
        if ($localOnly) {
            $q->where('has_local', true);
        } else {
            $q->where(fn ($qq) => $qq->where('has_local', true)->orWhere('has_courier', true));
        }
        return $q->pluck('product_id')->all();
    }
}
