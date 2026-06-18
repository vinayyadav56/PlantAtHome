<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance/scalability indexes confirmed by the perf audit (find→verify→confirmed). All
 * additive + composite secondary indexes (online-addable, no data/behavior change); each wrapped
 * in try/catch so a re-run or a pre-existing index is a no-op, matching add_analytics_indexes.
 *
 *  - orders(parent_id, created_at): the admin "all orders" list runs `WHERE parent_id IS NULL`
 *    AND the Order model's unconditional global scope adds `ORDER BY created_at DESC`. With only
 *    the bare parent_id FK index (and no covering sort) this is a full scan + filesort at scale;
 *    this composite serves the equality on the leading column then reads created_at in order.
 *    Also speeds the AnalyticsController whereNull/whereNotNull('parent_id') date aggregates.
 *  - orders(customer_id, parent_id): the customer "My Orders" list filters customer_id + parent_id.
 *  - shipments(provider, provider_order_id): the Borzo webhook looks a shipment up by
 *    (provider, provider_order_id); the only existing composite leads with provider+status, so
 *    provider_order_id was unindexed for this lookup.
 *  - products(language, quantity): the admin low-stock widget runs `WHERE language=? AND quantity<10`.
 */
return new class extends Migration {
    private array $indexes = [
        ['orders', ['parent_id', 'created_at'], 'orders_parent_created_idx'],
        ['orders', ['customer_id', 'parent_id'], 'orders_customer_parent_idx'],
        ['shipments', ['provider', 'provider_order_id'], 'shipments_provider_order_idx'],
        ['products', ['language', 'quantity'], 'products_lang_qty_idx'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$tableName, $cols, $name]) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            try {
                Schema::table($tableName, function (Blueprint $table) use ($cols, $name) {
                    $table->index($cols, $name);
                });
            } catch (\Throwable $e) {
                // index already exists / column missing on this deployment — additive no-op
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$tableName, $cols, $name]) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            try {
                Schema::table($tableName, function (Blueprint $table) use ($name) {
                    $table->dropIndex($name);
                });
            } catch (\Throwable $e) {
                // index may not exist
            }
        }
    }
};
