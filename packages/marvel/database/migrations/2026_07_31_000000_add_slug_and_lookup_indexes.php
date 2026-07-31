<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slug and lookup indexes that the query patterns need and the schema lacked.
 *
 * Verified absent by reading the LIVE schema (information_schema.STATISTICS),
 * not the migration history — several earlier index migrations swallow their
 * exceptions, so a green migration does not prove an index exists.
 *
 * Measured motivation (local benchmark, 1,570 products / 193 categories):
 *   - `categories.slug` had NO index. EXPLAIN showed type=ALL over 193 rows,
 *     and the accessor fires once per row, so GET /api/categories?parent=null
 *     performed 100 full scans in a single request (~19,300 row reads).
 *   - `types.slug` had NO index at all — the table has only a PRIMARY key —
 *     yet it is filtered inside correlated whereHas() subqueries on every
 *     vertical-filtered product and category request.
 *   - tags/attributes/attribute_values/shops slugs are all declared searchable
 *     in ProductRepository::$fieldSearchable and were likewise unindexed.
 *   - reviews(product_id, rating) backs the rating_count accessor's GROUP BY.
 *   - availabilities(product_id, bookable_type) backs the blocked_dates
 *     accessor. The `to` column is deliberately NOT included: the query wraps
 *     it in date(), which is non-sargable, so an index on it cannot be used.
 *     Making that query sargable is a code change, tracked separately.
 *
 * Unlike the earlier index migrations this does NOT swallow errors — a failure
 * here should be visible. Existence is checked first so it stays re-runnable.
 */
return new class extends Migration
{
    /** table => [indexName => columns] */
    private array $indexes = [
        'categories'       => ['categories_slug_index'        => ['slug']],
        'types'            => ['types_slug_index'             => ['slug']],
        'tags'             => ['tags_slug_index'              => ['slug']],
        'attributes'       => ['attributes_slug_index'        => ['slug']],
        'attribute_values' => ['attribute_values_slug_index'  => ['slug']],
        'shops'            => ['shops_slug_index'             => ['slug']],
        'reviews'          => ['reviews_product_rating_index' => ['product_id', 'rating']],
        'availabilities'   => ['availabilities_product_bookable_index' => ['product_id', 'bookable_type']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($defs as $name => $columns) {
                // Skip if the columns don't exist (schema drift between installs)
                // or the index is already there under this name.
                if (array_filter($columns, fn ($c) => !Schema::hasColumn($table, $c))) {
                    continue;
                }
                if ($this->indexExists($table, $name)) {
                    continue;
                }

                Schema::table($table, function ($t) use ($name, $columns) {
                    $t->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($defs) as $name) {
                if (!$this->indexExists($table, $name)) {
                    continue;
                }

                Schema::table($table, function ($t) use ($name) {
                    $t->dropIndex($name);
                });
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        );
    }
};
