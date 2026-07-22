<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\City;
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
                // The vendor's supply rate (what they receive when assigned). The customer
                // selling price is derived from the city's MAX rate + margin, not per-row.
                'vendor_rate'          => ($product && $hasPrice) ? $this->pricing->vendorRate($product, $r) : null,
                'selling_price'        => ($product && $hasPrice) ? $this->pricing->vendorRate($product, $r) : null,
                'stock_qty'            => (int) ($r->stock_qty ?? 0),
                'available_qty'        => (int) $r->available_qty,
                'track_stock'          => (bool) ($r->track_stock ?? false),
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
        // track_stock = 0 means "stock not tracked" (the common price-only sheet) → always
        // sellable. When track_stock = 1 the vendor IS managing stock, so a row with no free
        // stock (stock_qty - reserved_qty <= 0) is out of stock and excluded.
        $rows = $this->effective(
            VendorProductPrice::where('product_id', $productId)
                ->where('is_available', true)
                ->where(fn ($q) => $q->where('vendor_selling_price', '>', 0)->orWhere('cost_price', '>', 0))
                ->where(fn ($q) => $q->where('track_stock', false)->orWhereRaw('(stock_qty - reserved_qty) > 0'))
        )->get();

        $product = Product::with('categories:id')->find($productId);
        $cities = [];
        if ($rows->isNotEmpty() && $product) {
            // Each row's vendor RATE, computed once (rate = vendor quote, or legacy
            // margin-over-cost for cost-only sheets).
            $rateByRowId = $rows->mapWithKeys(fn ($r) => [$r->id => $this->pricing->vendorRate($product, $r)]);

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
                    $cities[$key] = ['has_local' => false, 'has_courier' => false, 'vendors' => [], 'max_rate' => []];
                }
                $mode = $area->fulfillment_mode;
                if ($mode === 'local' || $mode === 'both') {
                    $cities[$key]['has_local'] = true;
                }
                if ($mode === 'courier' || $mode === 'both') {
                    $cities[$key]['has_courier'] = true;
                }
                $cities[$key]['vendors'][$area->shop_id] = true;
                // Selling price per variant in a city = MAX vendor rate (+ margin below).
                foreach ($vendorRows as $r) {
                    $variant = $r->variation_option_id ?? 0; // 0 = simple/base row
                    $rate = (float) $rateByRowId[$r->id];
                    $cities[$key]['max_rate'][$variant] = max($cities[$key]['max_rate'][$variant] ?? 0.0, $rate);
                }
            }
        }

        $keep = array_keys($cities);
        ProductCityAvailability::where('product_id', $productId)
            ->when(!empty($keep), fn ($q) => $q->whereNotIn('city', $keep))
            ->delete();

        $now = Carbon::now();
        $resolver = new MarginResolver();
        $typeId = ($product && $product->type_id) ? (int) $product->type_id : null;
        foreach ($cities as $city => $info) {
            // The card "from ₹" price = the cheapest VARIANT's city price, where each
            // variant's city price is MAX-over-vendors rate × (1 + margin(city, vertical)).
            $margin = $resolver->marginPercent($city, $typeId);
            $minVariantMaxRate = !empty($info['max_rate']) ? min($info['max_rate']) : null;
            $minPrice = $minVariantMaxRate !== null
                ? round($minVariantMaxRate * (1 + $margin / 100), 2)
                : null;
            ProductCityAvailability::updateOrCreate(
                ['product_id' => $productId, 'city' => $city],
                [
                    'has_local'    => $info['has_local'],
                    'has_courier'  => $info['has_courier'],
                    'min_price'    => $minPrice,
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
    /**
     * Normalise a customer-supplied city name to the canonical key used in the
     * cities table + the product_city_availability projection. Handles the common
     * Indian endonym/exonym aliases (e.g. an IP/GPS lookup returning "Gurgaon"
     * while the city is stored as "Gurugram") so we don't wrongly empty the store.
     */
    public function normalizeCityKey(string $city): string
    {
        $key = strtolower(trim($city));
        static $aliases = [
            'gurgaon'   => 'gurugram',
            'bangalore' => 'bengaluru',
            'bombay'    => 'mumbai',
            'calcutta'  => 'kolkata',
            'madras'    => 'chennai',
            'new delhi' => 'delhi',
            // Delhi NCT postal districts — all one shopping city for us. The
            // reverse-geocode nearest-city fallback otherwise lands on these
            // non-serviceable sub-city rows (e.g. a Rohini pin -> "North West
            // Delhi") and wrongly reports Delhi as unserviceable.
            'central delhi'    => 'delhi',
            'east delhi'       => 'delhi',
            'north delhi'      => 'delhi',
            'north east delhi' => 'delhi',
            'north west delhi' => 'delhi',
            'south delhi'      => 'delhi',
            'south east delhi' => 'delhi',
            'south west delhi' => 'delhi',
            'west delhi'       => 'delhi',
            'shahdara'         => 'delhi',
        ];
        return $aliases[$key] ?? $key;
    }

    public function availabilityProductIdQuery(string $city, bool $localOnly = false)
    {
        $key = $this->normalizeCityKey($city);
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

    /**
     * City-first storefront policy — the single source of truth for "which products
     * are visible when the customer has selected city C". Returns either a query of
     * allowed product ids (apply with whereIn) or NULL meaning "no restriction / full
     * catalog". Three cases:
     *   1. City HAS vendor inventory mapped  -> STRICT: only that inventory. The
     *      marketplace is live for this city, so we honour it exactly (e.g. Rewari
     *      with one mapped vendor shows only that vendor's product).
     *   2. City is SERVICEABLE but unmapped  -> NULL (full catalog). The master
     *      PlantAtHome shop serves every serviceable city, so we never empty it
     *      while vendors are still onboarding.
     *   3. City is NOT serviceable           -> the (empty) projection query -> zero
     *      products -> a proper "we don't deliver here yet" empty state. No fallback
     *      ever exposes another city's catalog.
     * Defensive: any fault returns NULL (full catalog) so a DB hiccup never empties
     * the storefront.
     */
    public function cityScopeProductIds(string $city, bool $localOnly = false)
    {
        try {
            $key = $this->normalizeCityKey($city);
            if ($key === '') {
                return null;
            }
            $vendorSub = $this->availabilityProductIdQuery($key, $localOnly);
            if ((clone $vendorSub)->exists()) {
                return $vendorSub; // (1) marketplace live here — strict
            }
            if ($this->cityIsServiceable($key)) {
                return null;        // (2) serviceable + unmapped — full catalog
            }
            return $vendorSub;      // (3) not serviceable — empty -> empty state
        } catch (\Throwable $e) {
            return null;            // never empty the store on a fault
        }
    }

    /** Whether a city (matched by name, case-insensitive) is serviceable + accepting orders. */
    public function cityIsServiceable(string $cityName): bool
    {
        static $cache = [];
        $key = $this->normalizeCityKey($cityName);
        if ($key === '') {
            return false;
        }
        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = City::whereRaw('LOWER(name) = ?', [$key])
                    ->where('is_serviceable', true)
                    ->whereIn('status', [City::STATUS_ACTIVE, City::STATUS_MAINTENANCE])
                    ->exists();
            } catch (\Throwable $e) {
                $cache[$key] = true; // fail-open: treat as serviceable so we don't empty the store
            }
        }
        return $cache[$key];
    }

    /**
     * Apply the city-first product scope to ANY products query. Centralises the
     * policy so the listing, search, category, popular, best-selling, top-rated and
     * related feeds all behave identically. No-op when no city is given. `$idColumn`
     * is qualified ('products.id') so it works on joined queries (best-sellers).
     */
    public function applyCityScope($query, ?string $city, bool $localOnly = false, string $idColumn = 'products.id')
    {
        if (empty($city)) {
            return $query;
        }
        $ids = $this->cityScopeProductIds((string) $city, $localOnly);
        if ($ids === null) {
            return $query; // full catalog (serviceable + unmapped, or no city)
        }
        return $query->whereIn($idColumn, $ids);
    }
}
