<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders (Section 4.8): one customer-facing PARENT order, split internally into
 * one SUB-ORDER per vendor (vendor-hidden orchestration). Order items are fully
 * frozen snapshots. Parent status is a rollup, never set directly. A status
 * history logs every transition. Prefixed sales_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->uuid('customer_uuid')->nullable()->index();
                $table->unsignedBigInteger('checkout_id')->nullable();
                $table->json('address_snapshot')->nullable();
                $table->json('totals')->nullable();
                $table->string('status')->default('pending');       // derived rollup
                $table->decimal('delivery_fee', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_sub_orders')) {
            Schema::create('sales_sub_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('parent_order_id');
                $table->uuid('nursery_id')->index();
                $table->string('status')->default('placed');        // sub-order lifecycle
                $table->string('fulfillment_type')->nullable();
                $table->json('totals')->nullable();
                $table->timestamps();

                $table->foreign('parent_order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
                $table->index(['parent_order_id', 'nursery_id']);
            });
        }

        if (! Schema::hasTable('sales_order_items')) {
            Schema::create('sales_order_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('sub_order_id');
                $table->uuid('variant_uuid');
                $table->json('product_snapshot')->nullable();
                $table->json('variant_snapshot')->nullable();
                $table->json('config_snapshot')->nullable();
                $table->json('price_snapshot')->nullable();
                $table->unsignedInteger('weight_grams')->nullable();
                $table->unsignedInteger('qty')->default(1);
                $table->timestamps();

                $table->foreign('sub_order_id')->references('id')->on('sales_sub_orders')->cascadeOnDelete();
                $table->index('sub_order_id');
            });
        }

        if (! Schema::hasTable('sales_status_history')) {
            Schema::create('sales_status_history', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('entity_type');                      // sub_order | payment | checkout
                $table->uuid('entity_uuid')->index();
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->uuid('actor')->nullable();
                $table->timestamp('at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_status_history');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_sub_orders');
        Schema::dropIfExists('sales_orders');
    }
};
