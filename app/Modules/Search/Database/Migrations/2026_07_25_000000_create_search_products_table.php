<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search read model (Section 12). A denormalized projection of products —
 * rebuilt from domain events, NEVER a source of truth. This table is both the
 * MySQL fallback search index AND the shape mirrored into Elasticsearch when it
 * is available. Prefixed search_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('search_products')) {
            return;
        }

        Schema::create('search_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('product_uuid')->unique();
            $table->string('name');
            $table->string('slug');
            $table->uuid('category_uuid')->nullable()->index();
            $table->string('category_name')->nullable();
            $table->json('attributes')->nullable();          // code => value
            $table->text('keywords')->nullable();            // name + category + attr values (fulltext)
            $table->decimal('price_min', 12, 2)->nullable();
            $table->decimal('price_max', 12, 2)->nullable();
            $table->string('status')->default('published')->index();
            $table->timestamps();

            $table->index('name');
        });

        // Fulltext index for relevance ranking + typo-tolerant search (MySQL).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE search_products ADD FULLTEXT search_products_ft (name, keywords)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_products');
    }
};
