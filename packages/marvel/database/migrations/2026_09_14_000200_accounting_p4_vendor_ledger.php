<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting P4 — vendor_ledger_entries becomes the per-LINE vendor sub-ledger, linked to
 * its journal entry. The old uq_vle_order_entry UNIQUE(order_id, entry_type) structurally
 * forbade more than one sale row per order (so multi-vendor orders wrote nothing); it is
 * replaced by a UNIQUE idempotency_key ("{order_id}:{order_item_id|0}:{entry_type}:{seq}")
 * — the same three-layer pattern (DB unique + exists() + caught violation), finer grain.
 * Additive; existing rows are backfilled with a key before the constraint lands; the old
 * index is dropped only after the new one exists. Never touches amounts.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return;
        }
        $add = [
            'journal_entry_id'         => fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable()->index(),
            'idempotency_key'          => fn (Blueprint $t) => $t->string('idempotency_key', 191)->nullable(),
            'quantity'                 => fn (Blueprint $t) => $t->integer('quantity')->nullable(),
            'unit_rate'                => fn (Blueprint $t) => $t->decimal('unit_rate', 14, 2)->nullable(),
            'commission_rule_snapshot' => fn (Blueprint $t) => $t->json('commission_rule_snapshot')->nullable(),
            'discount_vendor_funded'   => fn (Blueprint $t) => $t->decimal('discount_vendor_funded', 14, 2)->nullable(),
            'delivery_deduction'       => fn (Blueprint $t) => $t->decimal('delivery_deduction', 14, 2)->nullable(),
            'packaging_deduction'      => fn (Blueprint $t) => $t->decimal('packaging_deduction', 14, 2)->nullable(),
            'penalty'                  => fn (Blueprint $t) => $t->decimal('penalty', 14, 2)->nullable(),
            'vendor_payment_id'        => fn (Blueprint $t) => $t->unsignedBigInteger('vendor_payment_id')->nullable(),
        ];
        foreach ($add as $col => $def) {
            if (!Schema::hasColumn('vendor_ledger_entries', $col)) {
                Schema::table('vendor_ledger_entries', fn (Blueprint $t) => $def($t));
            }
        }

        // Backfill: every legacy row gets a unique key from its own identity (never a guess).
        DB::table('vendor_ledger_entries')->whereNull('idempotency_key')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                DB::table('vendor_ledger_entries')->where('id', $r->id)->update([
                    'idempotency_key' => ((int) $r->order_id) . ':' . ((int) ($r->order_item_id ?? 0)) . ':' . $r->entry_type . ':' . $r->id,
                ]);
            }
        });

        try {
            Schema::table('vendor_ledger_entries', fn (Blueprint $t) => $t->unique('idempotency_key', 'uq_vle_idempotency'));
        } catch (\Throwable $e) {
            // already present
        }
        // The order-level unique blocked per-item rows; drop it now that the finer key exists.
        try {
            Schema::table('vendor_ledger_entries', fn (Blueprint $t) => $t->dropUnique('uq_vle_order_entry'));
        } catch (\Throwable $e) {
            // not present (its own migration swallowed creation on DBs with legacy duplicates)
        }
        try {
            Schema::table('vendor_ledger_entries', fn (Blueprint $t) => $t->index(['order_id', 'entry_type'], 'vle_order_entry_idx'));
        } catch (\Throwable $e) {
            // already present
        }
    }

    public function down(): void
    {
        // Additive on purpose — sub-ledger history is never dropped.
    }
};
