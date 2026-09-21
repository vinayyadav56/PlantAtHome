<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer actually bought, frozen onto the line.
 *
 * order_items referenced the product and the variation by id only, so every
 * historical invoice re-read today's names: rename a product, or retire a size,
 * and last year's invoice quietly changed with it. A tax invoice has to keep
 * saying what was sold — the rest of the line (price, HSN, rate, tax) is already
 * snapshotted, and this closes the one gap left in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'product_name')) {
                $table->string('product_name')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('order_items', 'variant_title')) {
                $table->string('variant_title')->nullable()->after('variation_option_id');
            }
            if (!Schema::hasColumn('order_items', 'variant_code')) {
                // The variant master's stable key (S/M/L) — survives a rename of
                // the size itself, which the title does not.
                $table->string('variant_code', 8)->nullable()->after('variant_title');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            foreach (['product_name', 'variant_title', 'variant_code'] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
