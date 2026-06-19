<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency guard for inventory restoration. A cancelled/refunded order
 * restores its (component) plant stock exactly once — set true the first time
 * ProductInventoryRestore runs for the order, so a later CANCELLED transition or
 * a refund-then-cancel can't double-restore.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'inventory_restored')) {
                $table->boolean('inventory_restored')->default(false)->after('order_status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'inventory_restored')) {
                $table->dropColumn('inventory_restored');
            }
        });
    }
};
