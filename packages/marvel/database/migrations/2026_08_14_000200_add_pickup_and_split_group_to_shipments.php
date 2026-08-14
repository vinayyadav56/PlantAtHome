<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment-level gaps found in the courier audit. All additive + nullable.
 *
 * pickup_location_id — which of the vendor's doors THIS leg leaves from. Booking reads
 *   shops.pickup_location_name, so every leg of a multi-yard vendor was sent from the
 *   same nickname regardless of where the stock actually sits.
 * invoice_url — Shiprocket mints an invoice alongside the label; label_url and
 *   manifest_url have columns, the invoice had nowhere to land.
 * pickup_scheduled_at / pickup_token — schedulePickup() returns both and we discard
 *   them, so nothing can tell an operator when the courier is actually coming, and a
 *   re-schedule can't be distinguished from a first one.
 * split_group — order_items HAS this column, shipments does NOT, yet
 *   OrderItemService::regroup() keys its carry-over map on `$s->split_group` — which is
 *   silently null on every row. Result: every regroup of a split order loses that
 *   parcel's shipping_cost, lane override (mode) and expected_delivery_at.
 *
 * NO courier_id column: shipments.courier_company_id already exists (unsignedInteger
 * nullable, added June, zero readers and zero writers to this day) and holds exactly
 * the Shiprocket courier_company_id that serviceability returns and assign/awb takes
 * as courier_id. Adding a second column for the same integer would just give us two
 * dead ones. Writers go on courier_company_id.
 *
 * split_group is varchar(16) to match order_items.split_group — grouping joins the two
 * values as strings, so a narrower column here would truncate long groups into
 * collisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'pickup_location_id')) {
                $table->unsignedBigInteger('pickup_location_id')->nullable();
                $table->index('pickup_location_id');
            }
            if (!Schema::hasColumn('shipments', 'invoice_url')) {
                $table->string('invoice_url', 512)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'pickup_scheduled_at')) {
                $table->timestamp('pickup_scheduled_at')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'pickup_token')) {
                $table->string('pickup_token', 64)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'split_group')) {
                $table->string('split_group', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }
        // ONE dropColumn call, not one per column: sqlite refuses multiple dropColumn
        // commands in a single blueprint, which is why several older shipments
        // migrations cannot actually be rolled back on the test connection.
        $drop = array_values(array_filter(
            ['pickup_location_id', 'invoice_url', 'pickup_scheduled_at', 'pickup_token', 'split_group'],
            fn ($c) => Schema::hasColumn('shipments', $c),
        ));
        if ($drop) {
            Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn($drop));
        }
    }
};
