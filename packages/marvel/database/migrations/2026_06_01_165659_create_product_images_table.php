<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated gallery table — one row per product image, ordered and manageable
 * from the admin. The image FILES live on S3; this stores their URLs + order.
 * Replaces reliance on the products.gallery JSON blob (which is kept in sync
 * for backward compatibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();

            $table->text('url');                         // full S3/CDN URL
            $table->text('thumbnail_url')->nullable();
            $table->string('alt', 255)->nullable();
            $table->integer('sort_order')->default(0);   // 0 = first
            $table->boolean('is_primary')->default(false);
            $table->string('source', 30)->nullable();    // inaturalist|wikimedia|pixabay|ai|manual
            $table->string('attribution', 500)->nullable(); // license/credit for CC images
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
