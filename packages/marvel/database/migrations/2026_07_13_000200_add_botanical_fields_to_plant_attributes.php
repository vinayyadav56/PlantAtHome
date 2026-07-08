<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the master-catalog botanical schema. Additive + guarded. Text columns are
 * translation-engine-overlay compatible (the overlay translates at the Eloquent
 * getAttributeValue layer), so multilingual support needs no further schema change.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('plant_attributes')) {
            Schema::table('plant_attributes', function (Blueprint $table) {
                $add = [
                    'regional_names'         => fn () => $table->json('regional_names')->nullable(),
                    'is_flowering'           => fn () => $table->boolean('is_flowering')->nullable(),
                    'fruit_bearing'          => fn () => $table->boolean('fruit_bearing')->nullable(),
                    'season'                 => fn () => $table->string('season', 100)->nullable(),
                    'life_cycle'             => fn () => $table->string('life_cycle', 50)->nullable(), // annual/biennial/perennial
                    'width_range'            => fn () => $table->string('width_range', 100)->nullable(),
                    'humidity'               => fn () => $table->string('humidity', 100)->nullable(),
                    'soil_type'              => fn () => $table->string('soil_type', 150)->nullable(),
                    'fertilizer_requirement' => fn () => $table->string('fertilizer_requirement', 150)->nullable(),
                    'difficulty_level'       => fn () => $table->string('difficulty_level', 50)->nullable(), // easy/moderate/hard
                    'care_guide'             => fn () => $table->text('care_guide')->nullable(),
                    'planting_guide'         => fn () => $table->text('planting_guide')->nullable(),
                    'faqs'                   => fn () => $table->json('faqs')->nullable(),
                ];
                foreach ($add as $col => $make) {
                    if (!Schema::hasColumn('plant_attributes', $col)) {
                        $make();
                    }
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'seo_title')) {
                    $table->string('seo_title', 255)->nullable();
                }
                if (!Schema::hasColumn('products', 'seo_description')) {
                    $table->string('seo_description', 500)->nullable();
                }
                if (!Schema::hasColumn('products', 'barcode')) {
                    $table->string('barcode', 191)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plant_attributes')) {
            Schema::table('plant_attributes', function (Blueprint $table) {
                foreach (['regional_names', 'is_flowering', 'fruit_bearing', 'season', 'life_cycle', 'width_range', 'humidity', 'soil_type', 'fertilizer_requirement', 'difficulty_level', 'care_guide', 'planting_guide', 'faqs'] as $col) {
                    if (Schema::hasColumn('plant_attributes', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                foreach (['seo_title', 'seo_description', 'barcode'] as $col) {
                    if (Schema::hasColumn('products', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
