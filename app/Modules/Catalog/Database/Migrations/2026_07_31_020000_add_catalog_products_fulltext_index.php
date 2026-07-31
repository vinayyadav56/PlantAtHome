<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FULLTEXT index for v1 catalogue search on `catalog_products`.
 *
 * Search ran `name LIKE '%term%'`, which cannot use an index and scans the
 * table. The obvious fix — reuse the existing `products_fulltext` index — does
 * not apply here: that index is on the marvel `products` table, whereas this
 * module's model is `catalog_products`, a different table which has no `sku`
 * column at all. Matching MATCH(name, sku) against it was a hard SQL error and
 * returned HTTP 500 on staging.
 *
 * Indexes (name, botanical_name): the two searchable text columns this table
 * actually has. The controller MATCHes exactly this column list, because MySQL
 * requires a FULLTEXT index covering precisely the columns in the MATCH.
 *
 * No try/catch. Every other index migration in this repo swallows its failures,
 * which is why a missing index went unnoticed until it caused a 500 — a failure
 * here should be loud. Existence-checked so it stays re-runnable.
 */
return new class extends Migration
{
    private const TABLE = 'catalog_products';
    private const INDEX = 'catalog_products_fulltext';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['name', 'botanical_name'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return; // schema drift — nothing sensible to index
            }
        }

        if ($this->indexExists()) {
            return;
        }

        DB::statement('ALTER TABLE `'.self::TABLE.'` ADD FULLTEXT `'.self::INDEX.'` (`name`, `botanical_name`)');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && $this->indexExists()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` DROP INDEX `'.self::INDEX.'`');
        }
    }

    private function indexExists(): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABLE, self::INDEX]
        );
    }
};
