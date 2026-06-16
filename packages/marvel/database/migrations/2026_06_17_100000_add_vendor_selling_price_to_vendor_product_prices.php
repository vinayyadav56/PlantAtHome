<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P1 — vendor-set pricing. Vendors set their OWN selling price
 * per variant (the customer sees the lowest available price in their city; the vendor
 * is never named). `vendor_selling_price` is that price; `cost_price` stays as the
 * vendor's optional margin reference + the legacy margin-over-cost fallback (used when
 * a row has no selling price set yet, so existing cost-only rows keep working).
 * Audit columns record which vendor user attached/edited the row (self-serve).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_product_prices', 'vendor_selling_price')) {
                $table->decimal('vendor_selling_price', 14, 2)->nullable()->after('cost_price');
            }
            if (!Schema::hasColumn('vendor_product_prices', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('source');
            }
            if (!Schema::hasColumn('vendor_product_prices', 'updated_by_user_id')) {
                $table->unsignedBigInteger('updated_by_user_id')->nullable()->after('created_by_user_id');
            }
        });

        // Cheapest-available-by-displayed-price lookups. Separate statement so the
        // column is committed/visible; try/catch makes a re-run idempotent.
        try {
            Schema::table('vendor_product_prices', function (Blueprint $table) {
                $table->index(['product_id', 'is_available', 'vendor_selling_price'], 'vpp_product_available_sell_idx');
            });
        } catch (\Throwable $e) {
            // index already exists
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        try {
            Schema::table('vendor_product_prices', function (Blueprint $table) {
                $table->dropIndex('vpp_product_available_sell_idx');
            });
        } catch (\Throwable $e) {
            // index may not exist
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            foreach (['vendor_selling_price', 'created_by_user_id', 'updated_by_user_id'] as $col) {
                if (Schema::hasColumn('vendor_product_prices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
