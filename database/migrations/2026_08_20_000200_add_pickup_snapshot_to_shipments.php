<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this shipment was ACTUALLY dispatched from, frozen at booking.
 *
 * Until now the pickup was resolved live on every read: `buildRequest` calls
 * pickupLocationFor() → pickupAddressOf() each time. That means a vendor editing their address
 * silently rewrites the pickup of every historical shipment, including delivered ones — the
 * record stops describing what happened and starts describing the vendor's present.
 *
 * A JSON snapshot rather than eight columns: this is a read-only record of what was sent, not
 * business data anything filters on. The queryable link (`pickup_location_id`) already exists on
 * this table — it just had no writer until the same change that added this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            if (!Schema::hasColumn('shipments', 'pickup_snapshot')) {
                $t->json('pickup_snapshot')->nullable()->after('pickup_location_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            if (Schema::hasColumn('shipments', 'pickup_snapshot')) {
                $t->dropColumn('pickup_snapshot');
            }
        });
    }
};
