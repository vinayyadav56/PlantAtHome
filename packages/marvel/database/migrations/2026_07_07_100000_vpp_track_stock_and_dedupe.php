<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor inventory production-readiness:
 *  - `track_stock` — a vendor who sets a stock number is now managing stock, so 0 means OUT OF
 *    STOCK (previously stock_qty<=0 meant "untracked / infinite", so a vendor could never mark a
 *    listing sold-out). Default false keeps every existing row untracked (unchanged behaviour).
 *  - `dedupe_key` + UNIQUE index — blocks duplicate vendor listings for the same
 *    (shop, product, variant, period, effective_from). A plain unique index can't do this because
 *    MySQL treats each NULL variant / effective_from as distinct; the model maintains dedupe_key
 *    (null for soft-deleted rows, so the same mapping can be recreated later).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }

        Schema::table('vendor_product_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_product_prices', 'track_stock')) {
                $table->boolean('track_stock')->default(false)->after('reserved_qty');
            }
            if (!Schema::hasColumn('vendor_product_prices', 'dedupe_key')) {
                $table->string('dedupe_key', 191)->nullable()->after('track_stock');
            }
        });

        // Backfill + collapse existing duplicates before the unique index goes on (MySQL only;
        // the sqlite test harness starts empty so it just gets the columns + index).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                UPDATE vendor_product_prices
                SET dedupe_key = CONCAT_WS('|',
                    shop_id,
                    product_id,
                    COALESCE(variation_option_id, 0),
                    COALESCE(period_type, ''),
                    COALESCE(DATE_FORMAT(effective_from, '%Y-%m-%d'), '0')
                )
                WHERE deleted_at IS NULL
            ");
            // Keep the newest row per key; soft-delete the rest and free their key.
            DB::statement("
                UPDATE vendor_product_prices v
                JOIN (
                    SELECT dedupe_key, MAX(id) AS keep_id
                    FROM vendor_product_prices
                    WHERE deleted_at IS NULL AND dedupe_key IS NOT NULL
                    GROUP BY dedupe_key
                    HAVING COUNT(*) > 1
                ) d ON v.dedupe_key = d.dedupe_key AND v.id <> d.keep_id AND v.deleted_at IS NULL
                SET v.deleted_at = NOW(), v.dedupe_key = NULL
            ");
        }

        // Add the unique index (idempotent — ignore if it already exists).
        try {
            Schema::table('vendor_product_prices', function (Blueprint $table) {
                $table->unique('dedupe_key', 'vpp_dedupe_key_unique');
            });
        } catch (\Throwable $e) {
            // index already present
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('vendor_product_prices')) {
            return;
        }
        try {
            Schema::table('vendor_product_prices', function (Blueprint $table) {
                $table->dropUnique('vpp_dedupe_key_unique');
            });
        } catch (\Throwable $e) {
            //
        }
        Schema::table('vendor_product_prices', function (Blueprint $table) {
            foreach (['dedupe_key', 'track_stock'] as $col) {
                if (Schema::hasColumn('vendor_product_prices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
