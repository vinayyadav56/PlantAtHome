<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-extensible plant attributes: definitions (typed) → terms (values) → product
 * assignments. This is the DISCOVERY layer (facets/filters/collections). It deliberately
 * does not replace: the legacy `attributes` tables (variation picker axis) or the
 * `plant_attributes` wide table (the six live botanical facets — canonical there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_attribute_definitions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->unique();
            // single | multi | boolean | number | text | range
            $t->string('type', 20)->default('multi');
            $t->boolean('is_facet')->default(true);
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('plant_attribute_terms', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('definition_id')->index();
            $t->string('value');
            $t->string('slug');
            $t->integer('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['definition_id', 'slug']);
        });

        Schema::create('plant_attribute_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('definition_id')->index();
            $t->unsignedBigInteger('term_id')->nullable()->index();
            $t->unsignedBigInteger('product_id')->index();
            // For boolean/number/text/range definitions (no term row)
            $t->string('value_text')->nullable();
            $t->timestamps();
            $t->unique(['definition_id', 'term_id', 'product_id'], 'pap_def_term_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_attribute_product');
        Schema::dropIfExists('plant_attribute_terms');
        Schema::dropIfExists('plant_attribute_definitions');
    }
};
