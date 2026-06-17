<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial/Dashboards (D1) — indexes that keep the new marketplace analytics + report
 * aggregates cheap at scale (500k+ orders, 10k+ vendors). All additive; each wrapped in
 * try/catch so a re-run (or a pre-existing index) is a no-op.
 */
return new class extends Migration {
    private array $indexes = [
        ['orders', ['order_status', 'parent_id'], 'orders_status_parent_idx'],
        ['orders', ['shop_id', 'order_status'], 'orders_shop_status_idx'],
        ['orders', ['delivery_mode'], 'orders_delivery_mode_idx'],
        ['vendor_ledger_entries', ['shop_id', 'entry_type'], 'vle_shop_entrytype_idx'],
        ['order_product', ['product_id'], 'order_product_product_idx'],
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
                // index already exists
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
