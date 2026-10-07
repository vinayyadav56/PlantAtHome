<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The owner's category photos migration, run against a production-shaped Plants tree:
 * real categories unflagged while their empty name-duplicates are flagged, a retired
 * Medicinal with "Retired — …" copy, a soft-deleted Bonsai, categories the environment
 * lacks, another vertical sharing slugs. up() must dress exactly the eleven, create the
 * missing ones, and leave the tile list in the owner's order; down() must put every
 * touched row back.
 */
final class OwnerCategoryTilesMigrationTest extends TestCase
{
    private const MIGRATION = 'packages/marvel/database/migrations/2026_10_07_130000_apply_owner_category_tiles.php';
    private const BASE = 'https://cdn.plantathome.in/category-tiles/2026-10-07/';
    private const ORDER = [
        'indoor', 'outdoor', 'flowering', 'succulents-cacti', 'foliage', 'herbs',
        'climbers-vines', 'palms-tropical', 'bonsai', 'rare-exotic', 'medicinal',
    ];

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

        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
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
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function seedProductionShape(): void
    {
        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants'], ['id' => 2, 'name' => 'Tools', 'slug' => 'tools']]);
        $unsplash = json_encode(['original' => 'https://images.unsplash.com/photo-x']);
        // One key set for every row: a multi-row insert needs the same columns throughout.
        $row = fn (array $r) => $r + ['type_id' => 1, 'language' => 'en', 'image' => $unsplash, 'details' => null, 'is_active' => true, 'show_on_homepage' => false, 'homepage_sort_order' => 0, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()];
        DB::table('categories')->insert([
            $row(['id' => 1, 'name' => 'Indoor Plants', 'slug' => 'indoor', 'details' => 'Plants that thrive inside the home or office.']),
            $row(['id' => 2, 'name' => 'Indoor Plants', 'slug' => 'indoor-plants', 'show_on_homepage' => true]),
            $row(['id' => 3, 'name' => 'Outdoor Plants', 'slug' => 'outdoor']),
            $row(['id' => 4, 'name' => 'Outdoor Plants', 'slug' => 'outdoor-plants', 'show_on_homepage' => true]),
            $row(['id' => 5, 'name' => 'Flowering Plants', 'slug' => 'flowering']),
            $row(['id' => 6, 'name' => 'Flowering Plants', 'slug' => 'flowering-plants', 'show_on_homepage' => true]),
            $row(['id' => 7, 'name' => 'Herbs', 'slug' => 'herbs', 'show_on_homepage' => true]),
            $row(['id' => 8, 'name' => 'Gifts & Planters', 'slug' => 'gifts-planters', 'show_on_homepage' => true]),
            $row(['id' => 9, 'name' => 'Medicinal', 'slug' => 'medicinal', 'is_active' => false, 'details' => 'Retired — this characteristic now lives on each plant as an attribute.']),
            $row(['id' => 10, 'name' => 'Bonsai', 'slug' => 'bonsai', 'deleted_at' => now()]),
            // Another vertical with a shared slug must not be touched.
            $row(['id' => 11, 'name' => 'Outdoor Tools', 'slug' => 'outdoor', 'type_id' => 2, 'show_on_homepage' => true]),
        ]);
    }

    private function tiles(): array
    {
        return DB::table('categories')->where('type_id', 1)->whereNull('parent')->whereNull('deleted_at')
            ->where('show_on_homepage', true)->where('is_active', true)
            ->orderBy('homepage_sort_order')->orderBy('name')->pluck('slug')->all();
    }

    public function test_up_dresses_activates_creates_and_orders_the_owners_tiles(): void
    {
        $this->seedProductionShape();
        $this->migration()->up();

        $this->assertSame([...self::ORDER, 'gifts-planters'], $this->tiles());

        foreach (self::ORDER as $i => $slug) {
            $c = DB::table('categories')->where('type_id', 1)->where('slug', $slug)->whereNull('deleted_at')->first();
            $this->assertNotNull($c, $slug);
            $image = json_decode($c->image, true);
            $this->assertSame(self::BASE . "{$slug}-1200.webp", $image['original'], $slug);
            $this->assertSame(self::BASE . "{$slug}-600.webp", $image['thumbnail'], $slug);
            $this->assertSame("pah-tile-{$slug}", $image['id'], 'the admin uploader only renders images with an id');
            $this->assertSame($i + 1, (int) $c->homepage_sort_order, $slug);
        }

        // Created where missing, with production's name; restored where soft-deleted.
        $this->assertSame('Palms & Tropical Plants', DB::table('categories')->where('slug', 'palms-tropical')->value('name'));
        $this->assertSame(1, DB::table('categories')->where('slug', 'bonsai')->count(), 'restore, never a second Bonsai');
        // Medicinal is live and no longer says "Retired"; other copy is untouched.
        $this->assertNull(DB::table('categories')->where('id', 9)->value('details'));
        $this->assertSame('Plants that thrive inside the home or office.', DB::table('categories')->where('id', 1)->value('details'));
        // Empty duplicates unflagged (still active); the other vertical untouched.
        $this->assertSame([0, 0, 0], DB::table('categories')->whereIn('id', [2, 4, 6])->pluck('show_on_homepage')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1, 1, 1], DB::table('categories')->whereIn('id', [2, 4, 6])->pluck('is_active')->map(fn ($v) => (int) $v)->all());
        $tools = DB::table('categories')->where('id', 11)->first();
        $this->assertStringContainsString('unsplash', $tools->image);
        $this->assertSame(0, (int) $tools->homepage_sort_order);
    }

    public function test_down_restores_every_touched_row(): void
    {
        $this->seedProductionShape();
        $before = DB::table('categories')->orderBy('id')->get(['id', 'image', 'details', 'is_active', 'show_on_homepage', 'homepage_sort_order', 'deleted_at'])
            ->map(fn ($r) => array_merge((array) $r, ['deleted' => $r->deleted_at !== null, 'deleted_at' => null]))->all();
        // Production today: all sort orders 0, so the empty duplicates lead by name.
        $this->assertSame(['flowering-plants', 'gifts-planters', 'herbs', 'indoor-plants', 'outdoor-plants'], $this->tiles());

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $after = DB::table('categories')->whereIn('id', range(1, 11))->orderBy('id')->get(['id', 'image', 'details', 'is_active', 'show_on_homepage', 'homepage_sort_order', 'deleted_at'])
            ->map(fn ($r) => array_merge((array) $r, ['deleted' => $r->deleted_at !== null, 'deleted_at' => null]))->all();
        $this->assertEquals($before, $after);
        $this->assertSame(['flowering-plants', 'gifts-planters', 'herbs', 'indoor-plants', 'outdoor-plants'], $this->tiles());
        // Categories the migration created are retired again (soft, in case plants were added).
        $this->assertSame(0, DB::table('categories')->where('slug', 'palms-tropical')->whereNull('deleted_at')->count());
        $this->assertFalse(Schema::hasTable('pah_category_tiles_backup'));
    }

    public function test_a_database_without_plants_is_left_alone(): void
    {
        $this->migration()->up();

        $this->assertSame(0, DB::table('categories')->count());
        $this->assertFalse(Schema::hasTable('pah_category_tiles_backup'));
    }
}
