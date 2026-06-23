<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Address accuracy — persist the precise geo of a saved address so we can show
 * "you are at the delivery point", compute distance/ETA, and (later) match the
 * nearest vendor/courier. lat/lng + the Google place id come from the Places
 * autocomplete / GPS reverse-geocode on the storefront. All nullable & additive.
 * (Table is named `address`, singular, in this schema.)
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('address')) {
            return;
        }
        Schema::table('address', function (Blueprint $table) {
            if (!Schema::hasColumn('address', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }
            if (!Schema::hasColumn('address', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('address', 'google_place_id')) {
                $table->string('google_place_id')->nullable()->after('longitude');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('address')) {
            return;
        }
        Schema::table('address', function (Blueprint $table) {
            foreach (['latitude', 'longitude', 'google_place_id'] as $col) {
                if (Schema::hasColumn('address', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
