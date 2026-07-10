<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product proposals (Section 3/4.3). A nursery that wants a new plant in the
 * master catalog files a proposal — it never creates a product row. Admin
 * reviews and, on approval, materializes a real product. nursery_id is the
 * opaque vendor scope (no cross-module FK); proposed_by is the identity user
 * uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_product_proposals')) {
            return;
        }

        Schema::create('catalog_product_proposals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->uuid('nursery_id')->index();
            $table->uuid('proposed_by')->nullable();
            $table->json('payload');                        // suggested name/botanical/category/etc.
            $table->string('status')->default('pending')->index(); // pending | approved | rejected
            $table->text('review_note')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('product_id')->nullable(); // set when approved → materialized
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('catalog_products')->nullOnDelete();
            $table->index(['nursery_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_proposals');
    }
};
