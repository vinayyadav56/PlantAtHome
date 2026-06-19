<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

/**
 * Inventory Command Center (Phase 4). Aggregates stock health from products +
 * sales velocity from order_product (units sold). Bundles are excluded (their
 * stock is derived, not stored). `order_product.order_quantity` is a string →
 * always CAST to UNSIGNED.
 */
class InventoryMetricsService
{
    private const LOW_THRESHOLD = 10;

    public function overview(): array
    {
        $d30 = Carbon::now()->subDays(30)->toDateString();
        $d60 = Carbon::now()->subDays(60)->toDateString();

        // Stock-health counts (published, non-bundle products).
        $base = fn () => DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', ProductStatus::PUBLISH)
            ->where('product_type', '!=', ProductType::BUNDLE);

        $outOfStock = (clone $base())->where('quantity', '<=', 0)->count();
        $lowStock = (clone $base())->whereBetween('quantity', [1, self::LOW_THRESHOLD - 1])->count();
        $healthy = (clone $base())->where('quantity', '>=', self::LOW_THRESHOLD)->count();
        $stockValue = (float) (clone $base())
            ->select(DB::raw('SUM(quantity * COALESCE(price, min_price, 0)) as v'))->value('v');

        return [
            'out_of_stock'    => $outOfStock,
            'low_stock'       => $lowStock,
            'healthy'         => $healthy,
            'total_products'  => $outOfStock + $lowStock + $healthy,
            'stock_value'     => round($stockValue, 2),
            'fast_moving'     => $this->fastMoving($d30),
            'slow_moving'     => $this->slowMoving($d60),
            'low_stock_items' => $this->lowStockItems(),
            'vendor_risk'     => $this->vendorRisk(),
            'category_heatmap' => $this->categoryHeatmap(),
        ];
    }

    /** Top sellers by units in the window. */
    private function fastMoving(string $since): array
    {
        return DB::table('order_product as op')
            ->join('orders as o', function ($j) use ($since) {
                $j->on('op.order_id', '=', 'o.id')
                    ->whereNull('o.parent_id')->whereNull('o.deleted_at')
                    ->where('o.order_status', OrderStatus::COMPLETED)
                    ->where('o.created_at', '>=', $since);
            })
            ->join('products as p', 'op.product_id', '=', 'p.id')
            ->groupBy('p.id', 'p.name', 'p.quantity')
            ->select('p.id', 'p.name', 'p.quantity', DB::raw('SUM(CAST(op.order_quantity AS UNSIGNED)) as units'))
            ->orderByDesc('units')->limit(10)->get()->all();
    }

    /** In-stock products with NO sales in the window (stale stock). */
    private function slowMoving(string $since): array
    {
        return DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->where('p.status', ProductStatus::PUBLISH)
            ->where('p.product_type', '!=', ProductType::BUNDLE)
            ->where('p.quantity', '>', 5)
            ->whereNotExists(function ($q) use ($since) {
                $q->select(DB::raw(1))->from('order_product as op')
                    ->join('orders as o', 'op.order_id', '=', 'o.id')
                    ->whereColumn('op.product_id', 'p.id')
                    ->where('o.created_at', '>=', $since)
                    ->whereIn('o.order_status', [OrderStatus::COMPLETED, OrderStatus::PROCESSING]);
            })
            ->orderByDesc('p.quantity')
            ->limit(10)
            ->get(['p.id', 'p.name', 'p.quantity'])->all();
    }

    private function lowStockItems(): array
    {
        return DB::table('products')
            ->whereNull('deleted_at')->where('status', ProductStatus::PUBLISH)
            ->where('product_type', '!=', ProductType::BUNDLE)
            ->where('quantity', '<', self::LOW_THRESHOLD)
            ->orderBy('quantity')
            ->limit(15)
            ->get(['id', 'name', 'quantity', 'sku'])->all();
    }

    /** Vendors holding the most low/out-of-stock published products. */
    private function vendorRisk(): array
    {
        return DB::table('products as p')
            ->join('shops as s', 's.id', '=', 'p.shop_id')
            ->whereNull('p.deleted_at')->where('p.status', ProductStatus::PUBLISH)
            ->where('p.product_type', '!=', ProductType::BUNDLE)
            ->where('p.quantity', '<', self::LOW_THRESHOLD)
            ->groupBy('s.id', 's.name')
            ->select('s.id', 's.name', DB::raw('COUNT(p.id) as at_risk'))
            ->orderByDesc('at_risk')->limit(10)->get()->all();
    }

    /** Top categories × stock buckets (the heatmap grid). */
    private function categoryHeatmap(): array
    {
        return DB::table('category_product as cp')
            ->join('categories as c', 'c.id', '=', 'cp.category_id')
            ->join('products as p', function ($j) {
                $j->on('p.id', '=', 'cp.product_id')
                    ->whereNull('p.deleted_at')
                    ->where('p.status', ProductStatus::PUBLISH)
                    ->where('p.product_type', '!=', ProductType::BUNDLE);
            })
            ->groupBy('c.id', 'c.name')
            ->select(
                'c.id', 'c.name',
                DB::raw('COUNT(p.id) as total'),
                DB::raw('SUM(CASE WHEN p.quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock'),
                DB::raw('SUM(CASE WHEN p.quantity BETWEEN 1 AND ' . (self::LOW_THRESHOLD - 1) . ' THEN 1 ELSE 0 END) as low'),
                DB::raw('SUM(CASE WHEN p.quantity >= ' . self::LOW_THRESHOLD . ' THEN 1 ELSE 0 END) as healthy')
            )
            ->orderByDesc('total')->limit(14)->get()->all();
    }
}
