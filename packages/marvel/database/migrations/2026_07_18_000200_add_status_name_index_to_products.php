<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composite (status, name) index so the vendor catalogue browse
 * (WHERE status = 'publish' ORDER BY name LIMIT/OFFSET) is served in name order
 * straight from the index — no filesort over the whole ~1,600-product catalogue,
 * which was costing ~2s per page. Guarded (skips if present or on any length/
 * row-format edge; the query still works, just slower).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        try {
            $exists = collect(DB::select(
                "SHOW INDEX FROM products WHERE Key_name = 'products_status_name_idx'"
            ))->isNotEmpty();
            if (! $exists) {
                DB::statement('ALTER TABLE products ADD INDEX products_status_name_idx (status, name)');
            }
        } catch (\Throwable $e) {
            // Non-fatal — leave the catalogue query on its existing (slower) plan.
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE products DROP INDEX products_status_name_idx');
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
