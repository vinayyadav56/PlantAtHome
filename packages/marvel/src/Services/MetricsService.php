<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\Permission;

/**
 * The SINGLE source of truth for the Operations Command Center numbers. Every
 * command-center endpoint delegates here, so metric definitions live in ONE
 * place. Definitions (documented, internally consistent):
 *   revenue = SUM(paid_total) over COMPLETED customer orders (parent_id IS NULL)
 *             — the parent row carries the full order total, so no double-count.
 *   orders  = customer orders (parent_id IS NULL), counted by status.
 * Cities are still free-text (no master cities table yet — Phase 2), extracted
 * from the order's shipping_address JSON.
 */
class MetricsService
{
    /** Minutes a courier's GPS ping stays "fresh" / a session stays "live". */
    private const COURIER_FRESH_MIN = 10;
    private const VISITOR_WINDOW_MIN = 15;
    /** A processing order older than this is flagged "delayed". */
    private const DELAYED_AFTER_HOURS = 24;

    // ── CEO overview ────────────────────────────────────────────────────────
    public function overview(): array
    {
        $todayStart = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $d30 = Carbon::now()->subDays(30);
        $d60 = Carbon::now()->subDays(60);

        $newCustomers = $this->customersCreatedSince($d30);
        $prevCustomers = $this->customersCreatedBetween($d60, $d30);
        $newVendors = $this->vendorsCreatedSince($d30);
        $prevVendors = $this->vendorsCreatedBetween($d60, $d30);

        return [
            'revenue_today'        => $this->revenueSince($todayStart),
            'revenue_month'        => $this->revenueSince($monthStart),
            'orders_today'         => $this->customerOrders()->where('created_at', '>=', $todayStart)->count(),
            'orders_today_by_status' => $this->ordersByStatusSince($todayStart),
            'new_customers'        => $newCustomers,
            'customer_growth_pct'  => $this->growthPct($newCustomers, $prevCustomers),
            'vendors_total'        => $this->vendorsTotal(),
            'new_vendors'          => $newVendors,
            'vendor_growth_pct'    => $this->growthPct($newVendors, $prevVendors),
            'cities_active'        => $this->activeCitiesCount($d30),
            'active_couriers'      => $this->activeCouriersCount(),
            'live_visitors'        => $this->liveVisitorsApprox(),  // approx — request_logs (GET skipped)
            'generated_at'         => Carbon::now()->toIso8601String(),
        ];
    }

    // ── Live operations ─────────────────────────────────────────────────────
    public function liveOperations(): array
    {
        $todayStart = Carbon::today();
        $delayedBefore = Carbon::now()->subHours(self::DELAYED_AFTER_HOURS);

        $counts = [
            'processing'  => $this->orders()->where('order_status', OrderStatus::PROCESSING)->count(),
            'at_facility' => $this->orders()->where('order_status', OrderStatus::AT_LOCAL_FACILITY)->count(),
            'in_transit'  => $this->orders()->where('order_status', OrderStatus::OUT_FOR_DELIVERY)->count(),
            'delivered_today' => $this->orders()->where('order_status', OrderStatus::COMPLETED)->where('created_at', '>=', $todayStart)->count(),
            'failed'      => $this->orders()->whereIn('order_status', [OrderStatus::FAILED, OrderStatus::CANCELLED])->where('created_at', '>=', Carbon::now()->subDays(1))->count(),
            'delayed'     => $this->orders()
                ->whereIn('order_status', [OrderStatus::PROCESSING, OrderStatus::AT_LOCAL_FACILITY])
                ->where('created_at', '<', $delayedBefore)
                ->count(),
        ];

        $recent = $this->customerOrders()
            ->select('id', 'tracking_number', 'order_status', 'paid_total', 'shipping_address', 'created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn ($o) => [
                'id'              => $o->id,
                'tracking_number' => $o->tracking_number,
                'order_status'    => $o->order_status,
                'paid_total'      => (float) $o->paid_total,
                'city'            => $this->cityFromJson($o->shipping_address),
                'created_at'      => $o->created_at,
            ]);

        return ['counts' => $counts, 'recent' => $recent];
    }

    // ── City health (read-only; enable/disable is Phase 2) ──────────────────
    public function cityHealth(int $limit = 24): array
    {
        $d30 = Carbon::now()->subDays(30);
        $cityExpr = $this->cityExpr('orders');

        $rows = DB::table('orders')
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $d30)
            ->select(
                DB::raw("$cityExpr as city"),
                DB::raw('COUNT(id) as orders'),
                DB::raw('SUM(CASE WHEN order_status = "' . OrderStatus::COMPLETED . '" THEN paid_total ELSE 0 END) as revenue'),
                DB::raw('COUNT(DISTINCT customer_id) as customers')
            )
            ->groupBy('city')
            ->orderByDesc('orders')
            ->limit($limit)
            ->get();

        // Vendors per city (shops whose address city matches). One grouped query.
        // NB: the `shops` table has no `deleted_at` column (not SoftDeletes) —
        // filtering on it 500'd /command-center/city-health in every environment.
        $shopCityExpr = $this->shopCityExpr('shops');
        $vendorsByCity = DB::table('shops')
            ->select(DB::raw("$shopCityExpr as city"), DB::raw('COUNT(id) as vendors'))
            ->groupBy('city')
            ->pluck('vendors', 'city');

        return $rows->map(fn ($r) => [
            'city'      => $r->city ?: 'Unknown',
            'orders'    => (int) $r->orders,
            'revenue'   => (float) $r->revenue,
            'customers' => (int) $r->customers,
            'vendors'   => (int) ($vendorsByCity[$r->city] ?? 0),
            'status'    => 'active', // read-only in v1; real activation = Phase 2 master cities
        ])->all();
    }

    // ── Delivery operations ─────────────────────────────────────────────────
    public function deliveryOps(): array
    {
        $delayedBefore = Carbon::now()->subHours(self::DELAYED_AFTER_HOURS);

        $active = DB::table('orders')->whereNull('deleted_at')
            ->where('order_status', OrderStatus::OUT_FOR_DELIVERY)->count();
        $pending = DB::table('orders')->whereNull('deleted_at')
            ->whereIn('delivery_mode', ['courier_admin', 'courier_dp'])
            ->whereIn('order_status', [OrderStatus::PROCESSING, OrderStatus::AT_LOCAL_FACILITY])
            ->count();
        $delayed = DB::table('orders')->whereNull('deleted_at')
            ->where('order_status', OrderStatus::OUT_FOR_DELIVERY)
            ->where('created_at', '<', $delayedBefore)
            ->count();

        // Best-effort avg fulfilment time (hours) for recently-completed courier orders.
        $avgHours = (float) (DB::table('orders')->whereNull('deleted_at')
            ->where('order_status', OrderStatus::COMPLETED)
            ->whereIn('delivery_mode', ['courier_admin', 'courier_dp'])
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as h'))
            ->value('h') ?? 0);

        // Top couriers by completed deliveries (last 30d).
        $top = [];
        if (Schema::hasColumn('orders', 'delivery_partner_id')) {
            $top = DB::table('orders')
                ->join('delivery_partners', 'delivery_partners.id', '=', 'orders.delivery_partner_id')
                ->whereNull('orders.deleted_at')
                ->where('orders.order_status', OrderStatus::COMPLETED)
                ->where('orders.created_at', '>=', Carbon::now()->subDays(30))
                ->groupBy('delivery_partners.id', 'delivery_partners.full_name')
                ->select(
                    'delivery_partners.id',
                    'delivery_partners.full_name as name',
                    DB::raw('COUNT(orders.id) as deliveries')
                )
                ->orderByDesc('deliveries')
                ->limit(10)
                ->get();
        }

        return [
            'active_deliveries'  => $active,
            'pending_deliveries' => $pending,
            'delayed_deliveries' => $delayed,
            'avg_delivery_hours' => round($avgHours, 1),
            'top_couriers'       => $top,
        ];
    }

    // ── Courier live positions (for the ops map) ────────────────────────────
    public function courierPositions(): array
    {
        $fresh = Carbon::now()->subMinutes(self::COURIER_FRESH_MIN);

        return DB::table('delivery_partners')
            ->whereNull('deleted_at')
            ->where('is_online', true)
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->select('id', 'full_name as name', 'mobile', 'current_lat', 'current_lng', 'location_updated_at')
            ->get()
            ->map(fn ($d) => [
                'id'      => $d->id,
                'name'    => $d->name,
                'mobile'  => $d->mobile,
                'lat'     => (float) $d->current_lat,
                'lng'     => (float) $d->current_lng,
                'updated' => $d->location_updated_at,
                'stale'   => $d->location_updated_at ? Carbon::parse($d->location_updated_at)->lt($fresh) : true,
            ])->all();
    }

    // ── Per-city dashboard (City Command Center) ────────────────────────────
    public function cityDashboard(string $city): array
    {
        $d30 = Carbon::now()->subDays(30);
        $cityExpr = $this->cityExpr('orders');

        // Closure builds a FRESH query each call (no shared/cloned state).
        $base = fn () => DB::table('orders')
            ->whereNull('parent_id')->whereNull('deleted_at')
            ->whereRaw("$cityExpr = ?", [$city]);

        $statusRows = $base()
            ->where('created_at', '>=', $d30)
            ->select('order_status', DB::raw('COUNT(id) as c'))
            ->groupBy('order_status')->pluck('c', 'order_status');

        $recent = $base()
            ->select('id', 'tracking_number', 'order_status', 'paid_total', 'created_at')
            ->orderByDesc('id')->limit(12)->get();

        // 14-day revenue trend (completed).
        $trend = $base()
            ->where('order_status', OrderStatus::COMPLETED)
            ->where('created_at', '>=', Carbon::now()->subDays(14))
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(paid_total) as revenue'))
            ->groupBy('d')->orderBy('d')->get();

        $vendors = (int) DB::table('shops')
            ->whereRaw($this->shopCityExpr('shops') . ' = ?', [$city])->count();

        return [
            'city'          => $city,
            'orders_30d'    => (int) $base()->where('created_at', '>=', $d30)->count(),
            'revenue_30d'   => (float) $base()->where('order_status', OrderStatus::COMPLETED)->where('created_at', '>=', $d30)->sum('paid_total'),
            'customers'     => (int) $base()->distinct()->count('customer_id'),
            'vendors'       => $vendors,
            'by_status'     => [
                'pending'        => (int) ($statusRows[OrderStatus::PENDING] ?? 0),
                'processing'     => (int) ($statusRows[OrderStatus::PROCESSING] ?? 0),
                'outForDelivery' => (int) ($statusRows[OrderStatus::OUT_FOR_DELIVERY] ?? 0),
                'completed'      => (int) ($statusRows[OrderStatus::COMPLETED] ?? 0),
                'cancelled'      => (int) ($statusRows[OrderStatus::CANCELLED] ?? 0),
            ],
            'revenue_trend' => $trend,
            'recent_orders' => $recent,
        ];
    }

    // ── private helpers ─────────────────────────────────────────────────────

    /** All (non-deleted) orders. */
    private function orders()
    {
        return DB::table('orders')->whereNull('deleted_at');
    }

    /** Customer-facing orders (the parent rows; full paid_total, no double-count). */
    private function customerOrders()
    {
        return $this->orders()->whereNull('parent_id');
    }

    private function revenueSince(Carbon $since): float
    {
        return (float) $this->customerOrders()
            ->where('order_status', OrderStatus::COMPLETED)
            ->where('created_at', '>=', $since)
            ->sum('paid_total');
    }

    private function ordersByStatusSince(Carbon $since): array
    {
        $rows = $this->customerOrders()
            ->where('created_at', '>=', $since)
            ->select('order_status', DB::raw('COUNT(id) as c'))
            ->groupBy('order_status')
            ->pluck('c', 'order_status');

        return [
            'pending'        => (int) ($rows[OrderStatus::PENDING] ?? 0),
            'processing'     => (int) ($rows[OrderStatus::PROCESSING] ?? 0),
            'completed'      => (int) ($rows[OrderStatus::COMPLETED] ?? 0),
            'cancelled'      => (int) ($rows[OrderStatus::CANCELLED] ?? 0),
            'failed'         => (int) ($rows[OrderStatus::FAILED] ?? 0),
            'atFacility'     => (int) ($rows[OrderStatus::AT_LOCAL_FACILITY] ?? 0),
            'outForDelivery' => (int) ($rows[OrderStatus::OUT_FOR_DELIVERY] ?? 0),
        ];
    }

    private function customersCreatedSince(Carbon $since): int
    {
        return User::permission(Permission::CUSTOMER)->where('created_at', '>=', $since)->count();
    }

    private function customersCreatedBetween(Carbon $from, Carbon $to): int
    {
        return User::permission(Permission::CUSTOMER)->whereBetween('created_at', [$from, $to])->count();
    }

    private function vendorsTotal(): int
    {
        return User::permission(Permission::STORE_OWNER)->count();
    }

    private function vendorsCreatedSince(Carbon $since): int
    {
        return User::permission(Permission::STORE_OWNER)->where('created_at', '>=', $since)->count();
    }

    private function vendorsCreatedBetween(Carbon $from, Carbon $to): int
    {
        return User::permission(Permission::STORE_OWNER)->whereBetween('created_at', [$from, $to])->count();
    }

    private function growthPct(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function activeCitiesCount(Carbon $since): int
    {
        $cityExpr = $this->cityExpr('orders');
        return (int) DB::table('orders')
            ->whereNull('parent_id')->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count(DB::raw($cityExpr));
    }

    private function activeCouriersCount(): int
    {
        return (int) DB::table('delivery_partners')
            ->whereNull('deleted_at')
            ->where('is_online', true)
            ->where('location_updated_at', '>=', Carbon::now()->subMinutes(self::COURIER_FRESH_MIN))
            ->count();
    }

    /** Approximate "active now": distinct request_log IPs in the last window (GET is not logged). */
    private function liveVisitorsApprox(): int
    {
        if (!Schema::hasTable('request_logs')) {
            return 0;
        }
        return (int) DB::table('request_logs')
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::VISITOR_WINDOW_MIN))
            ->whereNotNull('ip')
            ->distinct()
            ->count('ip');
    }

    /** SQL expr: the customer city from an order's shipping_address JSON (with fallbacks). */
    private function cityExpr(string $table): string
    {
        return "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT($table.shipping_address, '$.city'))), ''), "
            . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT($table.shipping_address, '$.address.city'))), ''), 'Unknown')";
    }

    /** SQL expr: the city from a shop's address JSON. */
    private function shopCityExpr(string $table): string
    {
        return "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT($table.address, '$.city'))), ''), 'Unknown')";
    }

    /** PHP-side city extraction from a shipping_address JSON string/array. */
    private function cityFromJson($shippingAddress): ?string
    {
        if (empty($shippingAddress)) {
            return null;
        }
        $a = is_string($shippingAddress) ? json_decode($shippingAddress, true) : (array) $shippingAddress;
        return $a['city'] ?? ($a['address']['city'] ?? null);
    }
}
