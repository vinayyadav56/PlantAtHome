<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized lat/lng on shops (vendors) for fast Haversine nearest-vendor
 * matching. The Google Places picker in the admin shop-form already produces
 * address.location{lat,lng}; these columns mirror it for indexed querying.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable()->after('address');
            }
            if (!Schema::hasColumn('shops', 'lng')) {
                $table->decimal('lng', 10, 7)->nullable()->after('lat');
            }
        });
        // index() inside the closure can error if the column pre-exists; add safely.
        try {
            Schema::table('shops', function (Blueprint $table) {
                $table->index(['lat', 'lng'], 'shops_lat_lng_index');
            });
        } catch (\Throwable $e) {
            // index already present — ignore
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            try {
                $table->dropIndex('shops_lat_lng_index');
            } catch (\Throwable $e) {
            }
            if (Schema::hasColumn('shops', 'lat')) {
                $table->dropColumn('lat');
            }
            if (Schema::hasColumn('shops', 'lng')) {
                $table->dropColumn('lng');
            }
        });
    }
};
