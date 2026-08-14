<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exception surface of courier fulfilment: NDR, RTO and returns. All additive + nullable.
 *
 * Today mapShiprocketStatus collapses RTO_INITIATED / NDR / DELIVERED into one terminal "rto"
 * and a bare "Undelivered" maps to nothing at all, so an undelivered parcel is invisible: ops
 * cannot see WHY it failed, how many delivery attempts the courier has burned, or whether anyone
 * actioned the NDR before the 3-attempt window closes and it auto-RTOs. Once it does bounce there
 * is nowhere to record WHEN, and a customer-initiated return (orders/create/return — a REVERSE
 * shipment, a different business process from an RTO) mints its own AWB and order id with no
 * column to land in, so it cannot be tracked at all.
 *
 * ndr_reason           courier's reason text for the failed delivery attempt
 * ndr_attempts         delivery attempts burned (Shiprocket auto-RTOs after 3)
 * ndr_action_at        when WE last submitted an NDR action (POST ndr/{awb}/action)
 * rto_at               when the parcel actually turned back — applyNormalizedStatus stamps the
 *                      status and reuses failure_reason for the text, but had no timestamp
 * return_awb           / return_provider_order_id — the REVERSE shipment's own identifiers; the
 *                      forward awb_number / provider_order_id must stay as the audit trail of
 *                      what was shipped out, so a return cannot overwrite them
 *
 * Deliberately NOT re-added here (verified against the current schema, not assumed):
 *   invoice_url, pickup_scheduled_at, pickup_token — 2026_08_14_000200 (commit 77abf22)
 *   manifest_url, label_url                        — 2026_06_23 (manifest_url still unwritten)
 * hasColumn guards on every column anyway: these tables are migrated on live boxes where an
 * earlier partial run is the normal case.
 */
return new class extends Migration
{
    /** @var string[] */
    private array $cols = [
        'ndr_reason', 'ndr_attempts', 'ndr_action_at',
        'rto_at', 'return_awb', 'return_provider_order_id',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'ndr_reason')) {
                $table->string('ndr_reason', 255)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'ndr_attempts')) {
                // Couriers give up at 3; tinyint is four sizes too big already.
                $table->unsignedTinyInteger('ndr_attempts')->default(0);
            }
            if (!Schema::hasColumn('shipments', 'ndr_action_at')) {
                $table->timestamp('ndr_action_at')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'rto_at')) {
                $table->timestamp('rto_at')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'return_awb')) {
                $table->string('return_awb', 64)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'return_provider_order_id')) {
                $table->string('return_provider_order_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }
        // ONE dropColumn call: sqlite refuses several in a single blueprint (which is why some
        // older shipments migrations cannot be rolled back on the test connection at all).
        $drop = array_values(array_filter(
            $this->cols,
            fn ($c) => Schema::hasColumn('shipments', $c),
        ));
        if ($drop) {
            Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn($drop));
        }
    }
};
