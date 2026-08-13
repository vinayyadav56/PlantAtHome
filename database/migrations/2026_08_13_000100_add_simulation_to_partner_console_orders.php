<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that an operator started Porter's UAT order-flow simulator against a console order.
 *
 * Extends the existing console-order audit rather than adding a table: a simulation is another
 * thing that happened to a partner order we already track, and a second ledger would need joining
 * to answer "what was done to this CRN".
 *
 * Nullable throughout — the overwhelming majority of console orders are never simulated, and the
 * simulator is only reachable on staging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_console_orders', function (Blueprint $t) {
            $t->unsignedTinyInteger('simulation_flow_type')->nullable()->after('last_tracked_at');
            $t->timestamp('simulation_started_at')->nullable()->after('simulation_flow_type');
            $t->unsignedBigInteger('simulation_started_by')->nullable()->after('simulation_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('partner_console_orders', function (Blueprint $t) {
            $t->dropColumn(['simulation_flow_type', 'simulation_started_at', 'simulation_started_by']);
        });
    }
};
