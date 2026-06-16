<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P3 — per-vendor shipping cost + SLA by zone, used by the
 * assignment score (shipping-cost factor) and the customer checkout estimate. `zone`
 * is coarse (same_city | intra_state | national) or a pincode prefix; the engine falls
 * back to the vendor service area's eta when no rate row matches.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_shipping_rates')) {
            return;
        }
        Schema::create('vendor_shipping_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('zone', 40);                         // same_city | intra_state | national | pincode prefix
            $table->string('fulfillment_mode', 16);             // local | courier
            $table->decimal('base_cost', 14, 2)->default(0);
            $table->decimal('per_kg_cost', 14, 2)->default(0);
            $table->unsignedSmallInteger('sla_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'zone', 'fulfillment_mode'], 'vsr_shop_zone_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_shipping_rates');
    }
};
