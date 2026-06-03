<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of each plant product's ORIGINAL pricing before the one-off prod
 * size-pricing conversion (simple → variable). Lets the conversion be fully
 * reversed (plantathome:size-price-prod --rollback) on the live store.
 * One row per product, written once (the true original).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pah_plant_price_backup')) {
            return;
        }
        Schema::create('pah_plant_price_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->string('product_type')->nullable();
            $table->double('price')->nullable();
            $table->double('sale_price')->nullable();
            $table->double('min_price')->nullable();
            $table->double('max_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('sku')->nullable();
            $table->timestamp('backed_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pah_plant_price_backup');
    }
};
