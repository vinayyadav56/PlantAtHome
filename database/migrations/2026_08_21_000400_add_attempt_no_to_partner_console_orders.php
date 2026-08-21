<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which booking attempt this ledger row is, for one shipment.
 *
 * A shipment keeps ONE row in `shipments` across every attempt — only provider_order_id
 * changes — so "Porter refused, Borzo carried it" was only ever recoverable from logs.
 * Worse, a FAILED attempt was persisted nowhere at all: book() writes failure_reason onto
 * the shipment and logs, and ingestShipments() filters whereNotNull('provider_order_id'),
 * so an attempt that never got a CRN existed in no table. This is the counter that turns
 * this ledger into the per-attempt history the shipments row cannot hold.
 *
 * NOT unique: the value is computed as count+1, and two operators clicking dispatch at the
 * same moment would collide on a unique index and surface as a 500 instead of two rows
 * both numbered 2.
 *
 * ponytail: count+1 counter, racy under concurrent dispatch; a per-shipment sequence if
 * that ever matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_console_orders')) {
            return;
        }
        Schema::table('partner_console_orders', function (Blueprint $t) {
            if (!Schema::hasColumn('partner_console_orders', 'attempt_no')) {
                $t->unsignedSmallInteger('attempt_no')->default(1);
            }
        });
        try {
            Schema::table('partner_console_orders', fn (Blueprint $t) => $t->index(
                ['shipment_id', 'attempt_no'],
                'pco_shipment_attempt_idx'
            ));
        } catch (\Throwable $e) {
            // already indexed
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('partner_console_orders') || !Schema::hasColumn('partner_console_orders', 'attempt_no')) {
            return;
        }
        // Drop the INDEX first: sqlite refuses to drop a column an index still references, so
        // a rollback on the test connection failed outright.
        try {
            Schema::table('partner_console_orders', fn (Blueprint $t) => $t->dropIndex('pco_shipment_attempt_idx'));
        } catch (\Throwable $e) {
            // never indexed on this box
        }
        Schema::table('partner_console_orders', fn (Blueprint $t) => $t->dropColumn('attempt_no'));
    }
};
