<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the pickup a vendor gets from its own registration (VendorPickupLocation::openForNewVendor),
 * so courier:register-vendor-pickups registers it at Shiprocket once the vendor is live. Every row
 * that exists today defaults to false: doors backfilled on 2026-08-14 or added by hand keep being
 * registered by a person, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vendor_pickup_locations') || Schema::hasColumn('vendor_pickup_locations', 'auto_register')) {
            return;
        }
        Schema::table('vendor_pickup_locations', function (Blueprint $table) {
            $table->boolean('auto_register')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendor_pickup_locations', 'auto_register')) {
            Schema::table('vendor_pickup_locations', function (Blueprint $table) {
                $table->dropColumn('auto_register');
            });
        }
    }
};
