<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Earnings ledger for a delivery partner — mirrors marvel's shop `balances`
 * table. Credited when an assigned delivery completes (P4); created at zero on
 * onboarding (P1).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_partner_balances')) {
            return;
        }
        Schema::create('delivery_partner_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_partner_id')->index();
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->decimal('withdrawn_amount', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->json('payment_info')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_partner_balances');
    }
};
