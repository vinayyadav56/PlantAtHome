<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * partner_console_orders grows from a console scratchpad into THE partner-order ledger.
 *
 * Until now a partner order lived in one of two places, both lossy: partner_console_orders held
 * only console test-books (2 rows ever), and shipments OVERWRITES provider_order_id on every
 * rebook — four of one day's Porter CRNs existed in NEITHER table an hour later. Historical
 * partner orders were simply not persisted anywhere.
 *
 * partner_status carries the PARTNER'S OWN vocabulary (Porter: open/accepted/live/ended/
 * cancelled/reopened), deliberately separate from last_status (our normalized internal one).
 * Mixing the two is how "live" and "out_for_delivery" ended up interchangeable in debugging
 * sessions.
 *
 * provider_order_id becomes nullable so FAILED create attempts persist too — an attempt that
 * never got a CRN is exactly the row an operator needs when Porter refuses bookings. The
 * composite unique survives: MySQL permits multiple NULLs in a unique index. Failure rows are
 * plain create()s, never updateOrCreate with a null id (Eloquent would turn that into whereNull
 * and collapse every failure into one row).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_console_orders')) {
            return;
        }
        Schema::table('partner_console_orders', function (Blueprint $t) {
            $add = fn (string $col, callable $def) => Schema::hasColumn('partner_console_orders', $col) ?: $def();

            $add('origin', fn () => $t->string('origin', 16)->default('console')->after('partner_code'));
            $add('shipment_id', fn () => $t->unsignedBigInteger('shipment_id')->nullable()->after('origin'));
            $add('order_id', fn () => $t->unsignedBigInteger('order_id')->nullable()->after('shipment_id'));
            $add('partner_status', fn () => $t->string('partner_status', 24)->nullable()->after('last_status'));
            $add('previous_partner_status', fn () => $t->string('previous_partner_status', 24)->nullable()->after('partner_status'));
            $add('status_changed_at', fn () => $t->timestamp('status_changed_at')->nullable()->after('previous_partner_status'));
            $add('status_source', fn () => $t->string('status_source', 16)->nullable()->after('status_changed_at'));
            $add('tracking_url', fn () => $t->text('tracking_url')->nullable()->after('status_source'));
            $add('latest_tracking_payload', fn () => $t->json('latest_tracking_payload')->nullable()->after('tracking_url'));
            $add('simulation_response', fn () => $t->json('simulation_response')->nullable()->after('simulation_started_by'));
            $add('simulation_http_status', fn () => $t->smallInteger('simulation_http_status')->nullable()->after('simulation_response'));
            $add('driver_name', fn () => $t->string('driver_name', 120)->nullable()->after('simulation_http_status'));
            $add('driver_phone', fn () => $t->string('driver_phone', 32)->nullable()->after('driver_name'));
            $add('vehicle_number', fn () => $t->string('vehicle_number', 32)->nullable()->after('driver_phone'));
            $add('last_error', fn () => $t->text('last_error')->nullable()->after('vehicle_number'));
            $add('last_error_payload', fn () => $t->json('last_error_payload')->nullable()->after('last_error'));
            $add('last_error_at', fn () => $t->timestamp('last_error_at')->nullable()->after('last_error_payload'));
            $add('track_failures', fn () => $t->unsignedTinyInteger('track_failures')->default(0)->after('last_error_at'));
            $add('accepted_at', fn () => $t->timestamp('accepted_at')->nullable()->after('track_failures'));
            $add('live_at', fn () => $t->timestamp('live_at')->nullable()->after('accepted_at'));
            $add('ended_at', fn () => $t->timestamp('ended_at')->nullable()->after('live_at'));
            $add('cancelled_at', fn () => $t->timestamp('cancelled_at')->nullable()->after('ended_at'));
            $add('reopened_at', fn () => $t->timestamp('reopened_at')->nullable()->after('cancelled_at'));
        });

        // Nullable provider_order_id: raw statement because change() needs doctrine/dbal on this
        // Laravel version. MySQL only; the sqlite used by tests declares its own stub schema.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE partner_console_orders MODIFY provider_order_id VARCHAR(191) NULL');
        }

        Schema::table('partner_console_orders', function (Blueprint $t) {
            $t->index('last_tracked_at', 'pco_last_tracked_at_idx');
            $t->index('created_at', 'pco_created_at_idx');
            $t->index('partner_status', 'pco_partner_status_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('partner_console_orders')) {
            return;
        }
        Schema::table('partner_console_orders', function (Blueprint $t) {
            $t->dropIndex('pco_last_tracked_at_idx');
            $t->dropIndex('pco_created_at_idx');
            $t->dropIndex('pco_partner_status_idx');
            $t->dropColumn([
                'origin', 'shipment_id', 'order_id', 'partner_status', 'previous_partner_status',
                'status_changed_at', 'status_source', 'tracking_url', 'latest_tracking_payload',
                'simulation_response', 'simulation_http_status', 'driver_name', 'driver_phone',
                'vehicle_number', 'last_error', 'last_error_payload', 'last_error_at',
                'track_failures', 'accepted_at', 'live_at', 'ended_at', 'cancelled_at', 'reopened_at',
            ]);
        });
    }
};
