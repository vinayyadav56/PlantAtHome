<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products.slug becomes UNIQUE.
 *
 * The slug is the storefront's product identity — every card links by it and the PDP resolves by
 * it. It only had a plain index; uniqueness was enforced by nothing but globalSlugify's collision
 * suffixing at write time. Verified before adding: 0 duplicated slugs across 4,396 products, and
 * the generator already suffixes on collision, so this constraint can never bite a legitimate
 * write — it exists to make a future import bug loud instead of quietly shipping two products
 * that answer to one URL.
 *
 * Guarded: if an environment somehow holds duplicates, the migration logs and skips rather than
 * bricking the deploy — a missing safety index is recoverable, a failed migration chain is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }
        $dupes = DB::select('SELECT slug FROM products GROUP BY slug HAVING COUNT(*) > 1 LIMIT 1');
        if (!empty($dupes)) {
            \Illuminate\Support\Facades\Log::warning('products.slug unique index SKIPPED — duplicates exist', [
                'first' => $dupes[0]->slug,
            ]);
            return;
        }
        // MySQL names checked live: no unique on slug exists, only products_slug_index (plain).
        if (DB::getDriverName() === 'mysql') {
            $exists = DB::select("SHOW INDEXES FROM products WHERE Key_name = 'products_slug_unique'");
            if (empty($exists)) {
                DB::statement('ALTER TABLE products ADD UNIQUE INDEX products_slug_unique (slug)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && DB::getDriverName() === 'mysql') {
            $exists = DB::select("SHOW INDEXES FROM products WHERE Key_name = 'products_slug_unique'");
            if (!empty($exists)) {
                DB::statement('ALTER TABLE products DROP INDEX products_slug_unique');
            }
        }
    }
};
