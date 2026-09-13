<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting foundation (double-entry). The general ledger: a configurable chart of
 * accounts, accounting periods, journal entries + lines, a row-locked sequence table
 * for gapless numbering, and an append-only audit log. Every table is additive and
 * guarded (Railway re-runs migrations on every deploy); down() only drops what this
 * migration created. Money is DECIMAL(14,2) — never float (spec §44).
 *
 * Idempotency lives in the schema: acc_journal_entries.source_key is UNIQUE, so a
 * replayed webhook / status event / cron cannot post the same financial event twice.
 */
return new class extends Migration {
    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';

        if (!Schema::hasTable('acc_accounts')) {
            Schema::create('acc_accounts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('code', 16)->unique();
                $t->string('name', 120);
                $t->string('type', 16);          // asset | liability | equity | revenue | expense
                $t->string('normal_side', 6);    // debit | credit
                $t->unsignedBigInteger('parent_id')->nullable()->index();
                $t->text('description')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('is_system')->default(false); // seeded/mapped accounts: deactivate-only
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['type', 'is_active']);
            });
        }

        if (!Schema::hasTable('acc_accounting_periods')) {
            Schema::create('acc_accounting_periods', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('period_start')->unique();
                $t->date('period_end');
                $t->string('status', 8)->default('open'); // open | closed
                $t->unsignedBigInteger('closed_by')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index('status');
            });
        }

        if (!Schema::hasTable('acc_sequences')) {
            Schema::create('acc_sequences', function (Blueprint $t) {
                $t->string('key', 64)->primary();
                $t->unsignedBigInteger('next_value')->default(1);
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('acc_journal_entries')) {
            Schema::create('acc_journal_entries', function (Blueprint $t) use ($mysql) {
                $t->bigIncrements('id');
                $t->string('entry_number', 32)->nullable()->unique(); // assigned at POST (JE-YYYY-000001)
                $t->date('entry_date')->index();
                $t->unsignedBigInteger('period_id')->nullable()->index();
                $t->string('status', 10)->default('draft')->index(); // draft | posted | reversed
                $t->string('source_type', 40);                       // ORDER_RECOGNIZED | PAYMENT_CAPTURED | ...
                $t->string('source_id', 64)->nullable();
                $t->string('source_key', 191)->unique();             // THE idempotency key
                $t->string('reference_type', 40)->nullable();
                $t->unsignedBigInteger('reference_id')->nullable();
                $t->string('description', 500)->nullable();
                $t->string('currency', 3)->default('INR');
                $t->decimal('total_debit', 14, 2)->default(0);
                $t->decimal('total_credit', 14, 2)->default(0);
                $t->unsignedBigInteger('reverses_entry_id')->nullable()->index();
                $t->unsignedBigInteger('reversed_by_entry_id')->nullable();
                $t->boolean('requires_reconciliation')->default(false)->index();
                $t->json('metadata')->nullable();
                $t->string('created_by', 64)->nullable(); // user id or system:<name>
                $t->string('posted_by', 64)->nullable();
                $t->timestamp('posted_at')->nullable();
                $t->timestamps();
                $t->index(['source_type', 'source_id']);
                $t->index(['reference_type', 'reference_id']);
                if ($mysql) {
                    $t->foreign('period_id')->references('id')->on('acc_accounting_periods');
                }
            });
        }

        if (!Schema::hasTable('acc_journal_lines')) {
            Schema::create('acc_journal_lines', function (Blueprint $t) use ($mysql) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('journal_entry_id')->index();
                $t->unsignedSmallInteger('line_no');
                $t->unsignedBigInteger('account_id');
                $t->date('entry_date'); // denormalised from the entry for GL paging / running balances
                $t->decimal('debit', 14, 2)->default(0);
                $t->decimal('credit', 14, 2)->default(0);
                // sub-ledger dimensions
                $t->unsignedBigInteger('shop_id')->nullable();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->unsignedBigInteger('order_id')->nullable()->index();
                $t->unsignedBigInteger('order_item_id')->nullable()->index();
                $t->unsignedBigInteger('settlement_id')->nullable();
                $t->unsignedBigInteger('vendor_payment_id')->nullable();
                $t->unsignedBigInteger('refund_id')->nullable();
                $t->unsignedBigInteger('shipment_id')->nullable();
                $t->string('tax_kind', 4)->nullable();   // cgst | sgst | igst
                $t->string('hsn_code', 16)->nullable();
                $t->decimal('tax_rate', 7, 4)->nullable();
                $t->string('description', 500)->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->index(['account_id', 'entry_date']);
                $t->index(['shop_id', 'account_id']);
                $t->index(['customer_id', 'account_id']);
                if ($mysql) {
                    $t->foreign('journal_entry_id')->references('id')->on('acc_journal_entries')->onDelete('cascade');
                    $t->foreign('account_id')->references('id')->on('acc_accounts');
                }
            });
        }

        if (!Schema::hasTable('acc_audit_log')) {
            Schema::create('acc_audit_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('auditable_type', 64);
                $t->string('auditable_id', 64);
                $t->string('action', 40);
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->text('reason')->nullable();
                $t->string('reference', 191)->nullable();
                $t->string('actor_type', 16)->nullable(); // user | system
                $t->string('actor_id', 64)->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['auditable_type', 'auditable_id']);
                $t->index('action');
            });
        }
    }

    public function down(): void
    {
        foreach (['acc_audit_log', 'acc_journal_lines', 'acc_journal_entries', 'acc_sequences', 'acc_accounting_periods', 'acc_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
