<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting P11 — inventory ledger + weighted-average valuation for PLATFORM_OWNED stock
 * (spec §20-21) and the purchase flow tables (designed, posted by service, no UI yet).
 * VENDOR_SUPPLIED stock never touches these tables. Additive.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('product_id')->index();
                $t->unsignedBigInteger('variation_option_id')->default(0);
                $t->unsignedBigInteger('warehouse_id')->default(0);
                $t->string('type', 16);                 // receipt|sale|return|adjustment|damage|transfer_in|transfer_out
                $t->integer('quantity');                // signed: + in, − out
                $t->decimal('unit_cost', 14, 4)->default(0);
                $t->decimal('total_cost', 14, 2)->default(0); // signed, = quantity × unit_cost
                $t->string('reference_type', 32)->nullable();  // order_item|return_request|goods_receipt|manual
                $t->unsignedBigInteger('reference_id')->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->string('idempotency_key', 191)->unique();
                $t->string('note', 500)->nullable();
                $t->string('created_by', 64)->nullable();
                $t->timestamps();
                $t->index(['product_id', 'variation_option_id', 'warehouse_id']);
                $t->index(['reference_type', 'reference_id']);
            });
        }
        if (!Schema::hasTable('inventory_valuations')) {
            Schema::create('inventory_valuations', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('variation_option_id')->default(0);
                $t->unsignedBigInteger('warehouse_id')->default(0);
                $t->integer('qty_on_hand')->default(0);
                $t->decimal('avg_unit_cost', 14, 4)->default(0);
                $t->decimal('total_value', 14, 2)->default(0);   // = what GL 1040 carries for this stock line
                $t->timestamps();
                $t->unique(['product_id', 'variation_option_id', 'warehouse_id'], 'uq_inventory_valuation');
            });
        }
        // Purchase flow (spec §21): posted via InventoryLedgerService/VendorBillService, no admin UI this pass.
        if (!Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('number', 32)->unique();
                $t->unsignedBigInteger('supplier_shop_id')->nullable()->index();
                $t->string('status', 16)->default('draft'); // draft|sent|partially_received|received|cancelled
                $t->date('order_date')->nullable();
                $t->date('expected_at')->nullable();
                $t->decimal('subtotal', 14, 2)->default(0);
                $t->decimal('tax_total', 14, 2)->default(0);
                $t->decimal('total', 14, 2)->default(0);
                $t->text('notes')->nullable();
                $t->string('created_by', 64)->nullable();
                $t->timestamps();
            });
            Schema::create('purchase_order_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('purchase_order_id')->index();
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('variation_option_id')->default(0);
                $t->integer('quantity');
                $t->integer('received_quantity')->default(0);
                $t->decimal('unit_cost', 14, 4);
                $t->decimal('tax_rate', 7, 4)->default(0);
                $t->string('hsn_code', 16)->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('purchase_order_id')->nullable()->index();
                $t->unsignedBigInteger('supplier_shop_id')->nullable();
                $t->unsignedBigInteger('warehouse_id')->default(0);
                $t->date('received_at');
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->string('idempotency_key', 191)->unique();
                $t->text('notes')->nullable();
                $t->string('received_by', 64)->nullable();
                $t->timestamps();
            });
            Schema::create('goods_receipt_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('goods_receipt_id')->index();
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('variation_option_id')->default(0);
                $t->integer('quantity');
                $t->decimal('unit_cost', 14, 4);
                $t->unsignedBigInteger('inventory_transaction_id')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('vendor_bills')) {
            Schema::create('vendor_bills', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('number', 64);
                $t->unsignedBigInteger('supplier_shop_id')->nullable()->index();
                $t->unsignedBigInteger('goods_receipt_id')->nullable();
                $t->date('bill_date');
                $t->date('due_date')->nullable();
                $t->decimal('subtotal', 14, 2)->default(0);
                $t->decimal('input_gst', 14, 2)->default(0);      // CA flag: 1060 Input GST Receivable
                $t->decimal('total', 14, 2)->default(0);
                $t->decimal('amount_paid', 14, 2)->default(0);
                $t->string('status', 16)->default('open');       // open|partially_paid|paid|cancelled
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->string('idempotency_key', 191)->unique();
                $t->timestamps();
            });
            Schema::create('vendor_bill_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('vendor_bill_id')->index();
                $t->string('description', 255);
                $t->unsignedBigInteger('product_id')->nullable();
                $t->integer('quantity')->default(1);
                $t->decimal('unit_cost', 14, 4)->default(0);
                $t->decimal('tax_rate', 7, 4)->default(0);
                $t->decimal('amount', 14, 2)->default(0);
                $t->string('account_role', 32)->default('inventory'); // inventory|packaging_cost|other_expenses
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['vendor_bill_items', 'vendor_bills', 'goods_receipt_items', 'goods_receipts', 'purchase_order_items', 'purchase_orders', 'inventory_valuations', 'inventory_transactions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
