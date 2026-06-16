<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P2 — one payout per vendor per settlement run. Aggregates the
 * vendor's settle-eligible ledger entries (net of refunds) into a single net_payable;
 * admin "Pay" creates a `withdraws` row (the existing payout instrument) and marks it
 * paid. Net debt (refunds > sales in a window) is carried forward (no negative payout).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_settlements')) {
            return;
        }
        Schema::create('vendor_settlements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('settlement_run_id')->index();
            $table->unsignedBigInteger('shop_id')->index();
            $table->decimal('gross_sales', 16, 2)->default(0);
            $table->decimal('total_commission', 16, 2)->default(0);
            $table->decimal('total_platform_fee', 16, 2)->default(0);
            $table->decimal('total_pg_fee', 16, 2)->default(0);
            $table->decimal('shipping_revenue', 16, 2)->default(0);
            $table->decimal('refund_adjustments', 16, 2)->default(0); // negative
            $table->decimal('net_payable', 16, 2)->default(0);
            $table->string('status', 16)->default('pending'); // pending | paid | on_hold
            $table->unsignedBigInteger('withdraw_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['settlement_run_id', 'shop_id']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_settlements');
    }
};
