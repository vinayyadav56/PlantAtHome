<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_inclusions — a product can *include* other products:
 *  - relation 'bundle' : items that make up a bundle/package (parent = bundle)
 *  - relation 'addon'  : suggested buy-together items, e.g. pots (parent = plant)
 * One pivot powers both the bundle and the buy-together features.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_inclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('child_id');
            $table->enum('relation', ['bundle', 'addon'])->default('bundle');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('child_id')->references('id')->on('products')->onDelete('cascade');
            $table->index(['parent_id', 'relation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_inclusions');
    }
};
