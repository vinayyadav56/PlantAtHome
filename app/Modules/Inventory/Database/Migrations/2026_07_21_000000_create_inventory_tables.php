<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory (Section 4.5). Polymorphic over "sellable" = a variant SKU OR an
 * option SKU (never merged). One row per SKU per vendor (single-location for
 * now; warehouse nullable). Stock is derived-verifiable from the immutable
 * movement ledger. Prefixed inv_* (legacy marvel owns `warehouses`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inv_warehouses')) {
            Schema::create('inv_warehouses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->uuid('nursery_id')->index();
                $table->string('name');
                $table->json('address')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inv_items')) {
            Schema::create('inv_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('sellable_type');                 // variant | option
                $table->uuid('sellable_uuid');                   // the variant/option uuid
                $table->uuid('nursery_id');
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->unsignedInteger('qty_on_hand')->default(0);
                $table->unsignedInteger('qty_reserved')->default(0);
                $table->boolean('track')->default(true);         // false = infinite stock
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('inv_warehouses')->nullOnDelete();
                // One inventory row per SKU per vendor (single-location).
                $table->unique(['sellable_type', 'sellable_uuid', 'nursery_id'], 'inv_items_sku_nursery_unique');
                $table->index(['sellable_type', 'sellable_uuid']);
            });
        }

        if (! Schema::hasTable('inv_reservations')) {
            Schema::create('inv_reservations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('inventory_item_id');
                $table->uuid('checkout_session_id')->index();
                $table->unsignedInteger('qty');
                $table->timestamp('expires_at');
                $table->string('status')->default('active');     // active | committed | released | expired
                $table->timestamps();

                $table->foreign('inventory_item_id')->references('id')->on('inv_items')->cascadeOnDelete();
                $table->index(['status', 'expires_at']);
            });
        }

        if (! Schema::hasTable('inv_movements')) {
            Schema::create('inv_movements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('inventory_item_id');
                $table->integer('delta');                        // signed: +restock / -sale
                $table->string('reason');                        // adjustment | sale | release | restock
                $table->string('ref_type')->nullable();
                $table->uuid('ref_id')->nullable();
                $table->timestamps();

                $table->foreign('inventory_item_id')->references('id')->on('inv_items')->cascadeOnDelete();
                $table->index('inventory_item_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_movements');
        Schema::dropIfExists('inv_reservations');
        Schema::dropIfExists('inv_items');
        Schema::dropIfExists('inv_warehouses');
    }
};
