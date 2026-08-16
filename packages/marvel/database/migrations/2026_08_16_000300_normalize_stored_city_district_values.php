<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\LocationNormalizer;

/**
 * Rewrite every stored city that is really a district, keeping the district.
 *
 *   {"city":"New Delhi","state":"Delhi"}  ->  {"city":"Delhi","district":"New Delhi","state":"Delhi"}
 *
 * Nothing is lost — the district moves into its own key rather than being dropped — but this does
 * edit historical order snapshots, so every prior value is copied into `location_backfill_2026_08`
 * first and `down()` restores them byte for byte.
 *
 * ⚠️ `users.city` / `users.state` are deliberately NOT touched. Despite the names they are HR
 * fields on the employee record (they sit beside `department` and `designation`), not a customer
 * location. Only the three location columns — preferred_city, last_detected_city, verified_city —
 * are normalized here.
 */
return new class extends Migration
{
    private const AUDIT = 'location_backfill_2026_08';

    public function up(): void
    {
        if (!Schema::hasTable('cities') || !Schema::hasColumn('cities', 'parent_city_id')) {
            return;
        }

        if (!Schema::hasTable(self::AUDIT)) {
            Schema::create(self::AUDIT, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('table_name', 64);
                $table->unsignedBigInteger('row_id');
                $table->string('column_name', 64);
                $table->longText('old_value')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['table_name', 'row_id']);
            });
        }

        $n = new LocationNormalizer();

        // --- canonical address JSON (saved addresses + order snapshots) -----------------------
        $this->rewriteJson($n, 'address', ['address']);
        $this->rewriteJson($n, 'orders', ['shipping_address', 'billing_address']);

        // --- plain city-name columns ----------------------------------------------------------
        $this->rewriteName($n, 'address', 'rg_city');
        $this->rewriteName($n, 'users', 'preferred_city');
        $this->rewriteName($n, 'users', 'last_detected_city');
        $this->rewriteName($n, 'users', 'verified_city');
        $this->rewriteName($n, 'carts', 'shopping_city');
        $this->rewriteName($n, 'orders', 'detected_city');
        $this->rewriteName($n, 'orders', 'serviceable_city');
        $this->rewriteName($n, 'vendor_service_areas', 'city');
        $this->rewriteName($n, 'delivery_pincodes', 'city');
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::AUDIT)) {
            return;
        }

        foreach (DB::table(self::AUDIT)->orderByDesc('id')->get() as $row) {
            if (Schema::hasTable($row->table_name) && Schema::hasColumn($row->table_name, $row->column_name)) {
                DB::table($row->table_name)
                    ->where('id', $row->row_id)
                    ->update([$row->column_name => $row->old_value]);
            }
        }

        Schema::dropIfExists(self::AUDIT);
    }

    /** Normalize the city/district inside a canonical-address JSON column. */
    private function rewriteJson(LocationNormalizer $n, string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
        if (!$columns) {
            return;
        }

        DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')->chunk(500, function ($rows) use ($n, $table, $columns) {
            foreach ($rows as $row) {
                foreach ($columns as $col) {
                    $raw = $row->$col;
                    if ($raw === null || $raw === '') {
                        continue;
                    }
                    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                    if (!is_array($decoded)) {
                        continue;
                    }

                    // Order snapshots are written both flat and wrapped as {address:{...}} —
                    // Address::missingFields tolerates both, so this has to as well.
                    $wrapped = is_array($decoded['address'] ?? null);
                    $inner   = $wrapped ? $decoded['address'] : $decoded;
                    $fixed   = $n->normalizeAddressJson($inner);

                    if ($fixed === $inner) {
                        continue;
                    }
                    if ($wrapped) {
                        $decoded['address'] = $fixed;
                    } else {
                        $decoded = $fixed;
                    }

                    $this->audit($table, (int) $row->id, $col, is_string($raw) ? $raw : json_encode($raw));
                    DB::table($table)->where('id', $row->id)->update([$col => json_encode($decoded)]);
                }
            }
        });
    }

    /** Normalize a bare city-name column to its canonical city. */
    private function rewriteName(LocationNormalizer $n, string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->select('id', $column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($n, $table, $column) {
                foreach ($rows as $row) {
                    $old = (string) $row->$column;
                    $new = $n->normalize(['city' => $old])['city'];
                    if ($new === null || $new === $old) {
                        continue;
                    }
                    $this->audit($table, (int) $row->id, $column, $old);
                    DB::table($table)->where('id', $row->id)->update([$column => $new]);
                }
            });
    }

    private function audit(string $table, int $id, string $column, ?string $old): void
    {
        DB::table(self::AUDIT)->insert([
            'table_name'  => $table,
            'row_id'      => $id,
            'column_name' => $column,
            'old_value'   => $old,
            'created_at'  => now(),
        ]);
    }
};
