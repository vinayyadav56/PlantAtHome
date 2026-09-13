<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting P8/P9 — settlements gain the spec §22 shape (period, opening/new/deductions/
 * refunds/adjustments/total, amount_paid/remaining, approval), and two new tables:
 * vendor_payments (PARTIAL payments, each a journal + ledger row + legacy withdraws
 * mirror) and vendor_adjustments (credit/debit with reason + approval, never a balance
 * edit). Additive and guarded; nothing historical is rewritten.
 */
return new class extends Migration {
    public function up(): void
    {
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

        $add('vendor_settlements', [
            'period_from'       => fn (Blueprint $t) => $t->date('period_from')->nullable(),
            'period_to'         => fn (Blueprint $t) => $t->date('period_to')->nullable(),
            'opening_payable'   => fn (Blueprint $t) => $t->decimal('opening_payable', 16, 2)->default(0),
            'new_payable'       => fn (Blueprint $t) => $t->decimal('new_payable', 16, 2)->default(0),
            'deductions'        => fn (Blueprint $t) => $t->decimal('deductions', 16, 2)->default(0),
            'refunds'           => fn (Blueprint $t) => $t->decimal('refunds', 16, 2)->default(0),
            'adjustments'       => fn (Blueprint $t) => $t->decimal('adjustments', 16, 2)->default(0),
            'total_payable'     => fn (Blueprint $t) => $t->decimal('total_payable', 16, 2)->default(0),
            'amount_paid'       => fn (Blueprint $t) => $t->decimal('amount_paid', 16, 2)->default(0),
            'remaining_payable' => fn (Blueprint $t) => $t->decimal('remaining_payable', 16, 2)->default(0),
            'approved_by'       => fn (Blueprint $t) => $t->unsignedBigInteger('approved_by')->nullable(),
            'approved_at'       => fn (Blueprint $t) => $t->timestamp('approved_at')->nullable(),
            'cancelled_at'      => fn (Blueprint $t) => $t->timestamp('cancelled_at')->nullable(),
            'notes'             => fn (Blueprint $t) => $t->text('notes')->nullable(),
        ]);
        $add('settlement_runs', [
            'period_from' => fn (Blueprint $t) => $t->date('period_from')->nullable(),
            'period_to'   => fn (Blueprint $t) => $t->date('period_to')->nullable(),
            'cadence'     => fn (Blueprint $t) => $t->string('cadence', 12)->nullable(),
        ]);

        if (!Schema::hasTable('vendor_payments')) {
            Schema::create('vendor_payments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('shop_id')->index();
                $t->unsignedBigInteger('vendor_settlement_id')->nullable()->index();
                $t->decimal('amount', 14, 2);
                $t->date('payment_date');
                $t->string('payment_method', 20); // bank_transfer|neft|rtgs|imps|upi|other|settlement
                $t->string('bank_reference', 120)->nullable();
                $t->string('transaction_reference', 120)->nullable();
                $t->string('status', 12)->default('completed'); // pending|completed|failed|cancelled
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('withdraw_id')->nullable();   // legacy mirror row (vendor/admin lists)
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->string('idempotency_key', 191)->unique();
                $t->string('created_by', 64)->nullable();
                $t->string('approved_by', 64)->nullable();
                $t->timestamps();
                $t->index(['shop_id', 'status']);
            });
        }

        if (!Schema::hasTable('vendor_adjustments')) {
            Schema::create('vendor_adjustments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('shop_id')->index();
                $t->string('type', 8);                 // credit | debit (credit = we owe the vendor more)
                $t->decimal('amount', 14, 2);
                $t->string('reason', 500);
                $t->string('reference', 191)->nullable();
                $t->date('effective_date');
                $t->string('status', 20)->default('pending_approval'); // pending_approval|approved|rejected
                $t->boolean('requires_approval')->default(true);
                $t->string('created_by', 64)->nullable();
                $t->string('approved_by', 64)->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('ledger_entry_id')->nullable();
                $t->timestamps();
                $t->index(['shop_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_adjustments');
        Schema::dropIfExists('vendor_payments');
        // settlement columns are kept — financial history
    }
};
