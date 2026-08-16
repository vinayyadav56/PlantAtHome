<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\LocationNormalizer;

/**
 * Re-run the city normalization over the ORIGINAL values, now that the normalizer is right.
 *
 * The first pass ran with a normalizer that let the location master's spelling win outright, so six
 * "Gurugram" addresses were rewritten to "Gurgaon" — the master holds one row for that place and it
 * still carries the pre-2016 name. The district rewrites in the same pass were correct and must
 * stay.
 *
 * Rather than special-case the damage, this replays the normalizer against the snapshot the first
 * pass took before it wrote anything. Feeding it the original input is what makes it self-
 * correcting: "Gurugram" now resolves back to "Gurugram", "South Delhi" still resolves to Delhi,
 * and a row the normalizer no longer wants to change is simply left alone. Re-running is a no-op.
 */
return new class extends Migration
{
    private const AUDIT = 'location_backfill_2026_08';

    public function up(): void
    {
        if (!Schema::hasTable(self::AUDIT)) {
            return;
        }

        LocationNormalizer::flush();
        $normalizer = new LocationNormalizer();

        // Oldest first: the audit may hold more than one entry for a row, and the earliest is the
        // true original.
        $seen = [];
        foreach (DB::table(self::AUDIT)->orderBy('id')->get() as $entry) {
            $key = $entry->table_name . '|' . $entry->row_id . '|' . $entry->column_name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (!Schema::hasTable($entry->table_name) || !Schema::hasColumn($entry->table_name, $entry->column_name)) {
                continue;
            }

            $original = $entry->old_value;
            if ($original === null || $original === '') {
                continue;
            }

            $decoded = json_decode((string) $original, true);
            if (is_array($decoded)) {
                $wrapped = is_array($decoded['address'] ?? null);
                $inner   = $wrapped ? $decoded['address'] : $decoded;
                $fixed   = $normalizer->normalizeAddressJson($inner);
                if ($wrapped) {
                    $decoded['address'] = $fixed;
                } else {
                    $decoded = $fixed;
                }
                $value = json_encode($decoded);
            } else {
                $value = $normalizer->normalize(['city' => (string) $original])['city'] ?: $original;
            }

            DB::table($entry->table_name)
                ->where('id', $entry->row_id)
                ->update([$entry->column_name => $value]);
        }
    }

    public function down(): void
    {
        // The 000300 migration's down() still restores every original from the same audit table.
    }
};
