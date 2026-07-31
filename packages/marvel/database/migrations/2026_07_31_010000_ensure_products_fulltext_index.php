<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantee the products FULLTEXT index that catalogue search uses.
 *
 * An earlier migration (2026_07_13_000300) creates this index, but like every
 * index migration in this repo it wraps itself in try/catch and swallows the
 * failure — so a green migration history is not evidence the index exists.
 *
 * It was in fact ABSENT on staging. The first version of the FULLTEXT search
 * assumed it, and `MATCH … AGAINST` against a missing index is a hard SQL error
 * rather than an empty result: catalogue search returned HTTP 500. The
 * controller now checks the schema and falls back to LIKE, so search cannot
 * break again either way; this migration exists so the fast path is actually
 * available rather than silently degraded forever.
 *
 * This is a SEPARATE file on purpose. Folding it into 2026_07_31_000000 would
 * have done nothing, because that migration had already run on staging and
 * Laravel will not re-run a recorded migration.
 *
 * Deliberately does NOT swallow exceptions — that habit is what hid the problem.
 * Existence is checked first so it stays safely re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach (['name', 'sku'] as $column) {
            if (! Schema::hasColumn('products', $column)) {
                return; // schema drift — nothing sensible to index
            }
        }

        if ($this->indexExists()) {
            return;
        }

        // Building a FULLTEXT index rebuilds the table on older MySQL. On a large
        // products table prefer a maintenance window; on InnoDB 8.0 this is an
        // in-place ALTER and cheap.
        DB::statement('ALTER TABLE `products` ADD FULLTEXT `products_fulltext` (`name`, `sku`)');
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && $this->indexExists()) {
            DB::statement('ALTER TABLE `products` DROP INDEX `products_fulltext`');
        }
    }

    private function indexExists(): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND INDEX_NAME = ? LIMIT 1',
            ['products', 'products_fulltext']
        );
    }
};
