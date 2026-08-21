<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shop;

/**
 * Vendors (shops) and their dependent data.
 *
 * 🔴 THE critical guard in this whole system lives here. `products.shop_id` is
 * ON DELETE CASCADE, and the master-shop consolidation reassigned EVERY product to one shop
 * — so deleting that single row would take the entire catalog (4,396 products) with it.
 * `users.shop_id` and `orders.shop_id` cascade the same way, meaning a careless shop delete
 * also removes staff accounts and sub-orders.
 *
 * Therefore: the master shop can never be planned, and any shop that still owns products is
 * refused. Vendor rows in tables born app-side have no FK at all and are deleted explicitly.
 */
class VendorsCleaner implements CleanerContract
{
    public function key(): string { return 'vendors'; }

    public function label(): string { return 'Vendors'; }

    public function description(): string
    {
        return 'Removes vendor shops and everything that depends on them: inventory, pricing, '
             . 'service areas, pickup locations, balances/withdraws, ledger + settlements, staff '
             . 'links and their orders. The master catalog shop can never be selected.';
    }

    public function stats(): array
    {
        if (!Schema::hasTable('shops')) {
            return ['total' => 0];
        }
        $master = $this->masterShopId();
        return [
            'total'        => DB::table('shops')->count(),
            'deletable'    => DB::table('shops')->when($master, fn ($q) => $q->where('id', '!=', $master))->count(),
            'master_shop_protected' => $master,
            'marked_test'  => TestDataMarker::countFor(Shop::class),
        ];
    }

    private function masterShopId(): ?int
    {
        try {
            return Shop::masterId();
        } catch (\Throwable) {
            return DB::table('shops')->where('slug', 'plantathome')->value('id');
        }
    }

    public function plan(array $scope): CleanupPlan
    {
        $plan = new CleanupPlan($this->key(), $scope);
        if (!Schema::hasTable('shops')) {
            return $plan;
        }

        $master = $this->masterShopId();
        $q = DB::table('shops')->select('id');
        if (!empty($scope['ids'])) {
            $q->whereIn('id', (array) $scope['ids']);
        } elseif (!empty($scope['only_marked'])) {
            $q->whereIn('id', TestDataMarker::idsFor(Shop::class));
        } elseif (empty($scope['all'])) {
            return $plan;
        }
        $ids = $q->pluck('id')->all();

        // GUARD 1 — the master shop owns the catalog; its deletion would cascade it away.
        if ($master && in_array($master, $ids)) {
            $ids = array_values(array_diff($ids, [$master]));
            $plan->warn("The master catalog shop (#{$master}) was excluded — deleting it would cascade-delete every product.");
        }
        // GUARD 2 — any shop that still owns products is refused for the same reason.
        if ($ids && Schema::hasTable('products')) {
            $owning = DB::table('products')->whereIn('shop_id', $ids)->distinct()->pluck('shop_id')->all();
            if ($owning) {
                $ids = array_values(array_diff($ids, $owning));
                $plan->warn('Shops ' . implode(', ', $owning) . ' still own catalog products and were excluded (products.shop_id cascades).');
            }
        }
        if (!$ids) {
            return $plan;
        }

        // Their orders go first — orders.shop_id cascades, which would otherwise delete
        // sub-orders and strand all the no-FK order debris.
        if (Schema::hasTable('orders')) {
            $orderIds = DB::table('orders')->whereIn('shop_id', $ids)->pluck('id')->all();
            if ($orderIds) {
                $orderPlan = (new OrdersCleaner())->plan(['ids' => $orderIds]);
                foreach ($orderPlan->steps as $s) {
                    $plan->steps[] = $s;
                }
                $plan->warn(count($orderIds) . ' order(s) belonging to these vendors are included.');
            }
        }

        // Delivery partners belonging to the shop (and their money rows).
        $dpIds = Schema::hasTable('delivery_partners')
            ? DB::table('delivery_partners')->whereIn('shop_id', $ids)->pluck('id')->all() : [];
        foreach ([['delivery_partner_earnings', 'delivery_partner_id'], ['delivery_partner_balances', 'delivery_partner_id'], ['delivery_partner_withdraws', 'delivery_partner_id']] as [$t, $c]) {
            if ($dpIds && Schema::hasTable($t)) {
                $plan->step($t, DB::table($t)->whereIn($c, $dpIds)->pluck('id')->all(), 'id');
            }
        }
        $plan->step('delivery_partners', $dpIds, 'id');

        // The no-FK vendor stack — nothing cleans these up automatically.
        foreach ([
            'vendor_settlements', 'vendor_ledger_entries', 'vendor_inventory_reviews',
            'vendor_product_prices', 'price_import_batches', 'vendor_service_areas',
            'vendor_pickup_locations', 'vendor_shipping_rates', 'vendor_covered_pincodes',
            'coverage_audit_logs', 'vendor_coverage_rules',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $plan->step($table, DB::table($table)->whereIn('shop_id', $ids)->pluck('id')->all(), 'id');
            }
        }
        if (Schema::hasTable('location_capture_requests')) {
            $plan->step('location_capture_requests', DB::table('location_capture_requests')->whereIn('vendor_id', $ids)->pluck('id')->all(), 'id');
        }

        // The V2 vendor context. A nursery is the same vendor under the DDD modules, bridged to
        // the legacy shop by `nursery_nurseries.legacy_id` — so a wipe that removed only the shop
        // left a live nursery behind, and the projection command would recreate the shop from it
        // on the next boot. Ordering matters: nursery_documents and nursery_balances CASCADE from
        // nursery_nurseries, but ledger entries and withdrawals are RESTRICT, so they must be
        // deleted first or the parent delete is refused outright.
        $nurseryIds = [];
        $nurseryUuids = [];
        if (Schema::hasTable('nursery_nurseries')) {
            $rows = DB::table('nursery_nurseries')->whereIn('legacy_id', $ids)->get(['id', 'uuid']);
            $nurseryIds = $rows->pluck('id')->all();
            $nurseryUuids = $rows->pluck('uuid')->filter()->all();
        }
        if ($nurseryIds) {
            foreach (['nursery_withdrawals', 'nursery_ledger_entries', 'nursery_balances', 'nursery_documents'] as $table) {
                if (Schema::hasTable($table)) {
                    $plan->step($table, DB::table($table)->whereIn('nursery_id', $nurseryIds)->pluck('id')->all(), 'id');
                }
            }
        }

        // Module tables keyed by the nursery UUID rather than a shop id. Missed, these are the
        // rows that make a deleted vendor keep serving a city or holding a price override.
        if ($nurseryUuids) {
            foreach ([
                'svc_vendor_areas', 'pricing_vendor_overrides', 'config_nursery_enablement',
                'inv_items', 'inv_warehouses', 'catalog_product_proposals',
            ] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'nursery_id')) {
                    $plan->step($table, DB::table($table)->whereIn('nursery_id', $nurseryUuids)->pluck('id')->all(), 'id');
                }
            }
        }
        if ($nurseryIds) {
            $plan->step('nursery_nurseries', $nurseryIds, 'id', 'the V2 nursery records');
        }

        // Catalog rows a vendor proposed belong to the platform now — keep them, clear the
        // pointer. Same for an order's vendor reference on any order we are NOT deleting.
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'proposed_by_shop_id')) {
            $plan->nullify('products', 'proposed_by_shop_id', $ids);
        }
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'vendor_shop_id')) {
            $plan->nullify('orders', 'vendor_shop_id', $ids);
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'shop_id')) {
            // users.shop_id CASCADES — staff accounts would be hard-deleted with the shop.
            $plan->nullify('users', 'shop_id', $ids);
            $plan->warn('Staff accounts are detached from the shop (users.shop_id cleared), not deleted — use the Users module for accounts.');
        }

        $plan->step('shops', $ids, 'id', 'the vendor shops themselves');

        return $plan;
    }
}
