<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace P1: a denormalized "is this master product available in this city"
 * projection, so the city-first storefront answers "products in <city>" in O(1)
 * at hundreds of thousands of vendor mappings (instead of joining vendor inventory
 * × service areas per request). Recomputed by VendorInventoryService whenever a
 * vendor's inventory or service area changes.
 *   has_local   → at least one vendor delivers it locally in this city
 *   has_courier → at least one vendor ships it here by courier
 *   min_price   → cheapest selling price among available vendors (for sorting)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('product_city_availability')) {
            return;
        }
        Schema::create('product_city_availability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('city')->index();
            $table->boolean('has_local')->default(false);
            $table->boolean('has_courier')->default(false);
            $table->decimal('min_price', 14, 2)->nullable();
            $table->unsignedInteger('vendor_count')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['product_id', 'city'], 'pca_product_city_uq');
            $table->index(['city', 'has_local'], 'pca_city_local_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_city_availability');
    }
};
