<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute definitions + values (Section 4.3, EAV-lite). An attribute is
 * either universal (applies to any product) or scoped to a category. Values are
 * stored per product as typed JSON; the attribute's `type` governs validation
 * and rendering. Unique per (attribute, product).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_attributes')) {
            Schema::create('catalog_attributes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('code')->unique();          // e.g. light_level
                $table->string('name');
                $table->string('type');                    // text | number | enum | bool
                $table->string('scope')->default('universal'); // universal | category
                $table->unsignedBigInteger('category_id')->nullable(); // set when scope=category
                $table->json('options')->nullable();       // allowed values for enum
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('category_id')->references('id')->on('catalog_categories')->nullOnDelete();
                $table->index('scope');
            });
        }

        if (! Schema::hasTable('catalog_attribute_values')) {
            Schema::create('catalog_attribute_values', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('attribute_id');
                $table->unsignedBigInteger('product_id');
                $table->json('value');                     // typed by attribute.type
                $table->timestamps();

                $table->foreign('attribute_id')->references('id')->on('catalog_attributes')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('catalog_products')->cascadeOnDelete();
                $table->unique(['attribute_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_attribute_values');
        Schema::dropIfExists('catalog_attributes');
    }
};
