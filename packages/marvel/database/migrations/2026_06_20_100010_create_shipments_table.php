<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P4 — a fulfilment unit grouping order_items by vendor + mode (one
 * pickup / one courier). Internal only: the customer sees ONE order and a single timeline,
 * never a "shipment 2 of 3". Reuses the existing delivery-partner + delivery-mode concepts
 * from the orders table. Additive.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('shipments')) {
            return;
        }
        Schema::create('shipments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');                 // the parent (customer) order
            $table->unsignedBigInteger('shop_id');                  // the assigned vendor
            $table->unsignedBigInteger('delivery_partner_id')->nullable();
            $table->string('delivery_mode', 24)->nullable();        // vendor_dp|separate_dp|courier_admin|courier_dp
            $table->string('fulfillment_mode', 16)->nullable();     // local | courier
            $table->string('status', 32)->default('pending');       // pending|assigned|packed|shipped|out_for_delivery|delivered|cancelled
            $table->decimal('shipping_cost', 14, 2)->nullable();    // platform's courier/DP cost
            $table->decimal('shipping_revenue', 14, 2)->nullable(); // customer-charged delivery share
            $table->unsignedSmallInteger('eta_days')->nullable();
            $table->date('expected_delivery_at')->nullable();
            $table->string('tracking_ref', 64)->nullable();         // internal; NOT a customer-facing order number
            $table->timestamps();

            $table->index('order_id');
            $table->index(['shop_id', 'status']);
            $table->index('delivery_partner_id');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
