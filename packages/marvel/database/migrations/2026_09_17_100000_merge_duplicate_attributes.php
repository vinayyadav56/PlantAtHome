<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Collapse attributes that share a name into one, and de-duplicate the
 * product ↔ attribute_value pivot.
 *
 * Why: product pages rendered the size chips twice — "Small Medium Large Small
 * Medium Large". The storefront draws one chip per `attribute_product` row and
 * groups them by `attribute.slug`, so a product attached to the values of TWO
 * attributes both named "Size" (both slug `size`) produces six chips for three
 * sizes. Production had five "Size" attributes (two of them demo data) and 71
 * of 865 variable products attached to two of them.
 *
 * How they appeared: four copies of
 *   Attribute::firstOrCreate(['slug' => 'size', 'language' => 'en', 'shop_id' => $shopId], …)
 * each deriving $shopId differently, plus 2026_07_12_000200 which later nulls
 * attributes.shop_id — so the next per-shop run minted a fresh "Size". The
 * lookup key no longer carries shop_id (see sizeValueIds() in helpers.php), and
 * the unique indexes added at the end of this migration make the doubling
 * unrepresentable from any future script.
 *
 * A migration rather than a command because staging (Railway) has no artisan
 * access and both environments run `migrate --force` on deploy.
 *
 * Safety: every row this migration rewrites or deletes is copied as JSON into
 * pah_attribute_merge_backup first. Idempotent — a second run finds no groups.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attributes') || !Schema::hasTable('attribute_values') || !Schema::hasTable('attribute_product')) {
            return;
        }

        $this->ensureBackupTable();

        DB::transaction(function () {
            foreach ($this->duplicateNameGroups() as $group) {
                $this->mergeGroup($group);
            }

            // Cause (2): `syncWithoutDetaching` could also attach the SAME value
            // twice to one product. Collapse any such pair, keeping the oldest row.
            $this->dropDuplicatePivotPairs();
        });

        $this->addUniqueIndexes();

        // Product payloads are cached under product:show:v{products:ver}:… —
        // bump the namespace so the merged catalogue is served immediately.
        try {
            Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
        } catch (\Throwable $e) {
            // A cache backend hiccup must never fail a deploy's migrate step.
        }
    }

    /**
     * One-way consolidation: the duplicate rows are gone, not renamed, so there
     * is nothing meaningful to reverse. Every deleted row is in
     * pah_attribute_merge_backup if a manual restore is ever needed.
     */
    public function down(): void
    {
        // intentionally empty
    }

    /* ── steps ──────────────────────────────────────────────────────────── */

    /** @return array<int, array<int, object>> attributes grouped by name+language, duplicates only */
    private function duplicateNameGroups(): array
    {
        $groups = [];
        foreach (DB::table('attributes')->orderBy('id')->get() as $attr) {
            // Grouped on NAME, not slug: the admin lists attributes by name and
            // the storefront groups chips by slug, so two rows named "Size" break
            // the page whether or not their slugs match (`size` and `size-zLh`
            // both existed on production).
            $key = mb_strtolower(trim((string) $attr->name)) . '|' . ($attr->language ?? 'en');
            $groups[$key][] = $attr;
        }

        return array_values(array_filter($groups, fn ($rows) => count($rows) > 1));
    }

    /** @param array<int, object> $attrs */
    private function mergeGroup(array $attrs): void
    {
        $canonical = $this->pickCanonical($attrs);

        foreach ($attrs as $attr) {
            if ((int) $attr->id === (int) $canonical->id) {
                continue;
            }
            $this->absorb($attr, $canonical);
        }

        $changes = [];

        // Single-shop model: attributes are global (2026_07_12_000200).
        if (Schema::hasColumn('attributes', 'shop_id') && $canonical->shop_id !== null) {
            $changes['shop_id'] = null;
        }

        // Normalise the surviving slug back to the name. The duplicates were
        // minted by a slugifier that suffixes collisions (`size-zLh`,
        // `color-OMG`), so the survivor can be holding a suffixed slug — and
        // sizeValueIds() looks the Size attribute up by slug `size`. Leaving a
        // suffixed slug there would have the helper mint a fresh duplicate on
        // its next run, which is the whole bug again.
        $preferred = Str::slug((string) $canonical->name);
        if ($preferred !== '' && $canonical->slug !== $preferred && !$this->slugTaken($preferred, $canonical)) {
            $changes['slug'] = $preferred;
        }

        if ($changes) {
            $this->backup('attributes', (array) $canonical);
            DB::table('attributes')->where('id', $canonical->id)->update($changes);
        }
    }

    /** Is $slug already used by another attribute in the same language? */
    private function slugTaken(string $slug, object $canonical): bool
    {
        return DB::table('attributes')
            ->where('slug', $slug)
            ->where('language', $canonical->language ?? 'en')
            ->where('id', '!=', $canonical->id)
            ->exists();
    }

    /**
     * The attribute the most products already point at wins — that keeps the
     * pivot rewrite small and leaves the ids the rest of the catalogue uses
     * untouched. Ties go to the lowest id (the oldest row).
     *
     * @param array<int, object> $attrs
     */
    private function pickCanonical(array $attrs): object
    {
        $best = null;
        $bestCount = -1;

        foreach ($attrs as $attr) {
            $count = DB::table('attribute_product')
                ->whereIn('attribute_value_id', $this->valueIdsOf((int) $attr->id))
                ->count();

            if ($count > $bestCount || ($count === $bestCount && $best && $attr->id < $best->id)) {
                $best = $attr;
                $bestCount = $count;
            }
        }

        return $best ?? $attrs[0];
    }

    /** Move $dup's referenced values onto $canonical, then delete $dup. */
    private function absorb(object $dup, object $canonical): void
    {
        $canonValues = [];
        foreach (DB::table('attribute_values')->where('attribute_id', $canonical->id)->get() as $v) {
            $canonValues[mb_strtolower(trim((string) $v->value))] = (int) $v->id;
        }

        foreach (DB::table('attribute_values')->where('attribute_id', $dup->id)->orderBy('id')->get() as $dupValue) {
            $refs = DB::table('attribute_product')->where('attribute_value_id', $dupValue->id)->get();
            if ($refs->isEmpty()) {
                // Nothing points at it — drop it rather than copying dead values
                // (e.g. the demo attribute's S/M/L/XL) onto the canonical row.
                continue;
            }

            $key = mb_strtolower(trim((string) $dupValue->value));
            $targetId = $canonValues[$key] ?? null;

            if ($targetId === null) {
                $targetId = $this->cloneValueOnto($canonical, $dupValue);
                $canonValues[$key] = $targetId;
            }

            foreach ($refs as $ref) {
                $this->backup('attribute_product', (array) $ref);

                $alreadyLinked = DB::table('attribute_product')
                    ->where('product_id', $ref->product_id)
                    ->where('attribute_value_id', $targetId)
                    ->exists();

                if ($alreadyLinked) {
                    // This is the doubled chip: the product already carries the
                    // canonical value, so the duplicate link just goes away.
                    DB::table('attribute_product')->where('id', $ref->id)->delete();
                    continue;
                }

                DB::table('attribute_product')->where('id', $ref->id)->update(['attribute_value_id' => $targetId]);
            }
        }

        // Explicit deletes in FK order — the sqlite test DB runs with foreign
        // keys off, so ON DELETE CASCADE cannot be relied on here.
        $dupValueIds = $this->valueIdsOf((int) $dup->id);
        if ($dupValueIds) {
            foreach (DB::table('attribute_product')->whereIn('attribute_value_id', $dupValueIds)->get() as $row) {
                $this->backup('attribute_product', (array) $row);
            }
            DB::table('attribute_product')->whereIn('attribute_value_id', $dupValueIds)->delete();

            foreach (DB::table('attribute_values')->whereIn('id', $dupValueIds)->get() as $row) {
                $this->backup('attribute_values', (array) $row);
            }
            DB::table('attribute_values')->whereIn('id', $dupValueIds)->delete();
        }

        $this->backup('attributes', (array) $dup);
        DB::table('attributes')->where('id', $dup->id)->delete();
    }

    private function cloneValueOnto(object $canonical, object $dupValue): int
    {
        $row = [
            'attribute_id' => $canonical->id,
            'value'        => $dupValue->value,
            'slug'         => $dupValue->slug ?? null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
        if (Schema::hasColumn('attribute_values', 'language')) {
            $row['language'] = $dupValue->language ?? ($canonical->language ?? 'en');
        }
        if (Schema::hasColumn('attribute_values', 'meta')) {
            $row['meta'] = $dupValue->meta ?? null;
        }
        if (Schema::hasColumn('attribute_values', 'translated_languages')) {
            $row['translated_languages'] = $dupValue->translated_languages ?? null;
        }

        return (int) DB::table('attribute_values')->insertGetId($row);
    }

    /** Same (product_id, attribute_value_id) more than once — keep the oldest row. */
    private function dropDuplicatePivotPairs(): void
    {
        $seen = [];
        $stale = [];

        DB::table('attribute_product')->orderBy('id')->select('id', 'product_id', 'attribute_value_id')
            ->chunk(2000, function ($rows) use (&$seen, &$stale) {
                foreach ($rows as $row) {
                    $pair = $row->product_id . '|' . $row->attribute_value_id;
                    if (isset($seen[$pair])) {
                        $stale[] = $row->id;
                        continue;
                    }
                    $seen[$pair] = true;
                }
            });

        foreach (array_chunk($stale, 500) as $chunk) {
            foreach (DB::table('attribute_product')->whereIn('id', $chunk)->get() as $row) {
                $this->backup('attribute_product', (array) $row);
            }
            DB::table('attribute_product')->whereIn('id', $chunk)->delete();
        }
    }

    /** Both indexes are added only once the data can satisfy them. */
    private function addUniqueIndexes(): void
    {
        if (!$this->hasDuplicatePivotPairs() && !$this->hasIndex('attribute_product', 'attribute_product_product_value_unique')) {
            try {
                Schema::table('attribute_product', function (Blueprint $table) {
                    $table->unique(['product_id', 'attribute_value_id'], 'attribute_product_product_value_unique');
                });
            } catch (\Throwable $e) {
                // Index creation is a guard, not the fix — never fail the deploy on it.
            }
        }

        if (!$this->hasDuplicateSlugs() && !$this->hasIndex('attributes', 'attributes_slug_language_unique')) {
            try {
                Schema::table('attributes', function (Blueprint $table) {
                    $table->unique(['slug', 'language'], 'attributes_slug_language_unique');
                });
            } catch (\Throwable $e) {
                // as above
            }
        }
    }

    /* ── helpers ────────────────────────────────────────────────────────── */

    /** @return array<int, int> */
    private function valueIdsOf(int $attributeId): array
    {
        return DB::table('attribute_values')->where('attribute_id', $attributeId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function hasDuplicatePivotPairs(): bool
    {
        return DB::table('attribute_product')
            ->select('product_id', 'attribute_value_id')
            ->groupBy('product_id', 'attribute_value_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasDuplicateSlugs(): bool
    {
        return DB::table('attributes')
            ->select('slug', 'language')
            ->groupBy('slug', 'language')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            return Schema::hasIndex($table, $index);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureBackupTable(): void
    {
        if (Schema::hasTable('pah_attribute_merge_backup')) {
            return;
        }
        Schema::create('pah_attribute_merge_backup', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('source_table', 64)->index();
            $t->text('row');
            $t->dateTime('backed_up_at')->nullable();
        });
    }

    private function backup(string $table, array $row): void
    {
        DB::table('pah_attribute_merge_backup')->insert([
            'source_table' => $table,
            'row'          => json_encode($row),
            'backed_up_at' => now(),
        ]);
    }
};
