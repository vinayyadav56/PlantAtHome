<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $product = Product::with('categories:id')->find($productId);

        return $rows->map(function (VendorProductPrice $r) use ($areas, $product) {
            $cities = ($areas[$r->shop_id] ?? collect())
                ->map(fn ($a) => ['city' => $a->city, 'fulfillment_mode' => $a->fulfillment_mode, 'eta_days' => $a->eta_days])
                ->values()->all();
            $hasPrice = (float) ($r->vendor_selling_price ?? 0) > 0 || (float) $r->cost_price > 0;
            return [
                'shop_id'                 => $r->shop_id,
                'vendor_product_price_id' => $r->id,
                'vendor_name'             => optional($r->shop)->name,
                'variation_option_id'     => $r->variation_option_id,
                'cost_price'           => (float) $r->cost_price,
                'vendor_selling_price' => $r->vendor_selling_price !== null ? (float) $r->vendor_selling_price : null,
                'selling_price'        => ($product && $hasPrice) ? $this->pricing->effectivePrice($product, $r) : null,
                'stock_qty'            => (int) ($r->stock_qty ?? 0),
                'available_qty'        => (int) $r->available_qty,
                'is_available'         => (bool) $r->is_available && $hasPrice,
                'fulfillment_mode'     => $r->fulfillment_mode,
                'cities'               => $cities,
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
        // Available in a city = a vendor has a current, priced, in-stock-OR-untracked row.
        // stock_qty <= 0 means "stock not tracked" (the common price-only sheet) → still
        // sellable; real stock is enforced at order time, not here.
        $rows = $this->effective(
            VendorProductPrice::where('product_id', $productId)
                ->where('is_available', true)
                ->where(fn ($q) => $q->where('vendor_selling_price', '>', 0)->orWhere('cost_price', '>', 0))
                ->where(fn ($q) => $q->where('stock_qty', '<=', 0)->orWhereRaw('(stock_qty - reserved_qty) > 0'))
        )->get();

        $product = Product::with('categories:id')->find($productId);
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
                    $cities[$key] = ['has_local' => false, 'has_courier' => false, 'vendors' => [], 'min_price' => null];
                }
                $mode = $area->fulfillment_mode;
                if ($mode === 'local' || $mode === 'both') {
                    $cities[$key]['has_local'] = true;
                }
                if ($mode === 'courier' || $mode === 'both') {
                    $cities[$key]['has_courier'] = true;
                }
                $cities[$key]['vendors'][$area->shop_id] = true;
                // Customer-facing price = lowest displayed price among this city's vendors
                // (vendor-set selling price, or margin-over-cost for legacy cost-only rows).
                $minPrice = (float) $vendorRows->min(fn ($r) => $this->pricing->effectivePrice($product, $r));
                $cities[$key]['min_price'] = is_null($cities[$key]['min_price']) ? $minPrice : min($cities[$key]['min_price'], $minPrice);
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
                    'min_price'    => $info['min_price'],
                    'vendor_count' => count($info['vendors']),
                    'updated_at'   => $now,
                ]
            );
        }
    }

    /** Recompute every product a vendor supplies (after a service-area / inventory change). */
    public function recomputeForShop(int $shopId): void
    {
        $pids = VendorProductPrice::where('shop_id', $shopId)->distinct()->pluck('product_id');
        foreach ($pids as $pid) {
            $this->recomputeForProduct((int) $pid);
        }
        self::bustCatalogCache();
    }

    /** Bump the products response-cache version so city-scoped lists refresh immediately. */
    public static function bustCatalogCache(): void
    {
        Cache::forever('products:ver', ((int) Cache::get('products:ver', 1)) + 1);
    }

    /** A query builder of product ids available in a city — used as a whereIn subquery (no pluck). */
    public function availabilityProductIdQuery(string $city, bool $localOnly = false)
    {
        $key = strtolower(trim($city));
        $q = ProductCityAvailability::query()->select('product_id')->where('city', $key);
        if ($localOnly) {
            $q->where('has_local', true);
        } else {
            $q->where(fn ($qq) => $qq->where('has_local', true)->orWhere('has_courier', true));
        }
        return $q;
    }

    /** Master product ids available in a city (city-first storefront / has-availability checks). */
    public function availableProductIdsInCity(string $city, bool $localOnly = false): array
    {
        if (trim($city) === '') {
            return [];
        }
        return $this->availabilityProductIdQuery($city, $localOnly)->pluck('product_id')->all();
    }
}
