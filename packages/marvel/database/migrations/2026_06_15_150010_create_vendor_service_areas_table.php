<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace P1: the cities a vendor (shop) serves and how. Drives city-first
 * customer availability and local-vs-courier fulfillment:
 *   fulfillment_mode = local  → same-city delivery via a delivery partner
 *                    = courier → ships from this vendor by courier
 *                    = both
 * A vendor lists one row per served city (optionally per pincode).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_service_areas')) {
            return;
        }
        Schema::create('vendor_service_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();   // vendor
            $table->string('city')->index();
            $table->string('pincode', 12)->nullable()->index();
            $table->string('fulfillment_mode')->default('local'); // local | courier | both
            $table->unsignedSmallInteger('eta_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city', 'is_active'], 'vsa_city_active_idx');
            $table->unique(['shop_id', 'city', 'pincode'], 'vsa_vendor_city_pincode_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_service_areas');
    }
};
