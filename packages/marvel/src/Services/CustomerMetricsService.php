<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\Permission;

/**
 * Customer Intelligence (Phase 4). Active/returning, lifetime value + top
 * customers from orders; cart-abandonment from the Phase-3 analytics_events
 * (graceful 0 if tracking isn't live yet); top cities + popular plants.
 */
class CustomerMetricsService
{
    public function overview(): array
    {
        $d30 = Carbon::now()->subDays(30);

        $completedParent = fn () => DB::table('orders')
            ->whereNull('parent_id')->whereNull('deleted_at')
            ->where('order_status', OrderStatus::COMPLETED);

        // Lifetime value.
        $totalRevenue = (float) $completedParent()->sum('paid_total');
        $payingCustomers = (int) $completedParent()->distinct()->count('customer_id');
        $clvAvg = $payingCustomers > 0 ? round($totalRevenue / $payingCustomers, 2) : 0;

        // Returning = >1 completed order.
        $returning = (int) DB::table(DB::raw(
            '(SELECT customer_id FROM orders WHERE parent_id IS NULL AND deleted_at IS NULL AND order_status = "'
            . OrderStatus::COMPLETED . '" GROUP BY customer_id HAVING COUNT(*) > 1) as t'
        ))->count();

        $active30 = (int) DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
            ->where('created_at', '>=', $d30)->distinct()->count('customer_id');

        return [
            'total_customers'   => (int) User::permission(Permission::CUSTOMER)->count(),
            'new_30d'           => (int) User::permission(Permission::CUSTOMER)->where('created_at', '>=', $d30)->count(),
            'active_30d'        => $active30,
            'paying_customers'  => $payingCustomers,
            'returning'         => $returning,
            'returning_rate'    => $payingCustomers > 0 ? round(($returning / $payingCustomers) * 100, 1) : 0,
            'clv_avg'           => $clvAvg,
            'top_customers'     => $this->topCustomers(),
            'cart_abandonment'  => $this->cartAbandonment(),
            'new_vs_returning'  => $this->newVsReturning($d30),
            'top_cities'        => $this->topCities(),
            'popular_plants'    => $this->popularPlants(),
        ];
    }

    private function topCustomers(): array
    {
        return DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.customer_id')
            ->whereNull('o.parent_id')->whereNull('o.deleted_at')
            ->where('o.order_status', OrderStatus::COMPLETED)
            ->groupBy('o.customer_id', 'u.name', 'u.email')
            ->select('o.customer_id as id', 'u.name', 'u.email',
                DB::raw('SUM(o.paid_total) as spend'), DB::raw('COUNT(o.id) as orders'))
            ->orderByDesc('spend')->limit(10)->get()->all();
    }

    /** From storefront events (last 7d). Graceful 0 if the table/data is absent. */
    private function cartAbandonment(): array
    {
        if (!Schema::hasTable('analytics_events')) {
            return ['carted' => 0, 'paid' => 0, 'rate' => 0];
        }
        $since = Carbon::now()->subDays(7);
        $carted = (int) DB::table('analytics_events')->where('type', 'add_to_cart')
            ->where('created_at', '>=', $since)->distinct()->count('visitor_id');
        $paid = (int) DB::table('analytics_events')->where('type', 'payment_complete')
            ->where('created_at', '>=', $since)->distinct()->count('visitor_id');
        $rate = $carted > 0 ? round((max(0, $carted - $paid) / $carted) * 100, 1) : 0;

        return ['carted' => $carted, 'paid' => $paid, 'rate' => $rate];
    }

    private function newVsReturning(Carbon $since): array
    {
        $buyers30 = DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
            ->where('created_at', '>=', $since)->distinct()->pluck('customer_id')->filter()->all();

        if (empty($buyers30)) {
            return ['new' => 0, 'returning' => 0];
        }

        $returning = (int) DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
            ->whereIn('customer_id', $buyers30)
            ->where('created_at', '<', $since)
            ->distinct()->count('customer_id');

        return ['new' => count($buyers30) - $returning, 'returning' => $returning];
    }

    private function topCities(): array
    {
        $cityExpr = "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.city'))), ''), "
            . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.address.city'))), ''), 'Unknown')";

        return DB::table('orders')
            ->whereNull('parent_id')->whereNull('deleted_at')
            ->where('order_status', OrderStatus::COMPLETED)
            ->select(DB::raw("$cityExpr as city"),
                DB::raw('COUNT(id) as orders'), DB::raw('SUM(paid_total) as revenue'))
            ->groupBy('city')->orderByDesc('revenue')->limit(8)->get()->all();
    }

    private function popularPlants(): array
    {
        $since = Carbon::now()->subDays(90)->toDateString();
        return DB::table('order_product as op')
            ->join('orders as o', function ($j) use ($since) {
                $j->on('op.order_id', '=', 'o.id')
                    ->whereNull('o.parent_id')->whereNull('o.deleted_at')
                    ->where('o.order_status', OrderStatus::COMPLETED)
                    ->where('o.created_at', '>=', $since);
            })
            ->join('products as p', 'op.product_id', '=', 'p.id')
            ->groupBy('p.id', 'p.name')
            ->select('p.id', 'p.name', DB::raw('SUM(CAST(op.order_quantity AS UNSIGNED)) as units'))
            ->orderByDesc('units')->limit(10)->get()->all();
    }
}
