<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Seeders\PlantAtHomeCategoryBulkSeeder;
use Marvel\Database\Seeders\PlantAtHomeCategorySeeder;
use Tests\TestCase;

/**
 * Staging runs the category seeders and categorize-plants on EVERY boot (production runs
 * them in prod-data-op modes). They used to re-apply stock photos to existing categories
 * and delete every Plants category outside the curated list — so the owner's photos and
 * their Bonsai / Palms / Rare & Exotic tiles were undone minutes after being applied.
 *
 * Contract now: seed data creates categories but never re-dresses them, and a category an
 * operator flagged for the homepage survives the granular clean-up.
 */
final class CategorySeedersKeepOperatorEditsTest extends TestCase
{
    private const OWNER = 'https://cdn.plantathome.in/category-tiles/2026-10-07/';

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
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
        // The translation overlay checks this when a translatable field (name/details) changes.
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

        foreach (['plants', 'tools', 'farm-box'] as $i => $slug) {
            DB::table('types')->insert(['id' => $i + 1, 'name' => ucfirst($slug), 'slug' => $slug, 'language' => 'en']);
        }
    }

    private function ownerCategory(string $slug, string $name, bool $flagged): void
    {
        DB::table('categories')->insert([
            'name' => $name, 'slug' => $slug, 'language' => 'en', 'type_id' => 1, 'details' => "{$name} — owner copy",
            'image' => json_encode(['id' => "pah-tile-{$slug}", 'original' => self::OWNER . "{$slug}-1200.webp"]),
            'show_on_homepage' => $flagged, 'homepage_sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function image(string $slug): ?string
    {
        $raw = DB::table('categories')->where('slug', $slug)->value('image');
        return $raw ? (json_decode($raw, true)['original'] ?? null) : null;
    }

    public function test_a_staging_boot_keeps_the_owners_photos_and_flagged_tiles(): void
    {
        // Curated (categorize-plants), CategorySeeder-listed, bulk-listed, and outside every list.
        $this->ownerCategory('indoor', 'Indoor Plants', true);
        $this->ownerCategory('succulents-cacti', 'Succulents & Cacti', true);
        $this->ownerCategory('bonsai', 'Bonsai', true);
        $this->ownerCategory('palms-tropical', 'Palms & Tropical Plants', true);
        $this->ownerCategory('old-granular', 'Old Granular', false);

        // The staging boot order (.railway/start.sh).
        $this->seed(PlantAtHomeCategorySeeder::class);
        $this->seed(PlantAtHomeCategoryBulkSeeder::class);
        Artisan::call('plantathome:categorize-plants');

        foreach (['indoor', 'succulents-cacti', 'bonsai', 'palms-tropical'] as $slug) {
            $this->assertSame(self::OWNER . "{$slug}-1200.webp", $this->image($slug), "{$slug} photo survives a boot");
        }
        $this->assertSame('Indoor Plants', DB::table('categories')->where('slug', 'indoor')->value('name'), 'admin names are not reset');
        $this->assertSame('Bonsai — owner copy', DB::table('categories')->where('slug', 'bonsai')->value('details'));

        // Flagged categories outside the curated list survive; unflagged granular ones still go.
        $this->assertSame(1, DB::table('categories')->where('slug', 'bonsai')->count());
        $this->assertSame(1, DB::table('categories')->where('slug', 'palms-tropical')->count());
        $this->assertSame(0, DB::table('categories')->where('slug', 'old-granular')->count());
        $this->assertSame(0, DB::table('categories')->where('slug', 'tree')->where('type_id', 1)->count(), 'bulk granular categories are still cleaned up');
    }

    public function test_seed_data_still_creates_what_is_missing(): void
    {
        $this->seed(PlantAtHomeCategorySeeder::class);
        Artisan::call('plantathome:categorize-plants');

        // A fresh environment still gets the curated set, with the stock photos.
        $this->assertStringContainsString('unsplash.com', (string) $this->image('herbs'));
        $this->assertSame('Herbs', DB::table('categories')->where('slug', 'herbs')->value('name'));
        $this->assertSame(1, DB::table('categories')->where('slug', 'pruning-cutting')->count(), 'other verticals seeded too');
    }
}
