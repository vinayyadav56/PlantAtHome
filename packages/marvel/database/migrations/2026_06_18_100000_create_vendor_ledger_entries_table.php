<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P2 — vendor ledger. A per-event money record for each fulfilled
 * (and refunded) order/item, mirroring the proven delivery_partner_earnings pattern.
 * `amount` is the signed vendor earning (credit + / debit −); the breakdown columns
 * explain it (product value, commission, platform fee, payment-gateway fee). Earnings
 * become settle-eligible at `available_at` (delivered + N days, the T+N hold). Runs in
 * PARALLEL with the legacy order-level `balances` credit until the cutover, so it never
 * mutates `balances` itself — the new settlement layer reads only this table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_ledger_entries')) {
            return;
        }
        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('order_item_id')->nullable()->index(); // per-item once order_items lands (P4)
            $table->unsignedBigInteger('shipment_id')->nullable();
            // sale | commission | platform_fee | pg_fee | shipping_revenue | refund_reversal | adjustment
            $table->string('entry_type', 32);
            $table->decimal('amount', 14, 2)->default(0); // signed net vendor earning for this event
            // breakdown snapshot (signed; negative on a reversal)
            $table->decimal('product_value', 14, 2)->nullable();
            $table->decimal('commission_amount', 14, 2)->nullable();
            $table->decimal('platform_fee', 14, 2)->nullable();
            $table->decimal('pg_fee', 14, 2)->nullable();
            $table->decimal('shipping_revenue', 14, 2)->nullable();
            $table->string('source', 32)->default('delivery'); // delivery | refund | manual | rule:*
            $table->string('status', 16)->default('pending');  // pending | settled | reversed
            $table->timestamp('available_at')->nullable();      // delivered_at + N (T+N hold)
            $table->unsignedBigInteger('vendor_settlement_id')->nullable()->index();
            $table->timestamp('earned_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'available_at']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_ledger_entries');
    }
};
