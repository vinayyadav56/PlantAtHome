<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor delivery capability: does this shop fulfil its own deliveries
 * ('self') or ride the platform's courier/DP stack ('platform', default)?
 *
 * VOCABULARY NOTE — three delivery_mode columns, three meanings, on purpose:
 *   shops.delivery_mode     'platform'|'self'                      — vendor capability (this one)
 *   orders.delivery_mode    vendor_dp|separate_dp|courier_*        — DP-assignment outcome
 *   shipments.delivery_mode 'self'|NULL                            — per-shipment routing key
 *                                                                    (NULL/anything-else = courier-eligible)
 *
 * Columns, not settings keys: PUT /shops/{id} full-replaces the settings
 * JSON, so anything stored there is wiped by unrelated vendor-profile saves.
 *
 * self_delivery JSON = operational metadata only (contact_name, contact_phone,
 * radius_km, same_day, cod, hours, notes). Delivery FEE and ETA deliberately
 * stay in vendor_shipping_rates / vendor_service_areas.eta_days — the estimate
 * engine already reads those; no second pricing source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('delivery_mode', 16)->default('platform');
            $table->json('self_delivery')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['delivery_mode', 'self_delivery']);
        });
    }
};
