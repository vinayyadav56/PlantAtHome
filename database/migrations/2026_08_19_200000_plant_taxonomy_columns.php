<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomy pass: SEO + ordering + authorship on categories; master↔variety linkage and
 * merchandising flags on products; common names on the botanical record; structured size
 * facts on variants. All additive — nothing existing changes shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            if (!Schema::hasColumn('categories', 'seo_title')) {
                $t->string('seo_title')->nullable();
                $t->text('seo_description')->nullable();
                $t->integer('display_order')->default(0);
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
            }
        });

        Schema::table('products', function (Blueprint $t) {
            if (!Schema::hasColumn('products', 'master_product_id')) {
                // A variety IS a product (own slug/PDP/sizes/inventory) pointing at its master
                // plant. No FK constraint on purpose: masters are soft-deletable and a variety
                // must survive its master being archived.
                $t->unsignedBigInteger('master_product_id')->nullable()->index();
                $t->string('variety_name')->nullable();
                $t->boolean('is_featured')->default(false)->index();
                $t->boolean('is_premium')->default(false);
            }
        });

        Schema::table('plant_attributes', function (Blueprint $t) {
            if (!Schema::hasColumn('plant_attributes', 'common_names')) {
                $t->json('common_names')->nullable();
            }
        });

        Schema::table('variation_options', function (Blueprint $t) {
            if (!Schema::hasColumn('variation_options', 'details')) {
                // {height_cm, pot_size, pot_included, weight_g, dimensions} — display + logistics
                // facts for a size. Kept off `options` (the picker-matching contract).
                $t->json('details')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['seo_title', 'seo_description', 'display_order', 'created_by_user_id', 'updated_by_user_id']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['master_product_id', 'variety_name', 'is_featured', 'is_premium']));
        Schema::table('plant_attributes', fn (Blueprint $t) => $t->dropColumn('common_names'));
        Schema::table('variation_options', fn (Blueprint $t) => $t->dropColumn('details'));
    }
};
