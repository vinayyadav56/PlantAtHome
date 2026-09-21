<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote the Size attribute to the platform's variant master.
 *
 * Small/Medium/Large were hardcoded in four console commands, a seeder and the
 * storefront filter, and the per-size delivery charge had nowhere to live at all
 * (delivery was a flat per-PRODUCT figure). Rather than stand up a parallel
 * product_variants table beside the attribute system every product already
 * references, the three facts a variant needs are added to attribute_values:
 *
 *   code            S/M/L — a stable key to look a size up by, independent of
 *                   its display name, so renaming "Large" to "XL" breaks nothing
 *   sort_order      chips render S -> M -> L instead of insertion order
 *   delivery_charge what the customer pays to have that size delivered, per unit
 *
 * Seeds the owner's figures (S 100 / M 150 / L 200) onto every Size attribute —
 * plural because the 2026-09-17 merge is best-effort and production had five.
 */
return new class extends Migration
{
    /** value (lowercased) => [code, sort_order, delivery_charge] */
    private const SIZES = [
        'small'  => ['S', 1, 100.00],
        'medium' => ['M', 2, 150.00],
        'large'  => ['L', 3, 200.00],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('attribute_values')) {
            return;
        }

        Schema::table('attribute_values', function (Blueprint $table) {
            if (!Schema::hasColumn('attribute_values', 'code')) {
                $table->string('code', 8)->nullable()->after('value');
            }
            if (!Schema::hasColumn('attribute_values', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('code');
            }
            if (!Schema::hasColumn('attribute_values', 'delivery_charge')) {
                // Per UNIT, like products.delivery_charge: a plant ships as its own parcel.
                $table->decimal('delivery_charge', 10, 2)->nullable()->after('sort_order');
            }
        });

        $this->seedSizes();
        $this->addUniqueIndexes();
    }

    public function down(): void
    {
        if (!Schema::hasTable('attribute_values')) {
            return;
        }

        foreach (['attribute_values_attribute_code_unique', 'attribute_values_attribute_value_language_unique'] as $index) {
            if ($this->hasIndex('attribute_values', $index)) {
                try {
                    Schema::table('attribute_values', function (Blueprint $table) use ($index) {
                        $table->dropUnique($index);
                    });
                } catch (\Throwable $e) {
                    // best effort, as on the way up
                }
            }
        }

        Schema::table('attribute_values', function (Blueprint $table) {
            foreach (['code', 'sort_order', 'delivery_charge'] as $column) {
                if (Schema::hasColumn('attribute_values', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Fill in the master for every Size attribute, matching on the value text
     * because that is the only thing the seeded rows and the admin-created rows
     * agree on. Only writes a row that has not been given a code already, so a
     * re-run never overwrites a charge the admin has since edited.
     */
    private function seedSizes(): void
    {
        $attributeIds = DB::table('attributes')
            ->where(function ($q) {
                $q->whereRaw('LOWER(slug) = ?', ['size'])->orWhereRaw('LOWER(name) = ?', ['size']);
            })
            ->pluck('id');

        if ($attributeIds->isEmpty()) {
            return;
        }

        foreach (self::SIZES as $value => [$code, $sortOrder, $deliveryCharge]) {
            DB::table('attribute_values')
                ->whereIn('attribute_id', $attributeIds)
                ->whereRaw('LOWER(TRIM(value)) = ?', [$value])
                ->whereNull('code')
                ->update([
                    'code'            => $code,
                    'sort_order'      => $sortOrder,
                    'delivery_charge' => $deliveryCharge,
                ]);
        }
    }

    /**
     * Both indexes are added only once the data can satisfy them — the same
     * stance as the duplicate-attribute merge: an index here is a guard against
     * the next duplicate, never something worth failing a deploy over.
     */
    private function addUniqueIndexes(): void
    {
        if (!$this->hasDuplicates(['attribute_id', 'code'], 'code')
            && !$this->hasIndex('attribute_values', 'attribute_values_attribute_code_unique')) {
            try {
                Schema::table('attribute_values', function (Blueprint $table) {
                    $table->unique(['attribute_id', 'code'], 'attribute_values_attribute_code_unique');
                });
            } catch (\Throwable $e) {
                // as above
            }
        }

        // The gap that let production grow five Sizes with duplicate values in the
        // first place: there has never been a uniqueness rule on the values themselves.
        if (!$this->hasDuplicates(['attribute_id', 'value', 'language'])
            && !$this->hasIndex('attribute_values', 'attribute_values_attribute_value_language_unique')) {
            try {
                Schema::table('attribute_values', function (Blueprint $table) {
                    $table->unique(['attribute_id', 'value', 'language'], 'attribute_values_attribute_value_language_unique');
                });
            } catch (\Throwable $e) {
                // as above
            }
        }
    }

    /**
     * @param  string[]     $columns
     * @param  string|null  $notNull  column that must be non-null to count (NULLs never collide)
     */
    private function hasDuplicates(array $columns, ?string $notNull = null): bool
    {
        try {
            foreach ($columns as $column) {
                if (!Schema::hasColumn('attribute_values', $column)) {
                    return true; // cannot index what is not there
                }
            }
            $query = DB::table('attribute_values')->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1');
            if ($notNull) {
                $query->whereNotNull($notNull);
            }

            return $query->exists();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Schema::hasIndex() only arrived in Laravel 11 and this is 10.x, so ask the
     * driver directly rather than letting a duplicate-index exception be the
     * control flow. (Lifted from 2026_09_17_100000_merge_duplicate_attributes.)
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
            // Unknown driver or a locked-down information schema: report "present"
            // so the migration skips the DDL rather than throwing at it blindly.
            return true;
        }
    }
};
