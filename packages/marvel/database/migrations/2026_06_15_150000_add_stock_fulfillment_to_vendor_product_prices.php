<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-vendor marketplace P1: per-vendor stock + fulfillment on the vendor→master-
 * product mapping. `vendor_product_prices` already maps a vendor (shop_id) to a
 * master product (no duplicate products); this adds the inventory + fulfillment a
 * true marketplace needs:
 *   stock_qty / reserved_qty → per-vendor availability (atomic reservation on order)
 *   fulfillment_mode         → local | courier | both (null = inherit the vendor's service area)
 *   moq / lead_time_days     → minimum order qty + courier lead time for ETA
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_product_prices', 'stock_qty')) {
                $table->integer('stock_qty')->default(0)->after('cost_price');
            }
            if (!Schema::hasColumn('vendor_product_prices', 'reserved_qty')) {
                $table->integer('reserved_qty')->default(0)->after('stock_qty');
            }
            if (!Schema::hasColumn('vendor_product_prices', 'fulfillment_mode')) {
                $table->string('fulfillment_mode')->nullable()->after('reserved_qty'); // local | courier | both
            }
            if (!Schema::hasColumn('vendor_product_prices', 'moq')) {
                $table->unsignedInteger('moq')->default(1)->after('fulfillment_mode');
            }
            if (!Schema::hasColumn('vendor_product_prices', 'lead_time_days')) {
                $table->unsignedSmallInteger('lead_time_days')->nullable()->after('moq');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            foreach (['stock_qty', 'reserved_qty', 'fulfillment_mode', 'moq', 'lead_time_days'] as $col) {
                if (Schema::hasColumn('vendor_product_prices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
