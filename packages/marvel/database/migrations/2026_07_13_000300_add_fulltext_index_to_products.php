<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL FULLTEXT index for scalable master-catalog search (name + sku), replacing the
 * `LIKE %term%` scan (which can't use an index). Guarded: MySQL-only + skip if the
 * index already exists. Native FULLTEXT is Scout-`database`-driver compatible.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'products')
            ->where('index_name', 'products_fulltext')
            ->exists();
        if (!$exists) {
            try {
                DB::statement('ALTER TABLE products ADD FULLTEXT products_fulltext (name, sku)');
            } catch (\Throwable $e) {
                // Non-fatal: search falls back to LIKE if the engine rejects the index.
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        try {
            DB::statement('ALTER TABLE products DROP INDEX products_fulltext');
        } catch (\Throwable $e) {
            //
        }
    }
};
