<?php

namespace Marvel\Services;

use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorServiceArea;
use Marvel\Database\Models\VendorShippingRate;

/**
 * Per-item vendor assignment scoring (the heart of P3). For one master product + size +
 * quantity in a customer's city/pincode, it ranks the vendors that can fulfil it, in the
 * priority order: 1 inventory, 2 pincode coverage, 3 delivery SLA, 4 vendor rating,
 * 5 vendor priority, 6 shipping cost, then 7 admin override (handled at the controller).
 *
 * Hard filters (a candidate must pass): in stock for the quantity, and serves the city
 * (locally, by courier, or courier-capable nationally). LOCAL always outranks COURIER
 * ("fast local first"); within a tier the weighted score decides. The customer never
 * sees any of this — it drives admin assignment + the (hidden) checkout estimate.
 */
class ItemAssignmentService
{
    private const DEFAULT_LOCAL_ETA = 2;
    private const DEFAULT_COURIER_ETA = 5;

    private array $weights;
    private int $targetSla;

    // Per-request memoization (keyed by shop_id) so a multi-line cart loads each vendor once.
    private array $shopCache = [];
    private array $areaCache = [];
    private array $rateCache = [];

    // Optional warm-start memo for the per-line Operations gate. Populated by warm() so a
    // whole-cart pass resolves "stop_deliveries" + per-(vertical,city) availability ONCE
    // instead of N times. When unset (the default), candidatesFor behaves byte-identically.
    private ?array $platformFlagsMemo = null;   // ['stop_deliveries' => bool, ...]
    private ?array $slugByProductMemo = null;    // productId => type slug
    private array $availabilityMemo = [];        // "slug|cityN" => bool available

    public function __construct(private ?AvailabilityService $availability = null)
    {
        $this->availability = $availability ?: new AvailabilityService();
        $settings = Settings::getData(); // may be null on a blank install
        $opts = (array) (($settings?->options['assignment'] ?? []) ?: []);
        $this->weights = [
            'inv'      => (float) ($opts['w_inv'] ?? 0.10),
            'pincode'  => (float) ($opts['w_pincode'] ?? 0.15),
            'sla'      => (float) ($opts['w_sla'] ?? 0.20),
            'rating'   => (float) ($opts['w_rating'] ?? 0.15),
            'priority' => (float) ($opts['w_priority'] ?? 0.15),
            'shipping' => (float) ($opts['w_shipping'] ?? 0.25),
            // Prefer the CHEAPEST vendor rate: the customer price is fixed per city
            // (max rate + margin), so a lower assigned rate = higher platform margin.
            'rate'     => (float) ($opts['w_rate'] ?? 0.15),
        ];
        $this->targetSla = max(1, (int) ($opts['target_sla_days'] ?? 3));
    }

    /**
     * Whole-cart warm-start for the Delivery Optimizer (and any multi-line caller): resolve
     * the Operations gate ONCE for every product/vertical at the given city, so the
     * per-line candidatesFor() calls hit O(1) memo reads instead of re-running
     * ServiceAvailabilityService N times. Purely additive — never changes outcomes, only
     * collapses repeated work. Safe to call more than once.
     *
     * @param int[] $productIds
     */
    public function warm(array $productIds, ?string $city): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return;
        }
        try {
            $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
            $this->platformFlagsMemo = (array) $svc->platformFlags();

            // products -> type slug, in one query.
            $this->slugByProductMemo = \Marvel\Database\Models\Product::whereIn('products.id', $productIds)
                ->join('types', 'products.type_id', '=', 'types.id')
                ->pluck('types.slug', 'products.id')
                ->map(fn ($s) => (string) $s)
                ->all();

            if (!empty($city)) {
                $cityN = $this->norm($city);
                foreach (array_values(array_unique($this->slugByProductMemo)) as $slug) {
                    if ($slug === '') {
                        continue;
                    }
                    $this->availabilityMemo["{$slug}|{$cityN}"] = (bool) $svc->resolve($slug, (string) $city)['available'];
                }
            }
        } catch (\Throwable $e) {
            // fail open — leave memos as-is; candidatesFor falls back to live resolution.
            $this->platformFlagsMemo = null;
            $this->slugByProductMemo = null;
        }
    }

    /**
     * Ranked vendor candidates for one line. Returns [] when nobody can fulfil it.
     * Each candidate: shop_id, vendor_name, selling_price, available_qty, fulfillment_mode,
     * pincode_covered, sla_days, eta_days, rating, priority, shipping_cost, score, recommended.
     */
    public function candidatesFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): array
    {
        $qty = max(1, $qty);
        $cityN = $this->norm($city);

        // Operations Control Center — no vendor / delivery assignment when the
        // "Stop All Deliveries" kill-switch is engaged, or for a vertical that's
        // unavailable in the city. FAIL OPEN (no city / error). When warm() has been
        // called, the flags / slug / availability come from memo (one resolution per cart).
        try {
            $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
            $flags = $this->platformFlagsMemo ?? (array) $svc->platformFlags();
            if (!empty($flags['stop_deliveries'])) {
                return [];
            }
            if (!empty($city)) {
                $slug = $this->slugByProductMemo[$productId]
                    ?? \Marvel\Database\Models\Product::where('products.id', $productId)
                        ->join('types', 'products.type_id', '=', 'types.id')
                        ->value('types.slug');
                if ($slug) {
                    $memoKey = "{$slug}|{$cityN}";
                    $available = array_key_exists($memoKey, $this->availabilityMemo)
                        ? $this->availabilityMemo[$memoKey]
                        : $svc->resolve($slug, (string) $city)['available'];
                    if (!$available) {
                        return [];
                    }
                }
            }
        } catch (\Throwable $e) {
            // fail open
        }

        $vendors = $this->availability->vendorsForProduct($productId, $variationOptionId);
        if (empty($vendors)) {
            return [];
        }

        // Memoized across lines of one request: a handful of vendors recur over a whole
        // cart, so we load each shop's rating / service areas / shipping rates ONCE.
        $shopIds = array_values(array_unique(array_map(fn ($v) => (int) $v['shop_id'], $vendors)));
        $shops = $this->loadShops($shopIds);
        $areas = $this->loadAreas($shopIds);
        $rates = $this->loadRates($shopIds);

        $candidates = [];
        foreach ($vendors as $v) {
            $shopId = (int) $v['shop_id'];

            // Hard filter 1 — inventory. An untracked row (track_stock = false) is always
            // sellable; a tracked row must have enough free stock for this line's quantity.
            $stockQty = (int) ($v['stock_qty'] ?? 0);
            $availQty = (int) ($v['available_qty'] ?? 0);
            $trackStock = !empty($v['track_stock']);
            $inStock = !empty($v['is_available']) && (!$trackStock || $availQty >= $qty);
            if (!$inStock) {
                continue;
            }

            // Hard filter 2 — serves the city (local first, then courier, then national courier).
            $area = $this->matchArea($areas[$shopId] ?? collect(), $cityN, $pincode);
            if ($area['mode'] === null) {
                continue; // cannot reach this customer
            }
            $mode = $area['mode']; // 'local' | 'courier'
            $cityMatched = !empty($area['city_matched']) || !empty($area['pincode_covered']);

            $shop = $shops[$shopId] ?? null;
            $rating = $shop && $shop->vendor_rating !== null ? (float) $shop->vendor_rating : null;
            $priority = $shop ? (int) ($shop->vendor_priority_score ?? 50) : 50;

            // Treat a stored 0 (or null) eta as "unset" so it falls through to the SLA
            // default — a 0-day window would both inflate the score and over-promise.
            $slaDays = ($area['eta_days'] ?: null)
                ?? ($shop && $shop->sla_default_days ? (int) $shop->sla_default_days : null)
                ?? ($mode === 'local' ? self::DEFAULT_LOCAL_ETA : self::DEFAULT_COURIER_ETA);

            $shippingCost = $this->shippingCost($rates[$shopId] ?? collect(), $mode, $area['pincode_covered']);

            $candidates[] = [
                'shop_id'                 => $shopId,
                'vendor_product_price_id' => $v['vendor_product_price_id'] ?? null,
                'vendor_name'             => $v['vendor_name'] ?? null,
                // The vendor's supply rate (their payout when assigned). selling_price is
                // overwritten below with the UNIFORM city price (max rate + margin).
                'vendor_rate'             => isset($v['vendor_rate']) ? (float) $v['vendor_rate'] : null,
                'selling_price'           => $v['selling_price'] ?? null,
                '_city_matched'           => $cityMatched,
                'available_qty'    => $availQty,
                'stock_tracked'    => $trackStock,
                'fulfillment_mode' => $mode,
                'serves_city'      => true,
                'pincode_covered'  => $area['pincode_covered'],
                'sla_days'         => (int) $slaDays,
                'eta_days'         => (int) $slaDays,
                'rating'           => $rating,
                'priority'         => $priority,
                'shipping_cost'    => $shippingCost,
                // raw score parts filled below after we know the max shipping cost
                '_inv'             => !$trackStock ? 0.5 : $this->clamp($availQty / ($qty * 3), 0, 1),
                '_pincode'         => $area['pincode_covered'] ? 1.0 : ($mode === 'local' ? 0.7 : 0.5),
                '_sla'             => 1 - $this->clamp(((int) $slaDays - $this->targetSla) / $this->targetSla, 0, 1),
                '_rating'          => $rating !== null ? $rating / 5 : 0.6,
                '_priority'        => $priority / 100,
            ];
        }

        if (empty($candidates)) {
            return [];
        }

        // Uniform customer selling price for this line: MAX vendor rate among the
        // fulfillable candidates × (1 + margin(city, vertical)). Every candidate carries
        // the SAME price — the vendor choice changes the platform's margin, never the
        // customer's price. margin_percent rides along for order snapshots.
        // The rate pool mirrors PricingService::coveredRows exactly: vendors whose
        // service area COVERS the city define the price; national-courier fallback
        // candidates only price the line when nobody covers the city (otherwise one
        // expensive courier-anywhere vendor would inflate every city's price).
        $ratePool = array_filter($candidates, fn ($c) => !empty($c['_city_matched']));
        if (empty($ratePool)) {
            $ratePool = $candidates;
        }
        $rates = array_values(array_filter(
            array_map(fn ($c) => $c['vendor_rate'], $ratePool),
            fn ($r) => $r !== null
        ));
        $sellingPrice = null;
        $marginPercent = null;
        if (!empty($rates)) {
            $typeId = \Marvel\Database\Models\Product::where('id', $productId)->value('type_id');
            $marginPercent = (new MarginResolver())->marginPercent($cityN !== '' ? $cityN : null, $typeId ? (int) $typeId : null);
            $sellingPrice = round(max($rates) * (1 + $marginPercent / 100), 2);
        }

        $maxShipping = max(array_map(fn ($c) => $c['shipping_cost'], $candidates)) ?: 0.0;
        // Rate-normalization bounds span ALL candidates (a pricey courier-fallback
        // vendor must still score worse), independent of the selling-price pool.
        $allRates = array_values(array_filter(array_map(fn ($c) => $c['vendor_rate'], $candidates), fn ($r) => $r !== null));
        $minRate = !empty($allRates) ? min($allRates) : 0.0;
        $maxRate = !empty($allRates) ? max($allRates) : 0.0;
        foreach ($candidates as &$c) {
            $c['selling_price']  = $sellingPrice ?? ($c['selling_price'] ?? null);
            $c['margin_percent'] = $marginPercent;
            $shipNorm = $maxShipping > 0 ? $this->clamp($c['shipping_cost'] / $maxShipping, 0, 1) : 0.0;
            // Normalize the rate within THIS candidate set (0 = cheapest, 1 = priciest)
            // and subtract like shipping — a cheaper vendor rate scores higher.
            $rateNorm = ($maxRate > $minRate && $c['vendor_rate'] !== null)
                ? $this->clamp(($c['vendor_rate'] - $minRate) / ($maxRate - $minRate), 0, 1)
                : 0.0;
            $c['score'] = round(
                $this->weights['inv'] * $c['_inv']
                + $this->weights['pincode'] * $c['_pincode']
                + $this->weights['sla'] * $c['_sla']
                + $this->weights['rating'] * $c['_rating']
                + $this->weights['priority'] * $c['_priority']
                - $this->weights['shipping'] * $shipNorm
                - $this->weights['rate'] * $rateNorm,
                4
            );
            unset($c['_inv'], $c['_pincode'], $c['_sla'], $c['_rating'], $c['_priority'], $c['_city_matched']);
        }
        unset($c);

        // LOCAL tier first (fast local), then by score desc, then cheaper vendor rate
        // (higher platform margin — the customer price is identical either way).
        usort($candidates, function ($a, $b) {
            $ta = $a['fulfillment_mode'] === 'local' ? 0 : 1;
            $tb = $b['fulfillment_mode'] === 'local' ? 0 : 1;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return ($a['vendor_rate'] ?? INF) <=> ($b['vendor_rate'] ?? INF);
        });

        $candidates[0]['recommended'] = true;
        for ($i = 1; $i < count($candidates); $i++) {
            $candidates[$i]['recommended'] = false;
        }
        return $candidates;
    }

    /**
     * The single best vendor for a line (auto-assign + checkout estimate), or null.
     * candidatesFor() applies the hard filters + scoring; the configured
     * VendorSelectionStrategy (default 'cheapest') then picks the winner. Swapping
     * strategy (settings.options.assignment.strategy) never touches this pipeline.
     */
    public function bestFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): ?array
    {
        $candidates = $this->candidatesFor($productId, $variationOptionId, $qty, $city, $pincode);
        if (empty($candidates)) {
            return null;
        }
        $strategy = \Marvel\Services\VendorSelection\VendorSelectionManager::make();
        return $strategy->select($candidates, [
            'product_id'          => $productId,
            'variation_option_id' => $variationOptionId,
            'qty'                 => $qty,
            'city'                => $city,
            'pincode'             => $pincode,
        ]);
    }

    /**
     * Best service-area match: exact pincode > city (local) > city (courier) > national courier.
     * `city_matched` distinguishes a declared city/pincode area from the national-courier
     * fallback — the uniform selling price is derived from city-covering vendors only.
     * @return array{mode: ?string, eta_days: ?int, pincode_covered: bool, city_matched: bool}
     */
    private function matchArea($areas, string $cityN, ?string $pincode): array
    {
        $pincode = $pincode ? trim($pincode) : null;
        $localCity = null;
        $courierCity = null;
        $courierAny = null;

        foreach ($areas as $a) {
            $mode = $a->fulfillment_mode; // local | courier | both
            $cityMatch = $this->norm($a->city) === $cityN && $cityN !== '';
            $pinMatch = $pincode && $a->pincode && trim((string) $a->pincode) === $pincode;

            if ($pinMatch) {
                $m = in_array($mode, ['local', 'both'], true) ? 'local' : 'courier';
                return ['mode' => $m, 'eta_days' => $a->eta_days, 'pincode_covered' => true, 'city_matched' => true];
            }
            if ($cityMatch && in_array($mode, ['local', 'both'], true) && $localCity === null) {
                $localCity = $a;
            }
            if ($cityMatch && in_array($mode, ['courier', 'both'], true) && $courierCity === null) {
                $courierCity = $a;
            }
            if (in_array($mode, ['courier', 'both'], true) && $courierAny === null) {
                $courierAny = $a;
            }
        }

        if ($localCity) {
            return ['mode' => 'local', 'eta_days' => $localCity->eta_days, 'pincode_covered' => false, 'city_matched' => true];
        }
        if ($courierCity) {
            return ['mode' => 'courier', 'eta_days' => $courierCity->eta_days, 'pincode_covered' => false, 'city_matched' => true];
        }
        if ($courierAny) {
            return ['mode' => 'courier', 'eta_days' => $courierAny->eta_days, 'pincode_covered' => false, 'city_matched' => false];
        }
        return ['mode' => null, 'eta_days' => null, 'pincode_covered' => false, 'city_matched' => false];
    }

    /** Shipping cost for a mode from the vendor's rate rows (zone by locality), else 0. */
    private function shippingCost($rates, string $mode, bool $pincodeCovered): float
    {
        if ($rates->isEmpty()) {
            return 0.0;
        }
        $zone = $mode === 'local' ? 'same_city' : 'national';
        $row = $rates->first(fn ($r) => $r->fulfillment_mode === $mode && $r->zone === $zone)
            ?: $rates->first(fn ($r) => $r->fulfillment_mode === $mode)
            ?: $rates->first();
        return $row ? (float) $row->base_cost : 0.0;
    }

    /**
     * Per-kg shipping rate for (shop, mode), reusing the SAME memoised rate rows that
     * candidatesFor already batch-loaded via loadRates — so the Delivery Optimizer can attach
     * the per-kg term without issuing its own per-shop query (no N+1). Public companion to the
     * private shippingCost(); zone selection is identical.
     */
    public function perKgCostFor(int $shopId, string $mode): float
    {
        $rates = $this->loadRates([$shopId])->get($shopId) ?? collect();
        if ($rates->isEmpty()) {
            return 0.0;
        }
        $zone = $mode === 'local' ? 'same_city' : 'national';
        $row = $rates->first(fn ($r) => $r->fulfillment_mode === $mode && $r->zone === $zone)
            ?: $rates->first(fn ($r) => $r->fulfillment_mode === $mode)
            ?: $rates->first();
        return $row ? (float) ($row->per_kg_cost ?? 0) : 0.0;
    }

    /** @return \Illuminate\Support\Collection keyed by shop id (cached). */
    private function loadShops(array $shopIds)
    {
        $missing = array_values(array_diff($shopIds, array_keys($this->shopCache)));
        if (!empty($missing)) {
            foreach (Shop::whereIn('id', $missing)->get() as $shop) {
                $this->shopCache[(int) $shop->id] = $shop;
            }
            foreach ($missing as $id) { // remember misses so we don't re-query them
                $this->shopCache[$id] = $this->shopCache[$id] ?? null;
            }
        }
        return collect($this->shopCache)->only($shopIds);
    }

    /** @return \Illuminate\Support\Collection of service areas grouped by shop id (cached). */
    private function loadAreas(array $shopIds)
    {
        $missing = array_values(array_diff($shopIds, array_keys($this->areaCache)));
        if (!empty($missing)) {
            $grouped = VendorServiceArea::whereIn('shop_id', $missing)->where('is_active', true)->get()->groupBy('shop_id');
            foreach ($missing as $id) {
                $this->areaCache[$id] = $grouped[$id] ?? collect();
            }
        }
        return collect($this->areaCache)->only($shopIds);
    }

    /** @return \Illuminate\Support\Collection of shipping rates grouped by shop id (cached). */
    private function loadRates(array $shopIds)
    {
        $missing = array_values(array_diff($shopIds, array_keys($this->rateCache)));
        if (!empty($missing)) {
            $grouped = VendorShippingRate::whereIn('shop_id', $missing)->where('is_active', true)->get()->groupBy('shop_id');
            foreach ($missing as $id) {
                $this->rateCache[$id] = $grouped[$id] ?? collect();
            }
        }
        return collect($this->rateCache)->only($shopIds);
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private function norm(?string $s): string
    {
        return strtolower(trim((string) $s));
    }
}
