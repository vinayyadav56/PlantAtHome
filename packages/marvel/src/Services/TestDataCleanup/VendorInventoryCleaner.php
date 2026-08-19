<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor catalog / inventory only — the vendor's offers, never the master plants they point at.
 * vendor_product_prices soft-deletes, so trashed rows are included deliberately (a hard reset
 * should not leave invisible rows holding the unique dedupe key).
 */
class VendorInventoryCleaner implements CleanerContract
{
    public function key(): string { return 'vendor_inventory'; }

    public function label(): string { return 'Vendor Catalog / Inventory'; }

    public function description(): string
    {
        return 'Removes vendor inventory rows (price, stock, fulfilment) and their review audit '
             . 'trail. Vendors and the master catalog both survive — only the offers are cleared. '
             . 'City availability is recomputed afterwards.';
    }

    public function stats(): array
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return ['total' => 0];
        }
        return [
            'total'     => DB::table('vendor_product_prices')->whereNull('deleted_at')->count(),
            'trashed'   => DB::table('vendor_product_prices')->whereNotNull('deleted_at')->count(),
            'pending_review' => Schema::hasColumn('vendor_product_prices', 'review_status')
                ? DB::table('vendor_product_prices')->where('review_status', 'pending_review')->count() : 0,
        ];
    }

    public function plan(array $scope): CleanupPlan
    {
        $plan = new CleanupPlan($this->key(), $scope);
        if (!Schema::hasTable('vendor_product_prices')) {
            return $plan;
        }

        $q = DB::table('vendor_product_prices')->select('id');
        if (!empty($scope['shop_ids'])) {
            $q->whereIn('shop_id', (array) $scope['shop_ids']);
        } elseif (!empty($scope['ids'])) {
            $q->whereIn('id', (array) $scope['ids']);
        } elseif (empty($scope['all'])) {
            return $plan;
        }
        $ids = $q->pluck('id')->all();
        if (!$ids) {
            return $plan;
        }

        if (Schema::hasTable('vendor_inventory_reviews')) {
            $plan->step('vendor_inventory_reviews',
                DB::table('vendor_inventory_reviews')->whereIn('vendor_product_price_id', $ids)->pluck('id')->all(), 'id');
        }
        $plan->step('vendor_product_prices', $ids, 'id', 'vendor offers (incl. soft-deleted)');
        $plan->warn('City availability is rebuilt after the run, so the storefront reflects the change immediately.');

        return $plan;
    }
}
