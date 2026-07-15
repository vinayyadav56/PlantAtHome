<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — snapshot of the coverage decision taken at checkout
 * ({pincode, shop_id, source, checked_at}) so an order's serviceability can be
 * audited even after rules change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'delivery_coverage')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->json('delivery_coverage')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'delivery_coverage')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_coverage');
            });
        }
    }
};
