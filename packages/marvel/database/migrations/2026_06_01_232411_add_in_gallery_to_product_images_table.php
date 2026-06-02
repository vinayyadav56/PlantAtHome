<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `in_gallery` to product_images.
 *
 * The library (product_images) is the source of truth for a plant's photos.
 * `in_gallery` marks which library photos are published into the product's
 * derived `gallery` column (and, for the primary, the `image` column).
 * Existing rows default to published so every already-downloaded photo shows up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->boolean('in_gallery')->default(true)->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('in_gallery');
        });
    }
};
