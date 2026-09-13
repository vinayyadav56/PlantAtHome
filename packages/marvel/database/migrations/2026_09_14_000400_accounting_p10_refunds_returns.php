<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting P10 — refunds become sliceable (full | partial amount | items) and journaled,
 * with a GST credit note per posted refund and a returns lifecycle. refunds.status stays the
 * existing MySQL enum (no ALTER); everything here is additive and guarded.
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
        $add('refunds', [
            'scope'                 => fn (Blueprint $t) => $t->string('scope', 8)->default('full'),   // full | partial | items
            'requested_amount'      => fn (Blueprint $t) => $t->decimal('requested_amount', 14, 2)->nullable(),
            'method'                => fn (Blueprint $t) => $t->string('method', 12)->nullable(),       // wallet | gateway | manual
            'gateway_refund_id'     => fn (Blueprint $t) => $t->string('gateway_refund_id', 120)->nullable(),
            'refunded_at'           => fn (Blueprint $t) => $t->timestamp('refunded_at')->nullable(),
            'idempotency_key'       => fn (Blueprint $t) => $t->string('idempotency_key', 191)->nullable()->unique(),
            'journal_entry_id'      => fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable(),
            'paid_journal_entry_id' => fn (Blueprint $t) => $t->unsignedBigInteger('paid_journal_entry_id')->nullable(),
            'credit_note_id'        => fn (Blueprint $t) => $t->unsignedBigInteger('credit_note_id')->nullable(),
        ]);

        if (!Schema::hasTable('refund_items')) {
            Schema::create('refund_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('refund_id')->index();
                $t->unsignedBigInteger('order_item_id')->index();
                $t->integer('quantity')->default(1);
                $t->decimal('amount', 14, 2)->default(0);        // what the customer gets back for this slice
                $t->decimal('taxable_value', 14, 2)->default(0);
                $t->decimal('tax_amount', 14, 2)->default(0);
                $t->decimal('cgst_amount', 14, 2)->default(0);
                $t->decimal('sgst_amount', 14, 2)->default(0);
                $t->decimal('igst_amount', 14, 2)->default(0);
                $t->decimal('discount_amount', 14, 2)->default(0); // platform-funded share reversed
                $t->decimal('vendor_share', 14, 2)->default(0);    // vendor payable reversed
                $t->unsignedBigInteger('shop_id')->nullable();
                $t->timestamps();
                $t->unique(['refund_id', 'order_item_id']);
            });
        }

        if (!Schema::hasTable('credit_notes')) {
            Schema::create('credit_notes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('number', 32)->unique();      // CN-FY-000001
                $t->unsignedBigInteger('order_id')->index();
                $t->unsignedBigInteger('refund_id')->nullable()->index();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->date('issue_date');
                $t->decimal('taxable_value', 14, 2)->default(0);
                $t->decimal('cgst_amount', 14, 2)->default(0);
                $t->decimal('sgst_amount', 14, 2)->default(0);
                $t->decimal('igst_amount', 14, 2)->default(0);
                $t->decimal('total', 14, 2)->default(0);
                $t->string('reason', 500)->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->json('lines')->nullable();           // per-line slices (HSN, qty, taxable, tax)
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('return_requests')) {
            Schema::create('return_requests', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('order_id')->index();
                $t->unsignedBigInteger('order_item_id')->index();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->integer('quantity')->default(1);
                $t->string('status', 12)->default('requested'); // requested|approved|received|refunded|rejected
                $t->string('reason', 500)->nullable();
                $t->unsignedBigInteger('shipment_id')->nullable();   // reverse leg (shipments.return_*)
                $t->unsignedBigInteger('refund_id')->nullable();
                $t->string('requested_by', 64)->nullable();
                $t->string('decided_by', 64)->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('refunded_at')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index(['order_item_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('refund_items');
    }
};
