<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart + checkout (Section 4.8 & 8). Cart items store full config + price
 * SNAPSHOTS at add time (never recomputed live from the cart). The checkout
 * session carries the state machine and links to its inventory reservation +
 * payment intent. Prefixed sales_* (legacy marvel owns orders/order_items/etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_carts')) {
            Schema::create('sales_carts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->uuid('customer_uuid')->nullable()->index(); // null = guest
                $table->string('city')->nullable();
                $table->string('status')->default('open');          // open | checked_out | abandoned
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_cart_items')) {
            Schema::create('sales_cart_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('cart_id');
                $table->uuid('variant_uuid');
                $table->uuid('nursery_id');
                $table->json('config_snapshot')->nullable();        // chosen options (frozen)
                $table->json('price_snapshot')->nullable();         // full price breakdown (frozen)
                $table->unsignedInteger('qty')->default(1);
                $table->timestamps();

                $table->foreign('cart_id')->references('id')->on('sales_carts')->cascadeOnDelete();
                $table->index('cart_id');
            });
        }

        if (! Schema::hasTable('sales_checkout_sessions')) {
            Schema::create('sales_checkout_sessions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('cart_id');
                $table->uuid('customer_uuid')->nullable();
                $table->string('status')->default('initiated');     // state machine
                $table->json('address_snapshot')->nullable();
                $table->json('totals')->nullable();
                $table->uuid('inventory_session')->nullable();       // Inventory reservation session
                $table->timestamps();

                $table->foreign('cart_id')->references('id')->on('sales_carts')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('sales_payment_intents')) {
            Schema::create('sales_payment_intents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('checkout_id');
                $table->decimal('amount', 12, 2)->default(0);
                $table->char('currency', 3)->default('INR');
                $table->string('status')->default('created');       // created|processing|succeeded|failed|refunded|partially_refunded
                $table->string('idempotency_key')->nullable()->unique();
                $table->string('provider_ref')->nullable();
                $table->timestamps();

                $table->foreign('checkout_id')->references('id')->on('sales_checkout_sessions')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_payment_intents');
        Schema::dropIfExists('sales_checkout_sessions');
        Schema::dropIfExists('sales_cart_items');
        Schema::dropIfExists('sales_carts');
    }
};
