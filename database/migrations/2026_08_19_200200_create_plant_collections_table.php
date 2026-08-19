<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic collections: a named, admin-managed rule set that resolves through the EXISTING
 * product-listing engine ("Air Purifying Plants" = the filter params, not a duplicated
 * product list). rules json = URL-param map fed straight to the storefront listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_collections', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->json('image')->nullable();
            $t->json('rules');
            $t->string('seo_title')->nullable();
            $t->text('seo_description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_collections');
    }
};
