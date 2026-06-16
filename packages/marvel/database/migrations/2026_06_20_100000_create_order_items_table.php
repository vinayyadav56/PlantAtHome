<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P4 — the per-line model for a SINGLE customer order. Each row is
 * one cart line on the parent `orders` row, carrying its own per-ITEM vendor assignment
 * (assigned_shop_id + the exact vendor_product_price_id) and shipment grouping. Written
 * in PARALLEL with the legacy per-vertical child orders (dual-write) during rollout; the
 * customer-facing cutover (hide sub-orders) flips later, behind a flag, after reconciliation.
 * Fully additive — nothing reads this yet.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('order_items')) {
            return;
        }
        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');                 // the parent (customer) order
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_option_id')->nullable();
            $table->integer('order_quantity')->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);       // customer-facing selling price (snapshot)
            $table->decimal('subtotal', 14, 2)->default(0);
            // per-item vendor assignment (null until assigned)
            $table->unsignedBigInteger('assigned_shop_id')->nullable();
            $table->unsignedBigInteger('vendor_product_price_id')->nullable(); // the exact vendor row reserved against
            $table->integer('reserved_qty')->default(0);
            $table->string('fulfillment_mode', 16)->nullable();     // local | courier
            $table->unsignedSmallInteger('eta_days')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->decimal('vendor_price_snapshot', 14, 2)->nullable();
            $table->decimal('vendor_cost_snapshot', 14, 2)->nullable();
            $table->string('item_status', 32)->default('pending');        // pending|assigned|packed|shipped|delivered|cancelled|refunded
            $table->string('assignment_status', 16)->default('unassigned'); // unassigned|suggested|approved|overridden
            $table->timestamps();

            $table->index('order_id');
            $table->index(['assigned_shop_id', 'item_status']);
            $table->index(['product_id', 'variation_option_id']);
            $table->index('shipment_id');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
