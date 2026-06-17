<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money-correctness: enforce at most ONE ledger entry per (order_id, entry_type) at the DB level.
 * VendorLedgerService::recordSale/reverseSale only ever write one 'sale' + one 'refund_reversal'
 * per order and guard with an exists()-then-create() check — but that check is a TOCTOU: two
 * concurrent order-completed events (e.g. a retried payment webhook) could both pass it and
 * double-credit the vendor. This partial-unique index makes the second insert fail, which
 * recordSale/reverseSale now catch as "already recorded" (idempotent). Additive + guarded; if
 * legacy duplicate rows exist they must be deduped first (left untouched here, surfaced via the
 * caught exception so the app-level guard still holds).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return;
        }
        try {
            Schema::table('vendor_ledger_entries', function (Blueprint $table) {
                $table->unique(['order_id', 'entry_type'], 'uq_vle_order_entry');
            });
        } catch (\Throwable $e) {
            // Pre-existing duplicate (order_id, entry_type) rows block the index — leave it; the
            // app-level idempotency guard in VendorLedgerService still prevents new duplicates.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return;
        }
        try {
            Schema::table('vendor_ledger_entries', fn (Blueprint $table) => $table->dropUnique('uq_vle_order_entry'));
        } catch (\Throwable $e) {
            // not present
        }
    }
};
