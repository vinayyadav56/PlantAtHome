<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product variants (Section 4.3). A variant is a sellable size of the plant
 * (Small/Medium/Large) — THE SKU that inventory (Phase 5) and pricing (Phase 6)
 * hang off. The variant owns no price or stock itself; those live in other
 * contexts keyed by this sku.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_product_variants')) {
            return;
        }

        Schema::create('catalog_product_variants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('product_id');
            $table->string('sku')->unique();
            $table->string('size_code')->nullable();        // S | M | L | ...
            $table->string('name')->nullable();             // display label
            $table->unsignedInteger('weight_grams')->nullable();
            $table->json('dimensions')->nullable();         // {l,b,h} cm
            $table->string('status')->default('active');    // active | inactive
            $table->unsignedInteger('sort')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('catalog_products')->cascadeOnDelete();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_variants');
    }
};
