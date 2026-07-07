<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Margin snapshot on assigned order lines. `unit_price` (customer selling price) and
 * `vendor_price_snapshot` (the assigned vendor's rate — what the vendor receives)
 * already exist; these two record the platform take at assignment time so later
 * margin-config changes never rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'margin_amount')) {
                // (unit_price − vendor rate) × qty at assignment time.
                $table->decimal('margin_amount', 14, 2)->nullable();
            }
            if (!Schema::hasColumn('order_items', 'margin_percent_snapshot')) {
                $table->decimal('margin_percent_snapshot', 8, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'margin_amount')) {
                $table->dropColumn('margin_amount');
            }
            if (Schema::hasColumn('order_items', 'margin_percent_snapshot')) {
                $table->dropColumn('margin_percent_snapshot');
            }
        });
    }
};
