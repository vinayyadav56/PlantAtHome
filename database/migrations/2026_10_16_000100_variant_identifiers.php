<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Product variants (2026-10-15): identifiers.
 *
 * - A blank variant SKU is NULL, never '': NULL means "generate one", and the API fills it as
 *   {product SKU}-{suffix} on the next save (or plantathome:generate-skus). Safe only now that the
 *   Variation sku accessor, which threw on NULL, is gone (deploys migrate after the code serves).
 * - variation_options.sku becomes UNIQUE, but only when the data already allows it: duplicates are
 *   logged and the index skipped, never "fixed" here (the API refuses new duplicates either way).
 * - gtin (GS1 barcode number, up to 14 digits) and mpn (manufacturer part number) on products and
 *   on variants. No index yet: nothing looks a product up by them.
 */
return new class extends Migration {
    private const INDEX = 'variation_options_sku_unique';

    public function up(): void
    {
        if (Schema::hasTable('variation_options') && Schema::hasColumn('variation_options', 'sku')) {
            DB::table('variation_options')->whereRaw("TRIM(sku) = ''")->update(['sku' => null]);
            $this->addSkuIndex();
        }

        foreach (['products', 'variation_options'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'gtin')) {
                    $t->string('gtin', 14)->nullable();
                }
                if (!Schema::hasColumn($table, 'mpn')) {
                    $t->string('mpn', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('variation_options') && $this->hasIndex('variation_options', self::INDEX)) {
            Schema::table('variation_options', fn (Blueprint $t) => $t->dropUnique(self::INDEX));
        }
        foreach (['products', 'variation_options'] as $table) {
            foreach (['gtin', 'mpn'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
        // '' -> NULL is not undone: both mean "no SKU".
    }

    private function addSkuIndex(): void
    {
        $duplicates = DB::table('variation_options')
            ->whereNotNull('sku')
            ->select('sku')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->pluck('sku')
            ->all();
        if ($duplicates) {
            Log::warning('variant_identifiers: variation_options.sku has duplicates, so ' . self::INDEX . ' was not added (the API still refuses new duplicates).', ['skus' => $duplicates]);

            return;
        }
        if ($this->hasIndex('variation_options', self::INDEX)) {
            return;
        }
        try {
            Schema::table('variation_options', fn (Blueprint $t) => $t->unique('sku', self::INDEX));
        } catch (\Throwable $e) {
            // The index is a guard, not the fix (the API checks SKUs): never fail the deploy on it.
            Log::warning('variant_identifiers: could not add ' . self::INDEX . ': ' . $e->getMessage());
        }
    }

    /**
     * Schema::hasIndex() only arrived in Laravel 11 and this is 10.x, so ask the driver directly
     * (as 2026_09_17_100000_merge_duplicate_attributes does; sqlite-safe, unlike SHOW INDEX).
     */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $driver = $connection->getDriverName();

            if ($driver === 'sqlite') {
                foreach ($connection->select("PRAGMA index_list(\"{$table}\")") as $row) {
                    if (($row->name ?? null) === $index) {
                        return true;
                    }
                }
                return false;
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                return (bool) $connection->selectOne(
                    'SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                    [$table, $index]
                );
            }

            return (bool) $connection->selectOne(
                'SELECT 1 AS found FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                [$table, $index]
            );
        } catch (\Throwable $e) {
            // Unknown driver or a locked-down information schema: report "present" so the
            // migration skips the DDL rather than throwing at it blindly.
            return true;
        }
    }
};
