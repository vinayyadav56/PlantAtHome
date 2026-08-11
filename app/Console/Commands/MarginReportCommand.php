<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Courier margin watch: orders where the delivery fee charged to the customer
 * (orders.delivery_fee) is LESS than what we paid the partner (Σ shipments.booked_cost).
 * The audit flagged that the customer-charged fee and the courier cost are never
 * reconciled — once courier is live, a mispriced product.delivery_charge silently leaks
 * margin on every order.
 *
 * Report-only — logs a summary + the worst offenders and changes nothing. Dormant until
 * courier bookings exist (no booked_cost until shipments are booked).
 */
class MarginReportCommand extends Command
{
    protected $signature = 'courier:margin-report {--days=7 : Only orders created within N days} {--limit=20 : Worst offenders to list}';

    protected $description = 'Report orders whose courier booked_cost exceeds the delivery fee charged (margin leak).';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $since = now()->subDays($days);

        // Per parent order: charged delivery_fee vs Σ booked_cost across its shipments.
        $rows = DB::table('orders')
            ->join('shipments', 'shipments.order_id', '=', 'orders.id')
            ->whereNull('orders.parent_id')
            ->where('orders.created_at', '>=', $since)
            ->whereNotNull('shipments.booked_cost')
            ->groupBy('orders.id', 'orders.tracking_number', 'orders.delivery_fee')
            ->select([
                'orders.tracking_number',
                DB::raw('COALESCE(orders.delivery_fee, 0) as charged'),
                DB::raw('SUM(COALESCE(shipments.booked_cost, 0)) as cost'),
            ])
            ->get()
            ->map(fn ($r) => (object) [
                'tracking_number' => $r->tracking_number,
                'charged' => (float) $r->charged,
                'cost'    => (float) $r->cost,
                'margin'  => round((float) $r->charged - (float) $r->cost, 2),
            ]);

        $leaks = $rows->filter(fn ($r) => $r->margin < 0)->sortBy('margin')->values();

        if ($leaks->isEmpty()) {
            $this->info("No courier margin leaks in the last {$days}d ({$rows->count()} booked order(s) checked).");
            return self::SUCCESS;
        }

        $totalLeak = round($leaks->sum(fn ($r) => -$r->margin), 2);
        Log::warning('courier.margin.leak', [
            'days'             => $days,
            'orders_with_leak' => $leaks->count(),
            'total_leak'       => $totalLeak,
            'worst'            => $leaks->take($limit)->map(fn ($r) => [
                'tracking_number' => $r->tracking_number,
                'charged'         => $r->charged,
                'cost'            => $r->cost,
                'margin'          => $r->margin,
            ])->all(),
        ]);
        $this->warn("{$leaks->count()} order(s) charged less delivery than courier cost; total leak ₹{$totalLeak}.");

        return self::SUCCESS;
    }
}
