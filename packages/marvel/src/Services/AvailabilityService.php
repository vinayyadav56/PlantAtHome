<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductCityAvailability;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
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

    /**
     * Drop rows belonging to a vendor who is on hold.
     *
     * This is what gives "on hold" teeth. `is_active` was never checked here —
     * it only gates the vendor dashboard — so before this a suspended vendor's
     * stock kept being sold and assigned like nothing had happened. Since the
     * single-master-shop change vendors own no products of their own, they
     * supply purely through vendor_product_prices, which makes this the one
     * place the whole selling path funnels through.
     *
     * `whereDoesntHave` rather than `where(... '!=', 'on_hold')` on purpose:
     * the latter is a NOT EXISTS that also silently drops every row whose shop
     * has a NULL approval_status (legacy rows) — i.e. it would stop selling
     * perfectly good inventory. This only ever excludes an explicit hold.
     */
    private function excludeHeldVendors($query)
    {
        return $query->whereDoesntHave(
            'shop',
            fn ($s) => $s->where('approval_status', Shop::STATUS_ON_HOLD),
        );
    }

    /**
     * Scope a vendor_product_prices query to rows effective today.
     *
     * Public so admin surfaces (the city command center's vendor counts) can
     * apply the SAME window the projection uses — counting without it reports
     * expired price sheets as live catalogue and disagrees with the product
     * list rendered beside it.
     */
    public function effective($query)
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
        // Held vendors are excluded here rather than only in candidatesFor,
        // because ItemAssignmentService::candidatesFor() calls THIS method to
        // build its candidate list — so this single filter covers both the
        // admin supply view and live order assignment.
        $rows = $this->effective($this->excludeHeldVendors($q))->get();

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
     * Vendor names supplying each of these products IN a city — ONE grouped
     * query for a whole page, not vendorsForProduct() per row.
     *
     * Carries the SAME predicates the projection is built with (held vendors
     * excluded, effective window, is_available, active service area), because
     * a name list that disagrees with the vendor_count beside it is worse than
     * no name list: it points operators at vendors who aren't actually selling.
     *
     * @param  int[]  $productIds
     * @return array<int, array<int, array{id:int,name:string}>> product_id => vendors
     */
    public function vendorNamesForProducts(array $productIds, string $cityKey): array
    {
        if (empty($productIds)) {
            return [];
        }
        try {
            $shopIds = VendorServiceArea::whereIn(
                DB::raw('LOWER(city)'),
                $this->cityKeyVariants($cityKey),
            )->where('is_active', true)->distinct()->pluck('shop_id');

            if ($shopIds->isEmpty()) {
                return [];
            }

            // Pairs first, names second — two flat queries. A join to `shops`
            // here would collide with the `shops` subquery excludeHeldVendors
            // builds, and the second query is a single indexed IN.
            $pairs = $this->effective(
                $this->excludeHeldVendors(
                    VendorProductPrice::whereIn('product_id', $productIds)
                        ->whereIn('shop_id', $shopIds)
                        ->where('is_available', true)
                )
            )->select('product_id', 'shop_id')->distinct()->get();

            $names = Shop::whereIn('id', $pairs->pluck('shop_id')->unique())
                ->pluck('name', 'id');

            $out = [];
            foreach ($pairs as $p) {
                $out[(int) $p->product_id][] = [
                    'id'   => (int) $p->shop_id,
                    'name' => (string) ($names[$p->shop_id] ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return []; // names are decoration — never fail the catalogue panel
        }
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
            $this->excludeHeldVendors(
                VendorProductPrice::where('product_id', $productId)
                    ->where('is_available', true)
                    ->where(fn ($q) => $q->where('vendor_selling_price', '>', 0)->orWhere('cost_price', '>', 0))
                    ->where(fn ($q) => $q->where('track_stock', false)->orWhereRaw('(stock_qty - reserved_qty) > 0'))
            )
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
                // CANONICAL key — normalizeCityKey, not a bare strtolower.
                // Every READER of this projection normalizes (overlayCityPrices,
                // availabilityProductIdQuery, cityHasSupply), so writing a raw
                // key made a vendor invisible in their own city whenever the
                // service area used an alias: a "Gurgaon" area wrote `gurgaon`
                // while every lookup asked for `gurugram` and found nothing.
                // Same for Bangalore/Bombay/Calcutta/Madras and all nine Delhi
                // NCT sub-districts.
                $key = $this->normalizeCityKey((string) $area->city);
                if ($key === '') {
                    continue;
                }
                if (!isset($cities[$key])) {
                    $cities[$key] = [
                        'has_local' => false, 'has_courier' => false,
                        'vendors' => [], 'max_rate' => [],
                        'variant_vendors' => [], 'stock' => [],
                    ];
                }
                $mode = $area->fulfillment_mode;
                if ($mode === 'local' || $mode === 'both') {
                    $cities[$key]['has_local'] = true;
                }
                if ($mode === 'courier' || $mode === 'both') {
                    $cities[$key]['has_courier'] = true;
                }
                // Fold each vendor's PRICE/STOCK rows into a city ONCE.
                // vendor_service_areas is unique per (shop, city, PINCODE), so a
                // vendor covering three pincodes in one city appears three times
                // here — max() wouldn't care, but SUM-aggregated stock would
                // triple-count. The mode flags above still run for every row, so
                // a second local/courier pincode row keeps widening coverage.
                if (isset($cities[$key]['vendors'][$area->shop_id])) {
                    continue;
                }
                $cities[$key]['vendors'][$area->shop_id] = true;
                // Per VARIANT in a city: MAX vendor rate (price), aggregated
                // stock, and the distinct vendors supplying that variant.
                foreach ($vendorRows as $r) {
                    $variant = (int) ($r->variation_option_id ?? 0); // 0 = simple/base row
                    $rate = (float) $rateByRowId[$r->id];
                    $cities[$key]['max_rate'][$variant] = max($cities[$key]['max_rate'][$variant] ?? 0.0, $rate);
                    $cities[$key]['variant_vendors'][$variant][$r->shop_id] = true;
                    // Stock: NULL means "a supplying vendor doesn't track stock"
                    // — effectively unlimited, and it stays NULL no matter what
                    // tracked vendors add. Tracked rows contribute free stock.
                    if (!array_key_exists($variant, $cities[$key]['stock'] ?? [])) {
                        $cities[$key]['stock'][$variant] = 0;
                    }
                    if (!$r->track_stock) {
                        $cities[$key]['stock'][$variant] = null;
                    } elseif ($cities[$key]['stock'][$variant] !== null) {
                        $free = max(0, (int) $r->stock_qty - (int) $r->reserved_qty);
                        $cities[$key]['stock'][$variant] = $this->aggregateStock(
                            (int) $cities[$key]['stock'][$variant],
                            $free,
                        );
                    }
                }
            }
        }

        // Prune per (city, variant): a city that lost supply disappears whole;
        // a variant a vendor stopped selling disappears from its city. Same
        // delete-what-wasn't-recomputed contract as before, one level deeper.
        $keep = array_keys($cities);
        ProductCityAvailability::where('product_id', $productId)
            ->when(!empty($keep), fn ($q) => $q->whereNotIn('city', $keep))
            ->delete();

        $now = Carbon::now();
        $resolver = new MarginResolver();
        $typeId = ($product && $product->type_id) ? (int) $product->type_id : null;
        foreach ($cities as $city => $info) {
            // Each variant's city price = the margin rule applied to its
            // MAX-over-vendors rate (percent or flat — MarginResolver::apply
            // owns the formula). Both rule types preserve rate order, so the
            // cheapest variant by rate is also cheapest by price.
            $variantPrices = [];
            foreach ($info['max_rate'] as $variant => $rate) {
                $variantPrices[$variant] = $resolver->apply((float) $rate, $city, $typeId);
            }

            // Variant rows (skip 0 here — it's written below as the rollup).
            foreach ($variantPrices as $variant => $price) {
                if ($variant === 0) {
                    continue;
                }
                ProductCityAvailability::updateOrCreate(
                    ['product_id' => $productId, 'city' => $city, 'variation_option_id' => $variant],
                    [
                        'has_local'     => $info['has_local'],
                        'has_courier'   => $info['has_courier'],
                        'min_price'     => $price,
                        'display_price' => $price,
                        'stock'         => $info['stock'][$variant] ?? null,
                        'vendor_count'  => count($info['variant_vendors'][$variant] ?? []),
                        'updated_at'    => $now,
                    ]
                );
            }

            // The 0-row rollup keeps the EXACT pre-variant semantics every
            // existing reader depends on: min_price = cheapest variant's city
            // price, vendor_count = distinct vendors product-wide. Rollup stock:
            // NULL if ANY variant is untracked, else the max across variants
            // (variants share physical plants often enough that summing across
            // them would overstate; max is the honest single number).
            $rollupStock = null;
            $stocks = $info['stock'] ?? [];
            if (!in_array(null, $stocks, true) && $stocks !== []) {
                $rollupStock = max($stocks);
            }
            ProductCityAvailability::updateOrCreate(
                ['product_id' => $productId, 'city' => $city, 'variation_option_id' => 0],
                [
                    'has_local'     => $info['has_local'],
                    'has_courier'   => $info['has_courier'],
                    'min_price'     => !empty($variantPrices) ? min($variantPrices) : null,
                    'display_price' => !empty($variantPrices) ? min($variantPrices) : null,
                    'stock'         => $rollupStock,
                    'vendor_count'  => count($info['vendors']),
                    'updated_at'    => $now,
                ]
            );

            // Variant-level prune inside a surviving city.
            $keepVariants = array_keys($variantPrices);
            $keepVariants[] = 0;
            ProductCityAvailability::where('product_id', $productId)
                ->where('city', $city)
                ->whereNotIn('variation_option_id', $keepVariants)
                ->delete();
        }
    }

    /**
     * Fold one vendor's free stock into a variant's city aggregate, per the
     * configured strategy. Default SUM — "23 across three vendors" is what the
     * business asked to show; max/min exist for operators who prefer a
     * conservative or single-vendor-capacity read.
     *
     * The `min` seed works because the row query already excludes tracked rows
     * with no free stock — every folded contribution is >= 1, so current === 0
     * can only be the initial seed, never a real zero.
     *
     * NOTE the static: cached per PROCESS. FPM forgets it per request; a queue
     * worker does not — after changing the strategy setting, `queue:restart`
     * (the same rule this codebase already has for ConfigOverlay).
     */
    private function aggregateStock(int $current, int $free): int
    {
        static $strategy = null;
        if ($strategy === null) {
            try {
                $opts = (array) (Settings::getData()->options['inventoryAggregation'] ?? []);
                $strategy = in_array($opts['strategy'] ?? 'sum', ['sum', 'max', 'min'], true)
                    ? ($opts['strategy'] ?? 'sum')
                    : 'sum';
            } catch (\Throwable $e) {
                $strategy = 'sum';
            }
        }
        return match ($strategy) {
            'max' => max($current, $free),
            'min' => $current === 0 ? $free : min($current, $free),
            default => $current + $free,
        };
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
    /**
     * Every raw city spelling that normalises to $key — the reverse of
     * normalizeCityKey. `vendor_service_areas.city` stores what the vendor
     * typed ("Gurgaon"), so matching a canonical key ("gurugram") against that
     * table needs the alias set, not an equality test.
     *
     * @return string[] lowercase spellings, canonical key first
     */
    public function cityKeyVariants(string $key): array
    {
        $key = $this->normalizeCityKey($key);
        $variants = [$key];
        foreach ($this->cityAliases() as $from => $to) {
            if ($to === $key) {
                $variants[] = $from;
            }
        }
        return array_values(array_unique($variants));
    }

    /** @return array<string,string> raw spelling => canonical key */
    public function cityAliases(): array
    {
        return $this->aliasMap();
    }

    public function normalizeCityKey(string $city): string
    {
        $key = strtolower(trim($city));
        $aliases = $this->aliasMap();
        return $aliases[$key] ?? $key;
    }

    /** The one alias table (kept private so both helpers above share it). */
    private function aliasMap(): array
    {
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
        return $aliases;
    }

    public function availabilityProductIdQuery(string $city, bool $localOnly = false)
    {
        $key = $this->normalizeCityKey($city);
        // Rollup rows only: id-scoping wants one row per product, and every
        // variant row's coverage flags mirror its rollup anyway.
        $q = ProductCityAvailability::query()->select('product_id')
            ->where('city', $key)
            ->where('variation_option_id', 0)
            // What gives a manual stock override teeth: an operator who sets 0
            // takes the product off the shelf IN THIS CITY. NULL means untracked
            // (unlimited), and the recompute never writes a computed 0 — every
            // out-of-stock tracked row is filtered out before aggregation — so
            // this excludes nothing that wasn't deliberately zeroed by a human.
            ->whereRaw('COALESCE(stock_override, stock) IS NULL OR COALESCE(stock_override, stock) > 0');
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

    /**
     * Whether ANY nursery currently supplies anything in this city (projection-backed).
     * Display-only policy: a serviceable city with no supply shows the catalog but is
     * NOT orderable. Fail-open TRUE — a fault must never block live ordering.
     */
    public function cityHasSupply(string $city): bool
    {
        try {
            $key = $this->normalizeCityKey($city);
            if ($key === '') {
                return true;
            }
            return $this->availabilityProductIdQuery($key, false)->exists();
        } catch (\Throwable $e) {
            return true;
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
