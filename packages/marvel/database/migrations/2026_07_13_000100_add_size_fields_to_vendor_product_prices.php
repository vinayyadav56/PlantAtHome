<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-vendor, per-size logistics fields on vendor_product_prices (rows are keyed by
 * shop_id + product_id + variation_option_id, so these are per-size). Price/stock/
 * moq/lead_time already exist. Additive + guarded. Never customer-exposed.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_product_prices', 'sku')) {
                $table->string('sku', 191)->nullable();
            }
            if (!Schema::hasColumn('vendor_product_prices', 'barcode')) {
                $table->string('barcode', 191)->nullable();
            }
            if (!Schema::hasColumn('vendor_product_prices', 'weight')) {
                $table->decimal('weight', 10, 3)->nullable(); // grams
            }
            if (!Schema::hasColumn('vendor_product_prices', 'length')) {
                $table->decimal('length', 10, 2)->nullable(); // cm
            }
            if (!Schema::hasColumn('vendor_product_prices', 'breadth')) {
                $table->decimal('breadth', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('vendor_product_prices', 'height')) {
                $table->decimal('height', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            foreach (['sku', 'barcode', 'weight', 'length', 'breadth', 'height'] as $col) {
                if (Schema::hasColumn('vendor_product_prices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
