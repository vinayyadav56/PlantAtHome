<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier / non-serviceable-area orders. When a shopper orders from outside our
 * standard service area and chooses "Continue Anyway", the order is flagged so
 * ops can route it via a courier partner (longer ETA, extra charges). We record
 * the city the shopper was detected in vs the serviceable city they shipped to.
 *
 * NOTE: we deliberately do NOT overload the existing `orders.delivery_mode`
 * (vendor_dp|separate_dp|courier_admin|courier_dp — an internal assignment-routing
 * field). The customer-facing "standard vs courier" intent is derived from
 * `is_non_serviceable_order`. All additive + nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'is_non_serviceable_order')) {
                $table->boolean('is_non_serviceable_order')->default(false)->index()->after('order_status');
            }
            if (!Schema::hasColumn('orders', 'detected_city')) {
                $table->string('detected_city')->nullable()->after('is_non_serviceable_order');
            }
            if (!Schema::hasColumn('orders', 'serviceable_city')) {
                $table->string('serviceable_city')->nullable()->after('detected_city');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            foreach (['is_non_serviceable_order', 'detected_city', 'serviceable_city'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
