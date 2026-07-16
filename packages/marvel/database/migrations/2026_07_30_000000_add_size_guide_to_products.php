<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product size guide image (Pickbazar attachment json {id, original, thumbnail}).
 * Uploaded on the admin product form, shown as a "Size Guide" section on the
 * storefront + app product page. Mirrors the existing `image` json column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'size_guide')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('size_guide')->nullable()->after('image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'size_guide')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('size_guide');
            });
        }
    }
};
