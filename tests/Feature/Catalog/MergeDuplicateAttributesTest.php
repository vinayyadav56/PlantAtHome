<?php

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_17_100000_merge_duplicate_attributes.
 *
 * Production had five attributes named "Size" and 71 variable products attached
 * to the values of two of them, so product pages drew the size chips twice:
 * "Small Medium Large Small Medium Large". The migration folds same-named
 * attributes into one and de-duplicates the pivot.
 *
 * In-memory sqlite with hand-built tables (the ProductFilterFacetsTest idiom) —
 * the migration deliberately uses the query builder and PHP-side grouping, with
 * no MySQL-only SQL, so it runs here unchanged. Foreign keys are off, which is
 * also why the migration deletes in explicit FK order instead of leaning on
 * ON DELETE CASCADE.
 */
class MergeDuplicateAttributesTest extends TestCase
{
    /** Canonical: the attribute most products already point at. */
    private const CANONICAL_ID = 12;

    /** Duplicate: same name, minted later with a shop_id. */
    private const DUPLICATE_ID = 13;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default'               => 'array',
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug');
            $t->string('name');
            $t->string('language')->default('en');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->timestamps();
        });
        Schema::create('attribute_values', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('attribute_id');
            $t->string('value');
            $t->string('language')->default('en');
            $t->text('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('attribute_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('attribute_value_id');
            $t->unsignedBigInteger('product_id');
            $t->timestamps();
        });

        $this->seedDuplicates();
    }

    protected function tearDown(): void
    {
        foreach (['attribute_product', 'attribute_values', 'attributes', 'pah_attribute_merge_backup'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_folds_same_named_attributes_into_one_and_leaves_three_sizes_per_product(): void
    {
        // Before: product 1 carries six pivot rows for three sizes — the bug.
        $this->assertSame(6, $this->pivotCount(1));

        $this->runMigration();

        $sizeAttributes = DB::table('attributes')->where('name', 'Size')->get();
        $this->assertCount(1, $sizeAttributes, 'the two "Size" attributes should have been folded into one');
        $this->assertSame(self::CANONICAL_ID, (int) $sizeAttributes->first()->id, 'the attribute most products point at wins');
        $this->assertNull($sizeAttributes->first()->shop_id, 'attributes are global in the single-shop model');

        // The survivor takes the plain slug back. sizeValueIds() resolves the Size
        // attribute BY slug `size`, so a survivor left on `size-zLh` would have the
        // helper mint a fresh "Size" on its next run — the bug, all over again.
        $this->assertSame('size', $sizeAttributes->first()->slug);

        $this->assertSame(3, DB::table('attribute_values')->where('attribute_id', self::CANONICAL_ID)->count());
        $this->assertSame(0, DB::table('attribute_values')->where('attribute_id', self::DUPLICATE_ID)->count());

        // Product 1 was attached to both attributes, product 2 to the duplicate
        // only (its links are repointed, not dropped), product 3 carried the same
        // value twice. All three end up with exactly one row per size.
        $this->assertSame(3, $this->pivotCount(1));
        $this->assertSame(3, $this->pivotCount(2));
        $this->assertSame(3, $this->pivotCount(3));

        // Every surviving link points at a value that still exists.
        $orphans = DB::table('attribute_product')
            ->whereNotIn('attribute_value_id', DB::table('attribute_values')->pluck('id'))
            ->count();
        $this->assertSame(0, $orphans);
    }

    /**
     * The guards are the reason this can't come back, so assert they land.
     * Schema::hasIndex() is Laravel 11+ and this app is on 10.x, hence the
     * driver-level check in the migration (and here).
     *
     * @test
     */
    public function it_adds_the_unique_indexes_that_make_the_doubling_unrepresentable(): void
    {
        $this->runMigration();

        $this->assertTrue($this->indexExists('attribute_product', 'attribute_product_product_value_unique'));
        $this->assertTrue($this->indexExists('attributes', 'attributes_slug_language_unique'));

        // And the pivot index really does reject a second identical link.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('attribute_product')->insert([
            ['product_id' => 1, 'attribute_value_id' => 38],
            ['product_id' => 1, 'attribute_value_id' => 38],
        ]);
    }

    /** @test */
    public function it_records_what_it_removed_and_busts_the_product_cache(): void
    {
        Cache::forever('products:ver', 4);

        $this->runMigration();

        $this->assertGreaterThan(0, DB::table('pah_attribute_merge_backup')->count());
        $this->assertGreaterThan(0, DB::table('pah_attribute_merge_backup')->where('source_table', 'attributes')->count());
        $this->assertGreaterThan(0, DB::table('pah_attribute_merge_backup')->where('source_table', 'attribute_product')->count());
        $this->assertSame(5, (int) Cache::get('products:ver'), 'cached product payloads must be invalidated');
    }

    /** @test */
    public function running_it_twice_changes_nothing(): void
    {
        $this->runMigration();

        $attributes = DB::table('attributes')->orderBy('id')->get()->toArray();
        $values     = DB::table('attribute_values')->orderBy('id')->get()->toArray();
        $pivots     = DB::table('attribute_product')->orderBy('id')->get()->toArray();

        $this->runMigration();

        $this->assertEquals($attributes, DB::table('attributes')->orderBy('id')->get()->toArray());
        $this->assertEquals($values, DB::table('attribute_values')->orderBy('id')->get()->toArray());
        $this->assertEquals($pivots, DB::table('attribute_product')->orderBy('id')->get()->toArray());
    }

    /* ── fixture ────────────────────────────────────────────────────────── */

    private function seedDuplicates(): void
    {
        DB::table('attributes')->insert([
            // The survivor deliberately carries a SUFFIXED slug: the slugifier
            // appends random characters on collision, so production really did
            // hold `size-zLh` and `color-OMG` alongside plain `size`.
            ['id' => self::CANONICAL_ID, 'slug' => 'size-zLh', 'name' => 'Size', 'language' => 'en', 'shop_id' => 12],
            ['id' => self::DUPLICATE_ID, 'slug' => 'size', 'name' => 'Size', 'language' => 'en', 'shop_id' => 12],
        ]);

        $valueId = 38;
        $canonical = $duplicate = [];
        foreach (['Small', 'Medium', 'Large'] as $size) {
            $canonical[$size] = $valueId;
            DB::table('attribute_values')->insert([
                'id' => $valueId++, 'attribute_id' => self::CANONICAL_ID, 'slug' => strtolower($size), 'value' => $size, 'language' => 'en',
            ]);
        }
        foreach (['Small', 'Medium', 'Large'] as $size) {
            $duplicate[$size] = $valueId;
            DB::table('attribute_values')->insert([
                'id' => $valueId++, 'attribute_id' => self::DUPLICATE_ID, 'slug' => strtolower($size), 'value' => $size, 'language' => 'en',
            ]);
        }

        $rows = [];
        // Product 1: attached to BOTH attributes — the reported bug.
        foreach ($canonical + [] as $id) {
            $rows[] = ['product_id' => 1, 'attribute_value_id' => $id];
        }
        foreach ($duplicate as $id) {
            $rows[] = ['product_id' => 1, 'attribute_value_id' => $id];
        }
        // Product 2: the duplicate attribute only — links must be repointed.
        foreach ($duplicate as $id) {
            $rows[] = ['product_id' => 2, 'attribute_value_id' => $id];
        }
        // Product 3: canonical only, but one value linked twice.
        foreach ($canonical as $id) {
            $rows[] = ['product_id' => 3, 'attribute_value_id' => $id];
        }
        $rows[] = ['product_id' => 3, 'attribute_value_id' => $canonical['Small']];

        DB::table('attribute_product')->insert($rows);
    }

    private function runMigration(): void
    {
        $path = base_path('packages/marvel/database/migrations/2026_09_17_100000_merge_duplicate_attributes.php');
        (require $path)->up();
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $row) {
            if (($row->name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    private function pivotCount(int $productId): int
    {
        return DB::table('attribute_product')->where('product_id', $productId)->count();
    }
}
