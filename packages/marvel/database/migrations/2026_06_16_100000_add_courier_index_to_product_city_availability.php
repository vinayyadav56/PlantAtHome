<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The default city-availability read filters on (city, has_local OR has_courier);
 * the existing (city, has_local) index doesn't cover courier-only cities. Add a
 * (city, has_courier) index so those reads are a clean range scan at scale.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_city_availability')) {
            return;
        }
        Schema::table('product_city_availability', function (Blueprint $table) {
            $table->index(['city', 'has_courier'], 'pca_city_courier_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_city_availability')) {
            return;
        }
        Schema::table('product_city_availability', function (Blueprint $table) {
            $table->dropIndex('pca_city_courier_idx');
        });
    }
};
