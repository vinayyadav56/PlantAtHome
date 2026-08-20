<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partner's own human-facing order number, when it differs from the id we call the API with.
 *
 * Borzo forced this: its cabinet lists `order_name` ("Order No. 30321") while every API call —
 * track, cancel — takes `order_id` (330321). We only ever parsed order_id, so the admin showed a
 * number that appears nowhere in Borzo's UI and an operator could not match the two screens.
 *
 * Deliberately a SEPARATE column rather than overloading provider_shipment_id: that field means
 * "the partner's shipment id" (Shiprocket uses it) and reusing it would make two different facts
 * share one column. Nullable — most partners have a single id and will leave it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            if (!Schema::hasColumn('shipments', 'provider_reference')) {
                $t->string('provider_reference', 64)->nullable()->after('provider_order_id');
            }
        });
        Schema::table('partner_console_orders', function (Blueprint $t) {
            if (!Schema::hasColumn('partner_console_orders', 'provider_reference')) {
                $t->string('provider_reference', 64)->nullable()->after('provider_order_id');
            }
        });
    }

    public function down(): void
    {
        foreach (['shipments', 'partner_console_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'provider_reference')) {
                    $t->dropColumn('provider_reference');
                }
            });
        }
    }
};
