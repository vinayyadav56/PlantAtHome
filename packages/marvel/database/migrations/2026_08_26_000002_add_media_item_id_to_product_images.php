<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge column: product_images stays the serving cache while media_items
 * becomes the source of identity. Nullable — legacy rows adopt lazily via
 * media:adopt-existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $t) {
            $t->unsignedBigInteger('media_item_id')->nullable()->after('product_id');
            $t->foreign('media_item_id')->references('id')->on('media_items')->nullOnDelete();
            $t->index('media_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $t) {
            $t->dropForeign(['media_item_id']);
            $t->dropIndex(['media_item_id']);
            $t->dropColumn('media_item_id');
        });
    }
};
