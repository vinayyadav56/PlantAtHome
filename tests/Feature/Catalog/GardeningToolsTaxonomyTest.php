<?php

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\ToolsTaxonomyImporter;
use Tests\TestCase;

/**
 * The Gardening Tools taxonomy import (owner brief 2026-10-14): 14 parents, 74 sub-categories,
 * 390 tool types, 28 placements, legacy Tools categories mapped instead of duplicated, and the
 * seeded tools linked into it. Create-only and idempotent, because staging runs it on every boot.
 *
 * In-memory sqlite with hand-built tables (the CategorySeedersKeepOperatorEditsTest idiom).
 * translations_cache exists because renaming a reused root goes through the Category model,
 * whose translation observer looks there; products_meta because Product is Metable.
 */
class GardeningToolsTaxonomyTest extends TestCase
{
    private int $tools;

    private int $plants;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        Cache::flush();

        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
            $t->string('icon')->nullable();
            $t->json('image')->nullable();
            $t->json('banner_image')->nullable();
            $t->text('details')->nullable();
            $t->unsignedBigInteger('parent')->nullable();
            $t->unsignedBigInteger('type_id');
            $t->boolean('show_on_homepage')->default(false);
            $t->integer('homepage_sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->integer('display_order')->default(0);
            $t->boolean('noindex')->default(false);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('category_placements', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('parent_id');
            $t->integer('display_order')->default(0);
            $t->timestamps();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
            $t->unsignedBigInteger('type_id')->nullable();
            $t->string('status')->default('publish');
            $t->string('visibility')->default('visibility_public');
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->nullable();
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('translations_cache', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('translatable_type');
            $t->unsignedBigInteger('translatable_id');
            $t->string('language', 12);
            $t->json('translated_fields')->nullable();
            $t->char('source_hash', 64)->nullable();
            $t->string('translation_source', 32)->default('google');
            $t->string('status')->default('pending');
            $t->boolean('is_reviewed')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->text('last_error')->nullable();
            $t->timestamps();
        });

        $this->plants = DB::table('types')->insertGetId(['name' => 'Plants', 'slug' => 'plants']);
        $this->tools = DB::table('types')->insertGetId(['name' => 'Tools', 'slug' => 'tools']);
    }

    /** Today's Tools roots (PlantAtHomeCategorySeeder) and the 40 seeded tools linked to them. */
    private function legacyCatalogue(array $overrides = []): void
    {
        $roots = [
            'pruning-cutting'  => 'Pruning & Cutting',
            'watering-tools'   => 'Watering',
            'planters-pots'    => 'Planters & Pots',
            'soil-care'        => 'Soil & Care',
            'tool-sets'        => 'Tool Sets',
            'tool-accessories' => 'Accessories',
        ];
        $ids = [];
        foreach ($roots as $slug => $name) {
            $ids[$slug] = DB::table('categories')->insertGetId(array_merge(
                ['name' => $name, 'slug' => $slug, 'type_id' => $this->tools, 'show_on_homepage' => true],
                $overrides[$slug] ?? []
            ));
        }
        $tools = json_decode(file_get_contents(base_path('packages/marvel/data/tools.json')), true);
        foreach ($tools as $tool) {
            // Query builder, not Product::create(): see ProductFilterFacetsTest on Metable's static column cache.
            $pid = DB::table('products')->insertGetId([
                'name' => $tool['name'], 'slug' => $tool['slug'], 'type_id' => $this->tools, 'price' => $tool['price'],
            ]);
            DB::table('category_product')->insert(['product_id' => $pid, 'category_id' => $ids[$tool['category']]]);
        }
    }

    private function import(bool $dryRun = false): array
    {
        return app(ToolsTaxonomyImporter::class)->run($dryRun);
    }

    private function cat(string $slug): ?object
    {
        return DB::table('categories')->where('slug', $slug)->first();
    }

    private function depthOf(int $id): int
    {
        $depth = 1;
        $seen = [$id => true];
        $parent = DB::table('categories')->where('id', $id)->value('parent');
        while ($parent) {
            $this->assertArrayNotHasKey($parent, $seen, 'cycle in categories.parent');
            $seen[$parent] = true;
            $depth++;
            $parent = DB::table('categories')->where('id', $parent)->value('parent');
        }

        return $depth;
    }

    private function counts(): array
    {
        return [
            'categories' => DB::table('categories')->count(),
            'placements' => DB::table('category_placements')->count(),
            'links'      => DB::table('category_product')->count(),
            'active'     => DB::table('categories')->where('is_active', true)->count(),
        ];
    }

    public function test_import_builds_the_whole_tree_and_maps_the_legacy_roots(): void
    {
        $this->legacyCatalogue();
        $pruningId = $this->cat('pruning-cutting')->id;

        $report = $this->import();

        // 14 parents: 11 new + 3 reused legacy roots. 74 + 390 below them. One alias (Digging Forks).
        $this->assertSame(11 + 74 + 390, $report['created']);
        $this->assertSame(3, $report['renamed']);
        $this->assertSame(28, $report['placements']);
        $this->assertSame(1, $report['aliases']);
        $this->assertSame([], $report['skipped']);

        $data = json_decode(file_get_contents(base_path('packages/marvel/data/tools-taxonomy.json')), true);
        $byDepth = [1 => 0, 2 => 0, 3 => 0];
        foreach ($data['parents'] as $p) {
            $row = $this->cat($p['slug']);
            $this->assertSame(1, $this->depthOf($row->id), "{$p['slug']} is a root");
            $byDepth[1]++;
            foreach ($p['children'] as $s) {
                $this->assertSame(2, $this->depthOf($this->cat($s['slug'])->id));
                $byDepth[2]++;
                foreach ($s['children'] as $l) {
                    $leaf = $this->cat($l['slug']);
                    $this->assertSame(3, $this->depthOf($leaf->id));
                    $this->assertSame($this->tools, (int) $leaf->type_id);
                    $this->assertSame($this->cat($s['slug'])->id, (int) $leaf->parent);
                    $byDepth[3]++;
                }
            }
        }
        $this->assertSame([1 => 14, 2 => 74, 3 => 390], $byDepth);

        // Slugs are unique: one row per slug, so /c/{slug} always resolves to one page.
        $this->assertSame(0, DB::table('categories')->select('slug')->groupBy('slug')->havingRaw('COUNT(*) > 1')->count());

        // Reused roots keep their id (URLs, homepage cards and product links survive) and get the new name.
        $pruning = $this->cat('pruning-cutting');
        $this->assertSame($pruningId, $pruning->id);
        $this->assertSame('Pruning and Cutting Tools', $pruning->name);
        $this->assertSame('Watering and Irrigation', $this->cat('watering-tools')->name);
        $this->assertSame('Gardening Tool Sets and Kits', $this->cat('tool-sets')->name);
        $this->assertSame(40, (int) $pruning->display_order);
        $this->assertSame(150, (int) $this->cat('soil-care')->display_order, 'kept roots sort after the 14');

        // New categories are live but never flagged for the homepage strips.
        $this->assertSame(0, DB::table('categories')->where('slug', 'hand-gardening-tools')->where('show_on_homepage', true)->count());
        $this->assertTrue((bool) $this->cat('hand-trowels')->is_active);

        // Placements: Root Hooks is one category, primary under Potting Tools, also under Bonsai and Repotting.
        $rootHooks = $this->cat('root-hooks');
        $this->assertSame($this->cat('potting-tools')->id, (int) $rootHooks->parent);
        $this->assertEqualsCanonicalizing(
            [$this->cat('bonsai-tools')->id, $this->cat('repotting-tools')->id],
            DB::table('category_placements')->where('category_id', $rootHooks->id)->pluck('parent_id')->map(fn ($v) => (int) $v)->all()
        );
        // The leaf that repeats its own sub-category is folded into it, not created twice.
        $this->assertSame(1, DB::table('categories')->where('slug', 'digging-forks')->count());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->legacyCatalogue();
        $this->import();
        $before = $this->counts();

        $report = $this->import();

        $this->assertSame($before, $this->counts());
        foreach (['created', 'renamed', 'ordered', 'placements', 'links', 'retired'] as $key) {
            $this->assertSame(0, $report[$key], "a re-run must not change {$key}");
        }
    }

    public function test_seeded_tools_gain_their_new_categories_and_keep_the_old_ones(): void
    {
        $this->legacyCatalogue();
        $trowel = DB::table('products')->where('slug', 'steel-hand-trowel')->value('id');
        $accessories = $this->cat('tool-accessories')->id;

        $report = $this->import();

        $this->assertSame(29, $report['links'], '26 tools, 3 of them in two tool types');
        $linked = DB::table('category_product')->where('product_id', $trowel)->pluck('category_id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($this->cat('hand-trowels')->id, $linked);
        $this->assertContains($accessories, $linked, 'the legacy link is kept (additive)');
        $this->assertCount(2, DB::table('category_product')->where('product_id', DB::table('products')->where('slug', 'hand-cultivator')->value('id'))->get()->filter(
            fn ($r) => in_array((int) $r->category_id, [$this->cat('hand-cultivators')->id, $this->cat('three-prong-cultivators')->id], true)
        ));
        // Pots and consumables are not tools: untouched.
        $pot = DB::table('products')->where('slug', 'terracotta-pots-set-3')->value('id');
        $this->assertSame([$this->cat('planters-pots')->id], DB::table('category_product')->where('product_id', $pot)->pluck('category_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_accessories_is_retired_only_once_its_products_live_elsewhere(): void
    {
        $this->legacyCatalogue();

        $this->import();

        $this->assertFalse((bool) $this->cat('tool-accessories')->is_active, 'Accessories retired after its 7 tools were re-homed');
        $this->assertSame(1, DB::table('categories')->where('slug', 'tool-accessories')->count(), 'retired, never deleted');
        $this->assertTrue((bool) $this->cat('soil-care')->is_active);
        $this->assertTrue((bool) $this->cat('planters-pots')->is_active);
    }

    public function test_accessories_stays_while_a_listed_product_would_be_stranded(): void
    {
        $this->legacyCatalogue();
        // A listed product only Accessories holds (no new home in the data file).
        $orphan = DB::table('products')->insertGetId(['name' => 'Garden Apron', 'slug' => 'garden-apron', 'type_id' => $this->tools, 'price' => 299]);
        DB::table('category_product')->insert(['product_id' => $orphan, 'category_id' => $this->cat('tool-accessories')->id]);

        $report = $this->import();

        $this->assertTrue((bool) $this->cat('tool-accessories')->is_active);
        $this->assertNotEmpty(array_filter($report['skipped'], fn ($l) => str_contains($l, 'tool-accessories')));
    }

    public function test_empty_stale_roots_are_retired_and_used_ones_kept(): void
    {
        $this->legacyCatalogue();
        foreach (['garden-tools' => 'Garden Tools', 'soil-fertilizer' => 'Soil & Fertilizer', 'watering' => 'Watering', 'pots-planters' => 'Pots & Planters'] as $slug => $name) {
            DB::table('categories')->insert(['name' => $name, 'slug' => $slug, 'type_id' => $this->tools]);
        }
        $pid = DB::table('products')->insertGetId(['name' => 'Kept', 'slug' => 'kept', 'type_id' => $this->tools, 'price' => 1]);
        DB::table('category_product')->insert(['product_id' => $pid, 'category_id' => $this->cat('watering')->id]);
        // Production's case: stale roots holding only products nobody can see (unlisted, drafts).
        $hidden = DB::table('products')->insertGetId(['name' => 'Old', 'slug' => 'old', 'type_id' => $this->tools, 'price' => 1, 'listing_enabled' => false]);
        $draft = DB::table('products')->insertGetId(['name' => 'Draft', 'slug' => 'draft', 'type_id' => $this->tools, 'price' => 1, 'status' => 'draft']);
        DB::table('category_product')->insert(['product_id' => $hidden, 'category_id' => $this->cat('garden-tools')->id]);
        DB::table('category_product')->insert(['product_id' => $draft, 'category_id' => $this->cat('pots-planters')->id]);

        $this->import();

        $this->assertFalse((bool) $this->cat('garden-tools')->is_active, 'only an unlisted product: retired');
        $this->assertFalse((bool) $this->cat('soil-fertilizer')->is_active);
        $this->assertFalse((bool) $this->cat('pots-planters')->is_active, 'only a draft: retired');
        $this->assertTrue((bool) $this->cat('watering')->is_active, 'a stale root that holds a listed product is kept');
        $this->assertSame(1, DB::table('category_product')->where('product_id', $hidden)->count(), 'retiring never touches product links');
    }

    public function test_admin_edits_survive_a_rerun(): void
    {
        $this->legacyCatalogue(['pruning-cutting' => ['name' => 'Secateurs & Saws']]);
        $this->import();
        $this->assertSame('Secateurs & Saws', $this->cat('pruning-cutting')->name, 'an admin-renamed root is not renamed');

        DB::table('categories')->where('slug', 'hand-trowels')->update([
            'name' => 'Trowels (hand)', 'details' => 'Owner copy', 'is_active' => false, 'display_order' => 99,
            'image' => json_encode(['original' => 'https://cdn.example/trowel.webp']),
        ]);
        $this->import();

        $row = $this->cat('hand-trowels');
        $this->assertSame('Trowels (hand)', $row->name);
        $this->assertSame('Owner copy', $row->details);
        $this->assertFalse((bool) $row->is_active);
        $this->assertSame(99, (int) $row->display_order);
        $this->assertStringContainsString('trowel.webp', $row->image);
    }

    public function test_a_slug_owned_by_another_vertical_is_never_moved(): void
    {
        $this->legacyCatalogue();
        $plantsRow = DB::table('categories')->insertGetId(['name' => 'Hand Trowels (plants)', 'slug' => 'hand-trowels', 'type_id' => $this->plants]);

        $report = $this->import();

        $row = DB::table('categories')->find($plantsRow);
        $this->assertSame($this->plants, (int) $row->type_id);
        $this->assertNull($row->parent);
        $this->assertSame(1, DB::table('categories')->where('slug', 'hand-trowels')->count());
        $this->assertNotEmpty(array_filter($report['skipped'], fn ($l) => str_starts_with($l, 'hand-trowels')));
    }

    public function test_without_a_tools_vertical_nothing_happens(): void
    {
        DB::table('types')->where('slug', 'tools')->delete();

        $report = $this->import();

        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame(0, $report['created']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->legacyCatalogue();
        $before = $this->counts();

        $report = $this->import(true);

        $this->assertSame(475, $report['created']);
        $this->assertSame($before, $this->counts());
        $this->assertSame('Pruning & Cutting', $this->cat('pruning-cutting')->name);
    }

    public function test_rollback_restores_the_catalogue(): void
    {
        $this->legacyCatalogue();
        $before = $this->counts();
        $this->import();

        $report = app(ToolsTaxonomyImporter::class)->rollback();

        $this->assertSame($before, $this->counts());
        $this->assertSame(475, $report['deleted'], 'unused created categories are removed');
        $this->assertSame('Pruning & Cutting', $this->cat('pruning-cutting')->name);
        $this->assertTrue((bool) $this->cat('tool-accessories')->is_active);
        $this->assertSame(0, DB::table(ToolsTaxonomyImporter::BACKUP)->count());
    }

    public function test_relabel_renames_only_rows_no_admin_edited(): void
    {
        $this->legacyCatalogue();
        $this->import();
        DB::table('categories')->where('slug', 'loppers')->update(['name' => 'Lopper Shears']);                     // drift, unedited
        DB::table('categories')->where('slug', 'shears')->update(['name' => 'All Shears', 'updated_by_user_id' => 7]); // admin's

        $report = app(ToolsTaxonomyImporter::class)->relabel();

        $this->assertSame('Loppers', $this->cat('loppers')->name);
        $this->assertSame('All Shears', $this->cat('shears')->name);
        $this->assertGreaterThanOrEqual(1, $report['renamed']);
    }
}
