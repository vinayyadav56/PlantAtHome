<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting P3 — orders + payments integration. Additive, hasColumn-guarded:
 *  - payment_events: the gateway-event ledger the codebase lacked (UNIQUE per gateway
 *    payment id, so a replayed capture webhook / reconcile run is a no-op).
 *  - orders: financial recognition state + the captured payment facts.
 *  - order_items: per-line financial snapshot (ownership, discount + funder, delivery
 *    allocation, vendor payable) frozen at recognition.
 *  - products.ownership_model, shops.recognition_mode / commission_mode (spec §1, D1, D2).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('gateway', 24);
                $t->string('event_id', 120);          // "<gateway_payment_id>:<event_type>"
                $t->string('event_type', 32);         // captured | refunded | failed
                $t->string('gateway_payment_id', 120)->nullable()->index();
                $t->string('gateway_order_id', 120)->nullable()->index();
                $t->unsignedBigInteger('order_id')->nullable()->index();
                $t->decimal('amount', 14, 2)->default(0);
                $t->decimal('fee', 14, 2)->nullable();
                $t->decimal('tax_on_fee', 14, 2)->nullable();
                $t->string('currency', 3)->default('INR');
                $t->string('status', 24)->nullable();
                $t->string('payload_hash', 64)->nullable();
                $t->json('payload')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('processed_at')->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->timestamps();
                $t->unique(['gateway', 'event_id'], 'uq_payment_events_gateway_event');
            });
        }

        $add = function (string $table, array $cols): void {
            if (!Schema::hasTable($table)) {
                return;
            }
            foreach ($cols as $name => $def) {
                if (!Schema::hasColumn($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $def($t));
                }
            }
        };

        $add('orders', [
            'financial_status'        => fn (Blueprint $t) => $t->string('financial_status', 24)->nullable()->index(), // unrecognized|recognized|derecognized|requires_reconciliation
            'recognized_at'           => fn (Blueprint $t) => $t->timestamp('recognized_at')->nullable(),
            'recognition_journal_id'  => fn (Blueprint $t) => $t->unsignedBigInteger('recognition_journal_id')->nullable(),
            'gateway_payment_id'      => fn (Blueprint $t) => $t->string('gateway_payment_id', 120)->nullable()->index(),
            'captured_amount'         => fn (Blueprint $t) => $t->decimal('captured_amount', 14, 2)->nullable(),
            'captured_at'             => fn (Blueprint $t) => $t->timestamp('captured_at')->nullable(),
            'cod_collected_at'        => fn (Blueprint $t) => $t->timestamp('cod_collected_at')->nullable(),
        ]);

        $add('order_items', [
            'ownership_model'         => fn (Blueprint $t) => $t->string('ownership_model', 16)->nullable(),
            'discount_amount'         => fn (Blueprint $t) => $t->decimal('discount_amount', 14, 2)->nullable(),
            'discount_funded_by'      => fn (Blueprint $t) => $t->string('discount_funded_by', 8)->nullable(), // platform | vendor
            'delivery_allocation'     => fn (Blueprint $t) => $t->decimal('delivery_allocation', 14, 2)->nullable(),
            'vendor_payable_snapshot' => fn (Blueprint $t) => $t->decimal('vendor_payable_snapshot', 14, 2)->nullable(),
            'commission_mode_snapshot'   => fn (Blueprint $t) => $t->string('commission_mode_snapshot', 16)->nullable(),
            'commission_rate_snapshot'   => fn (Blueprint $t) => $t->decimal('commission_rate_snapshot', 7, 4)->nullable(),
            'commission_amount_snapshot' => fn (Blueprint $t) => $t->decimal('commission_amount_snapshot', 14, 2)->nullable(),
            'recognized_at'           => fn (Blueprint $t) => $t->timestamp('recognized_at')->nullable(),
            'journal_entry_id'        => fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable(),
        ]);

        $add('products', [
            'ownership_model' => fn (Blueprint $t) => $t->string('ownership_model', 16)->default('VENDOR_SUPPLIED'),
        ]);

        $add('shops', [
            'recognition_mode' => fn (Blueprint $t) => $t->string('recognition_mode', 12)->default('principal'), // principal | agent
            'commission_mode'  => fn (Blueprint $t) => $t->string('commission_mode', 16)->default('cost_sheet'),  // cost_sheet | commission
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        // Snapshot columns are kept on purpose — dropping them would destroy financial history.
    }
};
