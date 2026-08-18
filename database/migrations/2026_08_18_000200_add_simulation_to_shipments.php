<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which UAT flow is running against a SHIPMENT.
 *
 * `partner_console_orders` already has these columns and CourierPartnerProxyController stamps them,
 * but that stamp is a `where(...)->update(...)` and a console row only exists for orders the
 * Integrations console itself created. The simulator people actually use runs on a real shipment's
 * CRN, where there is no console row — so the stamp has always written zero rows, silently. Live
 * check on staging: two console rows, both with simulation_flow_type NULL.
 *
 * Putting it on `shipments` is what lets a refreshed page say which flow is running: the admin
 * already loads every shipment column with the order, so restoring the panel costs no extra
 * request. Without this the only record was a module-level Map in the browser, which a refresh
 * empties — the reported bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }
        Schema::table('shipments', function (Blueprint $t) {
            if (!Schema::hasColumn('shipments', 'simulation_flow_type')) {
                // Porter documents flows 0..7; nullable because the vast majority of shipments are
                // real deliveries that were never simulated.
                $t->unsignedTinyInteger('simulation_flow_type')->nullable()->after('pickup_token');
            }
            if (!Schema::hasColumn('shipments', 'simulation_started_at')) {
                $t->timestamp('simulation_started_at')->nullable()->after('simulation_flow_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }
        Schema::table('shipments', function (Blueprint $t) {
            foreach (['simulation_flow_type', 'simulation_started_at'] as $c) {
                if (Schema::hasColumn('shipments', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
