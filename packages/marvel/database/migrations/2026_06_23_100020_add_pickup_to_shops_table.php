<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration C1 — the vendor's registered courier pickup location. The street/city/
 * state come from the existing shops.address JSON; these store the provider pickup nickname
 * and pincode. Additive + nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'pickup_location_name')) {
                $table->string('pickup_location_name', 64)->nullable();
            }
            if (!Schema::hasColumn('shops', 'pickup_postcode')) {
                $table->string('pickup_postcode', 12)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            foreach (['pickup_location_name', 'pickup_postcode'] as $c) {
                if (Schema::hasColumn('shops', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
