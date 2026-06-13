<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order fulfilment assignment + profit columns. Schema lands in P1; the matching
 * engine populates vendor/DP/mode (P3) and the cost/commission amounts (P2 cost
 * snapshot, P4 commission).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'vendor_shop_id')) {
                $table->unsignedBigInteger('vendor_shop_id')->nullable()->index();
            }
            if (!Schema::hasColumn('orders', 'delivery_partner_id')) {
                $table->unsignedBigInteger('delivery_partner_id')->nullable()->index();
            }
            if (!Schema::hasColumn('orders', 'delivery_mode')) {
                // vendor_dp | separate_dp | courier_admin | courier_dp
                $table->string('delivery_mode')->nullable();
            }
            if (!Schema::hasColumn('orders', 'assignment_status')) {
                // unassigned | suggested | approved
                $table->string('assignment_status')->default('unassigned');
            }
            if (!Schema::hasColumn('orders', 'vendor_cost_total')) {
                $table->decimal('vendor_cost_total', 14, 2)->nullable();
            }
            if (!Schema::hasColumn('orders', 'dp_commission_amount')) {
                $table->decimal('dp_commission_amount', 14, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'vendor_shop_id', 'delivery_partner_id', 'delivery_mode',
                'assignment_status', 'vendor_cost_total', 'dp_commission_amount',
            ] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
