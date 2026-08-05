<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\State;
use Marvel\Database\Models\Warehouse;
use Marvel\Exceptions\MarvelException;

/**
 * Master Location System (Phase 2). Public lookups feed the State→City address
 * dropdowns; the admin CRUD + City Activation Engine (super-admin) manage the
 * canonical states/cities/warehouses and gate serviceability by city status.
 */
class LocationController extends CoreController
{
    // ── Public lookups (address dropdowns + storefront) ─────────────────────
    public function states(Request $request)
    {
        return State::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    public function cities(Request $request)
    {
        // Delivery Coverage — cities can be filtered by their district once the
        // geo-master migration has landed (guarded: the column arrives with the
        // Serviceability module's migrations, which can lag the code deploy).
        $hasDistrict = \Illuminate\Support\Facades\Schema::hasColumn('cities', 'district_id');

        $query = City::query()
            ->when($request->filled('state_id'), fn ($q) => $q->where('state_id', $request->state_id))
            ->when($request->filled('state'), fn ($q) => $q->where('state_name', $request->state))
            ->when($hasDistrict && $request->filled('district_id'), fn ($q) => $q->where('district_id', $request->district_id))
            ->when($request->boolean('serviceable'), fn ($q) => $q->where('is_serviceable', true)->whereIn('status', [City::STATUS_ACTIVE, City::STATUS_MAINTENANCE]));

        $columns = ['id', 'name', 'state_id', 'state_name', 'status', 'is_serviceable', 'lat', 'lng'];
        if ($hasDistrict) {
            $columns[] = 'district_id';
        }

        return $query->orderBy('name')->get($columns);
    }

    /**
     * Public: active districts for a state (coverage pickers + address forms).
     * Cached under the geo master version (bumped by any geo admin write).
     */
    public function districts(Request $request)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('districts')) {
            return [];
        }
        $stateId = (int) $request->query('state_id', 0);
        $ver = (int) \Illuminate\Support\Facades\Cache::get('geo:ver', 0);

        return \Illuminate\Support\Facades\Cache::remember(
            "locations:districts:v{$ver}:{$stateId}",
            3600,
            fn () => \Illuminate\Support\Facades\DB::table('districts')
                ->where('is_active', true)
                ->when($stateId > 0, fn ($q) => $q->where('state_id', $stateId))
                ->orderBy('name')
                ->get(['id', 'state_id', 'name', 'code'])
                ->all()
        );
    }

    /**
     * Public: postal-code lookup (paginated) by district, city or free-text
     * pincode/office search — powers the coverage pickers.
     */
    public function postalCodes(Request $request)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('postal_codes')) {
            return response()->json(['data' => []]);
        }
        $search = trim((string) $request->query('search', ''));

        return \Illuminate\Support\Facades\DB::table('postal_codes')
            ->where('status', 'active')
            ->when($request->filled('district_id'), fn ($q) => $q->where('district_id', (int) $request->district_id))
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', (int) $request->city_id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('pincode', 'like', $search . '%')
                ->orWhere('office_name', 'like', '%' . $search . '%')))
            ->orderBy('pincode')
            ->paginate(min(100, max(1, (int) ($request->limit ?? 50))));
    }

    // ── Admin: states ───────────────────────────────────────────────────────
    public function stateIndex(Request $request)
    {
        return State::withCount('cities')->orderBy('name')->paginate((int) ($request->limit ?? 50));
    }

    public function stateStore(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255|unique:states,name',
            'code'      => 'nullable|string|max:8',
            'is_active' => 'nullable|boolean',
        ]);
        return State::create($data);
    }

    public function stateUpdate(Request $request, $id)
    {
        $state = State::findOrFail($id);
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255', Rule::unique('states', 'name')->ignore($state->id)],
            'code'      => 'nullable|string|max:8',
            'is_active' => 'nullable|boolean',
        ]);
        $state->update($data);
        return $state;
    }

    public function stateDestroy($id)
    {
        $state = State::findOrFail($id);
        $state->delete();
        return $state;
    }

    // ── Admin: cities + City Activation Engine ──────────────────────────────
    public function cityIndex(Request $request)
    {
        $query = City::with('state:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('state_id'), fn ($q) => $q->where('state_id', $request->state_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status));

        return $query->orderBy('display_order')->orderBy('name')->paginate((int) ($request->limit ?? 30));
    }

    public function cityShow($id)
    {
        return City::with(['state:id,name', 'warehouses'])->findOrFail($id);
    }

    public function cityStore(Request $request)
    {
        $data = $this->validateCity($request);
        $city = City::create($data);
        $this->bustServiceAvailability();
        return $city;
    }

    public function cityUpdate(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $city->update($this->validateCity($request, $city->id));
        $this->bustServiceAvailability();
        return $city->fresh('state');
    }

    public function cityDestroy($id)
    {
        $city = City::findOrFail($id);
        $city->delete();
        $this->bustServiceAvailability();
        return $city;
    }

    /**
     * Operations Control Center — a city's status/serviceability is Tier-2 of
     * the availability resolver (cached). Any city mutation must bump both the
     * availability map version AND the city-scoped product-list cache so the
     * change takes effect immediately (not after the 300s TTL).
     */
    protected function bustServiceAvailability(): void
    {
        app(\Marvel\Services\ServiceAvailabilityService::class)->bust();
        \Marvel\Services\AvailabilityService::bustCatalogCache();
    }

    /** City Activation Engine — flip a city's operational state. */
    public function citySetStatus(Request $request, $id)
    {
        $request->validate(['status' => ['required', Rule::in(City::STATUSES)]]);
        $city = City::findOrFail($id);
        $old = ['status' => $city->status, 'is_serviceable' => (bool) $city->is_serviceable];
        $city->status = $request->input('status');
        // Disabling a city also makes it non-serviceable; re-enabling restores it.
        if ($city->status === City::STATUS_DISABLED) {
            $city->is_serviceable = false;
        } elseif ($city->status === City::STATUS_ACTIVE) {
            $city->is_serviceable = true;
        }
        $city->save();
        // Audit through the same event every other availability change uses —
        // this flip can silence an entire city and used to leave no trace.
        // The listener also busts the caches, so no separate bust needed; the
        // explicit call stays as belt-and-braces (it's idempotent).
        event(new \Marvel\Events\ServiceAvailabilityChanged(
            'city',
            (string) $city->id,
            $old,
            ['status' => $city->status, 'is_serviceable' => (bool) $city->is_serviceable],
            'city_status_change',
            $request->user()?->id,
            $request->ip(),
        ));
        $this->bustServiceAvailability();
        return $city->fresh('state');
    }

    /**
     * GET cities/{id}/vendors — every vendor serving this city, with catalogue
     * and inventory counts. Powers the City Command Center vendor section.
     * Reads only the projection/service-area tables — no per-vendor N+1.
     */
    public function cityVendors(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $availability = app(\Marvel\Services\AvailabilityService::class);
        // Same canonical key the projection is built with — a bare strtolower
        // would miss a vendor whose service area says "Gurgaon" for a city row
        // named "Gurugram".
        $key = $availability->normalizeCityKey((string) $city->name);

        $shopIds = $this->shopIdsServingCity($key);

        $perPage = min(50, max(5, (int) $request->input('limit', 20)));
        $shops = Shop::whereIn('id', $shopIds)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->input('search') . '%'))
            ->select('id', 'name', 'slug', 'logo', 'is_active', 'approval_status', 'vendor_rating', 'contact_person', 'mobile')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate($perPage);

        // Counts in ONE grouped query, scoped exactly like the projection:
        // effective() window + is_available. Without those, an expired price
        // sheet counts as live catalogue and this panel disagrees with the
        // product list rendered right beside it.
        $pageShopIds = $shops->pluck('id');
        $productCounts = $availability->effective(
            \Marvel\Database\Models\VendorProductPrice::whereIn('shop_id', $pageShopIds)
                ->where('is_available', true)
        )
            ->select(
                'shop_id',
                DB::raw('COUNT(DISTINCT product_id) as products'),
                DB::raw('SUM(CASE WHEN track_stock = 1 THEN GREATEST(stock_qty - reserved_qty, 0) ELSE 0 END) as stock'),
                // Any untracked row means this vendor's stock is effectively
                // unlimited — the panel renders ∞ rather than a misleading 0.
                DB::raw('SUM(CASE WHEN track_stock = 1 THEN 0 ELSE 1 END) as untracked_rows')
            )
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        $vendorOrders = $this->vendorOrderStats($pageShopIds, $key);

        $shops->getCollection()->transform(function ($s) use ($productCounts, $vendorOrders) {
            $c = $productCounts[$s->id] ?? null;
            $o = $vendorOrders[$s->id] ?? null;
            $s->setAttribute('product_count', (int) ($c->products ?? 0));
            $s->setAttribute('tracked_stock', ($c && (int) $c->untracked_rows > 0) ? null : (int) ($c->stock ?? 0));
            $s->setAttribute('orders_today', (int) ($o->orders_today ?? 0));
            $s->setAttribute('orders_pending', (int) ($o->orders_pending ?? 0));
            $s->setAttribute('revenue_30d', (float) ($o->revenue_30d ?? 0));
            return $s;
        });

        return $shops;
    }

    /** Shop ids with an ACTIVE service area in this canonical city key. */
    private function shopIdsServingCity(string $key)
    {
        // Service areas store what the vendor typed ("Gurgaon"); the key is
        // canonical ("gurugram"). Match every spelling that normalises to it.
        $variants = app(\Marvel\Services\AvailabilityService::class)->cityKeyVariants($key);

        return \Marvel\Database\Models\VendorServiceArea::whereIn(DB::raw('LOWER(city)'), $variants)
            ->where('is_active', true)
            ->distinct()
            ->pluck('shop_id');
    }

    /**
     * Orders today / pending / 30-day revenue for a page of vendors, in ONE
     * grouped query. Vendor work lives on CHILD orders (shop_id) while the
     * delivery city lives on the PARENT's shipping address, so this joins the
     * two — the same shape AnalyticsController::topVendors uses, scoped to a
     * city and a known shop set instead of a global top-N.
     */
    private function vendorOrderStats($shopIds, string $cityKey)
    {
        if (count($shopIds) === 0) {
            return collect();
        }
        try {
            $variants = app(\Marvel\Services\AvailabilityService::class)->cityKeyVariants($cityKey);
            $cityExpr = "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(parents.shipping_address, '$.city'))), ''), "
                . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(parents.shipping_address, '$.address.city'))), ''), '')";

            return DB::table('orders as child')
                ->join('orders as parents', 'parents.id', '=', 'child.parent_id')
                ->whereIn('child.shop_id', $shopIds)
                ->whereNull('child.deleted_at')
                ->whereIn(DB::raw("LOWER($cityExpr)"), $variants)
                ->select(
                    'child.shop_id',
                    DB::raw('SUM(CASE WHEN DATE(child.created_at) = CURDATE() THEN 1 ELSE 0 END) as orders_today'),
                    DB::raw("SUM(CASE WHEN child.order_status IN ('order-pending','order-processing') THEN 1 ELSE 0 END) as orders_pending"),
                    DB::raw("SUM(CASE WHEN child.order_status = 'order-completed' AND child.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN child.paid_total ELSE 0 END) as revenue_30d")
                )
                ->groupBy('child.shop_id')
                ->get()
                ->keyBy('shop_id');
        } catch (\Throwable $e) {
            // An ops panel must never 500 over a metric — render zeros instead.
            return collect();
        }
    }

    /**
     * GET cities/{id}/products — the catalogue as this city sees it, straight
     * from the product_city_availability rollup rows (variant 0): city price,
     * aggregated stock, vendor count. Admin-only; vendor names come from the
     * existing catalog-product-vendors endpoint per product.
     */
    public function cityProducts(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $availability = app(\Marvel\Services\AvailabilityService::class);
        // Canonical key — see cityVendors. The projection is written with this
        // normalizer, so reading it with a bare strtolower silently returns an
        // empty catalogue for every aliased city (Gurgaon/Gurugram, Delhi NCT).
        $key = $availability->normalizeCityKey((string) $city->name);

        $perPage = min(50, max(5, (int) $request->input('limit', 20)));
        // The number an operator acts on is the OVERRIDE when one is set —
        // filter and sort on the same value the row renders, never raw stock.
        $effectiveStock = 'COALESCE(product_city_availability.stock_override, product_city_availability.stock)';
        $q = \Marvel\Database\Models\ProductCityAvailability::query()
            ->where('city', $key)
            ->where('variation_option_id', 0)
            ->join('products', 'products.id', '=', 'product_city_availability.product_id')
            ->select(
                'product_city_availability.id',
                'product_city_availability.product_id',
                'product_city_availability.min_price',
                // The price a customer in this city actually sees. Equal to
                // min_price on the rollup today; selected explicitly so the
                // panel keeps showing the right number if they ever diverge.
                'product_city_availability.display_price',
                'product_city_availability.stock',
                'product_city_availability.stock_override',
                'product_city_availability.vendor_count',
                'product_city_availability.has_local',
                'product_city_availability.has_courier',
                'product_city_availability.updated_at',
                'products.name',
                'products.slug',
                'products.image',
                'products.status',
                'products.product_type',
            );

        if ($request->filled('search')) {
            $q->where('products.name', 'like', '%' . $request->input('search') . '%');
        }
        // Insight filters for the ops board — cheap flags on the projection.
        if ($request->input('filter') === 'single_vendor') {
            $q->where('vendor_count', 1);
        } elseif ($request->input('filter') === 'low_stock') {
            $q->whereRaw("$effectiveStock IS NOT NULL")
                ->whereRaw("$effectiveStock <= ?", [(int) $request->input('threshold', 5)]);
        }

        $page = $q->orderByDesc('product_city_availability.updated_at')->paginate($perPage);

        // Vendor names: ONE grouped query for the whole page (never per row —
        // catalog-product-vendors/{id} is 3 queries each and isn't city-scoped).
        $vendors = $availability->vendorNamesForProducts(
            $page->pluck('product_id')->all(),
            $key,
        );
        $page->getCollection()->transform(function ($row) use ($vendors) {
            $row->setAttribute('vendors', $vendors[$row->product_id] ?? []);
            return $row;
        });

        return $page;
    }

    /**
     * PUT cities/{id}/products/{productId}/stock — manual inventory override for
     * one product in one city (the "Manual" aggregation strategy, and the escape
     * hatch when vendor-reported stock is known wrong).
     *
     * Writes the ROLLUP row only. The override survives recompute because
     * recomputeForProduct's updateOrCreate never touches stock_override — it
     * only rewrites the computed `stock`. Send null to clear and fall back to
     * the aggregate.
     */
    public function citySetProductStock(Request $request, $id, $productId)
    {
        $city = City::findOrFail($id);
        $data = $request->validate([
            'stock_override' => 'present|nullable|integer|min:0|max:1000000',
        ]);

        $availability = app(\Marvel\Services\AvailabilityService::class);
        $key = $availability->normalizeCityKey((string) $city->name);

        $row = \Marvel\Database\Models\ProductCityAvailability::where('product_id', (int) $productId)
            ->where('city', $key)
            ->where('variation_option_id', 0)
            ->first();
        if (!$row) {
            throw new \Marvel\Exceptions\MarvelException('This product has no supply in this city.');
        }

        $row->stock_override = $data['stock_override'];
        $row->save();

        // The storefront caches availability by a version key — without this the
        // override sits in the DB and the customer keeps seeing the old number.
        $this->bustServiceAvailability();

        return $row;
    }

    /**
     * GET cities/{id}/metrics — the City Command Center header numbers.
     *
     * Everything here is a count over an indexed column; there is no per-row
     * work and no N+1. Numbers that HAVE no data source return null rather than
     * a plausible-looking zero — the panel renders "—" and the operator knows
     * it was never measured (population and service radius are manual fields in
     * cities.settings; there is no source for them).
     */
    public function cityMetrics(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $availability = app(\Marvel\Services\AvailabilityService::class);
        $key = $availability->normalizeCityKey((string) $city->name);
        $variants = $availability->cityKeyVariants($key);

        $areas = \Marvel\Database\Models\VendorServiceArea::whereIn(DB::raw('LOWER(city)'), $variants);
        $shopIds = (clone $areas)->where('is_active', true)->distinct()->pluck('shop_id');

        $vendors = Shop::whereIn('id', $shopIds)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as on_hold', [Shop::STATUS_ON_HOLD])
            ->first();

        // Live products come from the PROJECTION (what a customer can actually
        // buy here). Pending/draft products have no projection row at all, so
        // they're counted off `products` — a projection count would report 0
        // and read as "nothing pending" when the queue is full.
        $liveProducts = \Marvel\Database\Models\ProductCityAvailability::where('city', $key)
            ->where('variation_option_id', 0)->count();
        $pendingProducts = \Marvel\Database\Models\Product::where('status', '!=', 'publish')->count();

        $orders = $this->cityOrderTotals($variants);

        $settings = (array) ($city->settings ?? []);

        return [
            'city'            => ['id' => $city->id, 'name' => $city->name, 'status' => $city->status],
            'vendors'         => [
                'total'    => (int) ($vendors->total ?? 0),
                'active'   => (int) ($vendors->active ?? 0),
                'inactive' => (int) ($vendors->total ?? 0) - (int) ($vendors->active ?? 0),
                'on_hold'  => (int) ($vendors->on_hold ?? 0),
            ],
            'products'        => [
                'live'    => $liveProducts,
                'pending' => $pendingProducts,
            ],
            'orders'          => $orders,
            // Distinct pincodes this city's vendors actually cover — the honest
            // "coverage area" number. A NULL pincode means city-wide, so it is
            // deliberately not counted as a pincode.
            'coverage'        => [
                'pincodes' => (clone $areas)->where('is_active', true)->whereNotNull('pincode')->distinct()->count('pincode'),
                'radius_km' => $settings['service_radius_km'] ?? null,
            ],
            // No city column exists on delivery_partners. This is the honest
            // proxy — partners who actually delivered here — and it is labelled
            // as such in the UI rather than dressed up as a roster count.
            'delivery_partners' => $this->cityDeliveryPartnerCount($variants),
            'population'      => $settings['population'] ?? null,
        ];
    }

    /** Order counts + revenue for a city, from the parent order's shipping city. */
    private function cityOrderTotals(array $cityVariants): array
    {
        try {
            $cityExpr = "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(orders.shipping_address, '$.city'))), ''), "
                . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(orders.shipping_address, '$.address.city'))), ''), '')";
            $row = DB::table('orders')
                ->whereNull('orders.deleted_at')
                ->whereNull('orders.parent_id') // parents only — children would double-count
                ->whereIn(DB::raw("LOWER($cityExpr)"), $cityVariants)
                ->selectRaw('SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today')
                // Calendar month, not a rolling 30 days — "this month" on a
                // dashboard means the month, and the two disagree by up to 30%.
                ->selectRaw('SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN 1 ELSE 0 END) as this_month')
                ->selectRaw("SUM(CASE WHEN order_status IN ('order-pending','order-processing') THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN order_status = 'order-completed' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN paid_total ELSE 0 END) as revenue_this_month")
                ->first();
            return [
                'today'              => (int) ($row->today ?? 0),
                'this_month'         => (int) ($row->this_month ?? 0),
                'pending'            => (int) ($row->pending ?? 0),
                'revenue_this_month' => (float) ($row->revenue_this_month ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['today' => 0, 'this_month' => 0, 'pending' => 0, 'revenue_this_month' => 0.0];
        }
    }

    private function cityDeliveryPartnerCount(array $cityVariants): ?int
    {
        try {
            $cityExpr = "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(orders.shipping_address, '$.city'))), ''), "
                . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(orders.shipping_address, '$.address.city'))), ''), '')";
            return DB::table('orders')
                ->whereNull('orders.deleted_at')
                ->whereNotNull('delivery_partner_id')
                ->whereIn(DB::raw("LOWER($cityExpr)"), $cityVariants)
                ->distinct()
                ->count('delivery_partner_id');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * GET cities/{id}/insights — the Operational Insights board: every list an
     * operator should act on today, each with a count and the first few rows.
     *
     * One query per insight, all indexed, capped at 10 rows each — this is a
     * "what needs attention" board, not a report. Every list is also reachable
     * as a filtered view, so the cap never hides work.
     */
    public function cityInsights(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $availability = app(\Marvel\Services\AvailabilityService::class);
        $key = $availability->normalizeCityKey((string) $city->name);
        $variants = $availability->cityKeyVariants($key);
        $limit = 10;

        $shopIds = \Marvel\Database\Models\VendorServiceArea::whereIn(DB::raw('LOWER(city)'), $variants)
            ->where('is_active', true)->distinct()->pluck('shop_id');

        $pca = fn () => \Marvel\Database\Models\ProductCityAvailability::where('city', $key)
            ->where('variation_option_id', 0);

        $effectiveStock = 'COALESCE(stock_override, stock)';

        // Low inventory — on the EFFECTIVE stock, so a row an operator has
        // manually overridden reads the same here as it does in the catalogue.
        $lowStock = $pca()
            ->whereRaw("$effectiveStock IS NOT NULL")
            ->whereRaw("$effectiveStock <= ?", [(int) $request->input('threshold', 5)])
            ->join('products', 'products.id', '=', 'product_city_availability.product_id')
            ->select('products.id', 'products.name', 'products.slug', DB::raw("$effectiveStock as stock"))
            ->orderByRaw($effectiveStock)
            ->limit($limit)->get();

        $singleVendor = $pca()->where('vendor_count', 1)
            ->join('products', 'products.id', '=', 'product_city_availability.product_id')
            ->select('products.id', 'products.name', 'products.slug')
            ->limit($limit)->get();

        // Products with NO supply here. Structurally unreachable from the
        // projection — recompute DELETES the row when the last vendor goes — so
        // this is the anti-join, not a filter.
        $noSupply = \Marvel\Database\Models\Product::where('status', 'publish')
            ->whereNotExists(function ($q) use ($key) {
                $q->select(DB::raw(1))->from('product_city_availability')
                    ->whereColumn('product_city_availability.product_id', 'products.id')
                    ->where('product_city_availability.city', $key)
                    ->where('product_city_availability.variation_option_id', 0);
            })
            ->select('id', 'name', 'slug')
            ->limit($limit)->get();

        $inactiveVendors = Shop::whereIn('id', $shopIds)
            ->where(fn ($q) => $q->where('is_active', false)->orWhere('approval_status', Shop::STATUS_ON_HOLD))
            ->select('id', 'name', 'slug', 'is_active', 'approval_status')
            ->limit($limit)->get();

        $awaitingApproval = Shop::whereIn('id', $shopIds)
            ->where('approval_status', 'pending')
            ->select('id', 'name', 'slug', 'created_at')
            ->limit($limit)->get();

        // A variable product with zero variant rows in the projection is
        // mispriced by definition — the customer sees a "from" price with
        // nothing behind it.
        $missingVariants = $pca()
            ->join('products', 'products.id', '=', 'product_city_availability.product_id')
            ->where('products.product_type', 'variable')
            ->whereNotExists(function ($q) use ($key) {
                $q->select(DB::raw(1))->from('product_city_availability as v')
                    ->whereColumn('v.product_id', 'products.id')
                    ->where('v.city', $key)
                    ->where('v.variation_option_id', '>', 0);
            })
            ->select('products.id', 'products.name', 'products.slug')
            ->limit($limit)->get();

        $noImages = \Marvel\Database\Models\Product::whereIn(
            'id',
            $pca()->select('product_id')
        )
            ->where(fn ($q) => $q->whereNull('image')->orWhere('image', '')->orWhere('image', '[]'))
            ->select('id', 'name', 'slug')
            ->limit($limit)->get();

        $recentPriceUpdates = \Marvel\Database\Models\VendorProductPrice::whereIn('shop_id', $shopIds)
            ->where('updated_at', '>=', Carbon::now()->subDays(7))
            ->join('products', 'products.id', '=', 'vendor_product_prices.product_id')
            ->select(
                'products.name',
                'vendor_product_prices.shop_id',
                'vendor_product_prices.updated_at',
                'vendor_product_prices.import_batch_id',
            )
            ->orderByDesc('vendor_product_prices.updated_at')
            ->limit($limit)->get();

        $recentVendorChanges = Shop::whereIn('id', $shopIds)
            ->where('updated_at', '>=', Carbon::now()->subDays(7))
            ->select('id', 'name', 'slug', 'is_active', 'approval_status', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit($limit)->get();

        return [
            'low_stock'             => $lowStock,
            'single_vendor'         => $singleVendor,
            'no_supply'             => $noSupply,
            'inactive_vendors'      => $inactiveVendors,
            'awaiting_approval'     => $awaitingApproval,
            'missing_variants'      => $missingVariants,
            'missing_images'        => $noImages,
            'recent_price_updates'  => $recentPriceUpdates,
            'recent_vendor_changes' => $recentVendorChanges,
        ];
    }

    protected function validateCity(Request $request, $ignoreId = null): array
    {
        $stateId = $request->input('state_id');
        return $request->validate([
            'name'           => [
                'required', 'string', 'max:255',
                Rule::unique('cities', 'name')->where(fn ($q) => $q->where('state_id', $stateId))->ignore($ignoreId),
            ],
            'state_id'       => 'nullable|integer|exists:states,id',
            'state_name'     => 'nullable|string|max:255',
            'lat'            => 'nullable|numeric',
            'lng'            => 'nullable|numeric',
            'status'         => ['nullable', Rule::in(City::STATUSES)],
            'is_serviceable' => 'nullable|boolean',
            'settings'       => 'nullable|array',
            // Per-city MAINTENANCE screen content (rendered by the storefront
            // takeover when status = maintenance). Typed rules, not a blind
            // array passthrough — this is a trust boundary and the strings
            // land in customer-facing UI.
            'settings.maintenance'                => 'nullable|array',
            'settings.maintenance.title'          => 'nullable|string|max:120',
            'settings.maintenance.description'    => 'nullable|string|max:600',
            'settings.maintenance.image'          => 'nullable|url|max:2048',
            'settings.maintenance.until'          => 'nullable|date',
            'settings.maintenance.supportContact' => 'nullable|string|max:120',
            'settings.maintenance.buttonTitle'    => 'nullable|string|max:60',
            'display_order'  => 'nullable|integer',
        ]);
    }

    // ── Admin: districts + postal-code remap (Delivery Coverage geo master) ─
    // Raw-table access (module-owned tables); geo:ver bump invalidates the
    // cached public lookups here and in the V2 geo endpoints.

    public function districtIndex(Request $request)
    {
        return \Illuminate\Support\Facades\DB::table('districts as d')
            ->leftJoin('states as s', 's.id', '=', 'd.state_id')
            ->when($request->filled('state_id'), fn ($q) => $q->where('d.state_id', (int) $request->state_id))
            ->when($request->filled('search'), fn ($q) => $q->where('d.name', 'like', "%{$request->search}%"))
            ->orderBy('d.name')
            ->select('d.*', 's.name as state_name')
            ->paginate((int) ($request->limit ?? 50));
    }

    public function districtStore(Request $request)
    {
        $data = $request->validate([
            'state_id'  => 'required|integer|exists:states,id',
            'name'      => 'required|string|max:255',
            'code'      => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
        ]);
        $exists = \Illuminate\Support\Facades\DB::table('districts')
            ->where('state_id', $data['state_id'])
            ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A district with this name already exists in the state.'], 422);
        }
        $now = now();
        $id = \Illuminate\Support\Facades\DB::table('districts')->insertGetId([
            'state_id'   => (int) $data['state_id'],
            'name'       => $data['name'],
            'code'       => $data['code'] ?? null,
            'is_active'  => (bool) ($data['is_active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->bumpGeoVersion();
        return \Illuminate\Support\Facades\DB::table('districts')->where('id', $id)->first();
    }

    public function districtUpdate(Request $request, $id)
    {
        $table = \Illuminate\Support\Facades\DB::table('districts');
        $district = (clone $table)->where('id', (int) $id)->first();
        if (!$district) {
            return response()->json(['message' => 'District not found.'], 404);
        }
        $data = $request->validate([
            'state_id'  => 'sometimes|integer|exists:states,id',
            'name'      => 'sometimes|string|max:255',
            'code'      => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
        ]);
        $update = array_intersect_key($data, array_flip(['state_id', 'name', 'code', 'is_active']));
        if ($update !== []) {
            (clone $table)->where('id', (int) $id)->update($update + ['updated_at' => now()]);
            $this->bumpGeoVersion();
        }
        return (clone $table)->where('id', (int) $id)->first();
    }

    /** PUT postal-codes/{id} — remap a pin's city (projection city bridge) or flip its status. */
    public function postalCodeUpdate(Request $request, $id)
    {
        $table = \Illuminate\Support\Facades\DB::table('postal_codes');
        $row = (clone $table)->where('id', (int) $id)->first();
        if (!$row) {
            return response()->json(['message' => 'Postal code not found.'], 404);
        }
        $data = $request->validate([
            'city_id' => 'nullable|integer|exists:cities,id',
            'status'  => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $update = [];
        if ($request->exists('city_id')) {
            $update['city_id'] = $data['city_id'] ?? null;
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = $data['status'];
        }
        if ($update !== []) {
            (clone $table)->where('id', (int) $id)->update($update + ['updated_at' => now()]);
            $this->bumpGeoVersion();
        }
        return (clone $table)->where('id', (int) $id)->first();
    }

    /** Invalidate cached geo lookups (public districts/postal endpoints key off geo:ver). */
    protected function bumpGeoVersion(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::increment('geo:ver');
        } catch (\Throwable $e) {
            // cache driver hiccup — stale lookups expire via TTL
        }
    }

    // ── Admin: warehouses ───────────────────────────────────────────────────
    public function warehouseIndex(Request $request)
    {
        return Warehouse::with('city:id,name')
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->city_id))
            ->orderByDesc('id')
            ->paginate((int) ($request->limit ?? 30));
    }

    public function warehouseStore(Request $request)
    {
        return Warehouse::create($this->validateWarehouse($request));
    }

    public function warehouseUpdate(Request $request, $id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update($this->validateWarehouse($request));
        return $warehouse->fresh('city');
    }

    public function warehouseDestroy($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->delete();
        return $warehouse;
    }

    protected function validateWarehouse(Request $request): array
    {
        return $request->validate([
            'name'       => 'required|string|max:255',
            'city_id'    => 'nullable|integer|exists:cities,id',
            'address'    => 'nullable|array',
            'lat'        => 'nullable|numeric',
            'lng'        => 'nullable|numeric',
            'capacity'   => 'nullable|integer|min:0',
            'manager_id' => 'nullable|integer|exists:users,id',
            'is_active'  => 'nullable|boolean',
        ]);
    }
}
