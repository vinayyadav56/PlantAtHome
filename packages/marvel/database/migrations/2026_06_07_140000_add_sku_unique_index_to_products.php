<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce unique product SKUs. NULL SKUs are allowed (MySQL permits multiple
 * NULLs in a unique index), so this is safe to run before the backfill command
 * fills them in. Guarded: skips if the index already exists or if any duplicate
 * non-null SKUs are present (so a stray duplicate can never break a deploy).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'sku')) {
            return;
        }

        $indexExists = collect(DB::select(
            "SHOW INDEX FROM products WHERE Key_name = 'products_sku_unique'"
        ))->isNotEmpty();
        if ($indexExists) {
            return;
        }

        $dupes = DB::select(
            "SELECT sku FROM products WHERE sku IS NOT NULL AND sku <> '' GROUP BY sku HAVING COUNT(*) > 1 LIMIT 1"
        );
        if (!empty($dupes)) {
            // Duplicates exist (run plantathome:generate-skus --force first). Skip
            // rather than fail the migration / deploy.
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique('sku', 'products_sku_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_sku_unique');
            });
        }
    }
};
