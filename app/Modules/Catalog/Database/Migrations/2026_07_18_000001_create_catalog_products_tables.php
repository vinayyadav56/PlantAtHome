<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products + media (Section 4.3). A product is the plant identity — owned by
 * admin. **No nursery_id**: this is the backbone of the "zero product
 * duplication" contract (Section 3). Commerce facts (price, stock, availability)
 * live in other contexts keyed by SKU, never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_products')) {
            Schema::create('catalog_products', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('botanical_name')->nullable();
                $table->string('hindi_name')->nullable();
                $table->text('description')->nullable();
                $table->json('care')->nullable();               // light/water/etc. care_json
                $table->string('status')->default('draft')->index(); // draft | published | archived
                $table->timestamp('published_at')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // RESTRICT: don't let a category with products be hard-deleted.
                $table->foreign('category_id')->references('id')->on('catalog_categories')->restrictOnDelete();
                $table->index(['status', 'category_id']);
            });
        }

        if (! Schema::hasTable('catalog_product_media')) {
            Schema::create('catalog_product_media', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('product_id');
                $table->string('url');                          // S3 key / CDN url
                $table->string('type')->default('image');       // image | video
                $table->string('alt')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('catalog_products')->cascadeOnDelete();
                $table->index(['product_id', 'sort']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_media');
        Schema::dropIfExists('catalog_products');
    }
};
