<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Seeders\PlantAtHomeToolsSeeder;
use Tests\TestCase;

/**
 * The Tools vertical sold nothing: PlantAtHomeToolsSeeder created the 40 tools in
 * tools.json without Master Catalog membership (is_available_product / listing_enabled
 * default false, nothing backfills them), and re-applied name/copy/price/stock on every
 * staging boot, undoing admin edits.
 *
 * Contract now: the seeder creates tools listable and never re-dresses an existing one;
 * 2026_10_08_000000_list_seeded_tools lists exactly the seeded, published, live tools and
 * its down() puts every flag back.
 */
final class ToolsListingTest extends TestCase
{
    private const MIGRATION = 'packages/marvel/database/migrations/2026_10_08_000000_list_seeded_tools.php';

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
            $t->json('image')->nullable();
            $t->text('details')->nullable();
            $t->unsignedBigInteger('parent')->nullable();
            $t->unsignedBigInteger('type_id');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('status')->default('draft');
            $t->string('visibility')->default('visibility_public');
            $t->string('product_type')->default('simple');
            $t->boolean('in_stock')->default(true);
            $t->boolean('is_taxable')->default(false);
            $t->string('unit')->nullable();
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('min_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->integer('quantity')->default(0);
            $t->json('image')->nullable();
            $t->boolean('is_available_product')->default(false);
            $t->boolean('listing_enabled')->default(false);
            $t->timestamp('available_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
        // Product's Metable trait reads this on save.
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->nullable();
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
        // The translation observer checks this when a translatable field changes.
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

        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants'], ['id' => 2, 'name' => 'Tools', 'slug' => 'tools']]);
        foreach (['pruning-cutting', 'watering-tools', 'planters-pots', 'soil-care', 'tool-sets', 'tool-accessories'] as $i => $slug) {
            DB::table('categories')->insert(['id' => 100 + $i, 'name' => $slug, 'slug' => $slug, 'type_id' => 2]);
        }
    }

    private function tool(array $over): int
    {
        return DB::table('products')->insertGetId($over + [
            'language' => 'en', 'type_id' => 2, 'status' => 'publish', 'product_type' => 'simple',
            'price' => 100, 'min_price' => 100, 'max_price' => 100, 'quantity' => 5,
            'is_available_product' => false, 'listing_enabled' => false, 'available_at' => null,
            'deleted_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTools(): void
    {
        $this->seed(PlantAtHomeToolsSeeder::class);
    }

    public function test_the_seeder_creates_tools_listable_and_never_redresses_them(): void
    {
        // Admin edited this tool: new price and name, moved to Accessories, image uploaded.
        $edited = $this->tool(['name' => 'Hedge Shears Pro', 'slug' => 'hedge-shears', 'price' => 777, 'min_price' => 777, 'max_price' => 777,
            'image' => json_encode(['original' => 'https://cdn.plantathome.in/x.webp'])]);
        DB::table('category_product')->insert(['product_id' => $edited, 'category_id' => 105]);

        $this->seedTools();
        $this->seedTools(); // a second boot changes nothing

        $json = json_decode(file_get_contents(base_path('packages/marvel/data/tools.json')), true);
        $this->assertSame(count($json), DB::table('products')->where('type_id', 2)->count(), 'one row per tool, no duplicates');

        $new = DB::table('products')->where('slug', 'bypass-pruning-secateurs')->first();
        $this->assertSame('publish', $new->status);
        $this->assertSame(1, (int) $new->is_available_product, 'created listable');
        $this->assertSame(1, (int) $new->listing_enabled);
        $this->assertNotNull($new->available_at);
        $this->assertSame([100], DB::table('category_product')->where('product_id', $new->id)->pluck('category_id')->map(fn ($v) => (int) $v)->all());

        $kept = DB::table('products')->where('id', $edited)->first();
        $this->assertSame('Hedge Shears Pro', $kept->name, 'admin name kept');
        $this->assertEquals(777, (float) $kept->price, 'admin price kept');
        $this->assertStringContainsString('cdn.plantathome.in', (string) $kept->image);
        $this->assertSame([105], DB::table('category_product')->where('product_id', $edited)->pluck('category_id')->map(fn ($v) => (int) $v)->all(), 'admin category kept');
        $this->assertSame(0, (int) $kept->listing_enabled, 'an existing row\'s catalogue flags are the migration\'s job, not the seeder\'s');
    }

    public function test_the_migration_lists_exactly_the_seeded_published_live_tools_and_down_restores(): void
    {
        $listed   = $this->tool(['name' => 'Bypass Pruning Secateurs', 'slug' => 'bypass-pruning-secateurs']);
        $draft    = $this->tool(['name' => 'Watering Can 5L', 'slug' => 'watering-can-5l', 'status' => 'draft']);
        $trashed  = $this->tool(['name' => 'Garden Twine', 'slug' => 'garden-twine-and-ties', 'deleted_at' => now()]);
        $custom   = $this->tool(['name' => 'Custom Tool', 'slug' => 'custom-tool']);
        $already  = $this->tool(['name' => 'Hand Cultivator', 'slug' => 'hand-cultivator', 'is_available_product' => true, 'listing_enabled' => true, 'available_at' => '2026-09-01 00:00:00']);
        $plant    = $this->tool(['name' => 'Bypass Pruning Secateurs', 'slug' => 'bypass-pruning-secateurs', 'type_id' => 1]);

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $flags = fn (int $id) => array_map('intval', (array) DB::table('products')->where('id', $id)->first(['is_available_product', 'listing_enabled']));
        $this->assertSame(['is_available_product' => 1, 'listing_enabled' => 1], $flags($listed));
        $this->assertNotNull(DB::table('products')->where('id', $listed)->value('available_at'));
        foreach ([$draft, $trashed, $custom, $plant] as $id) {
            $this->assertSame(['is_available_product' => 0, 'listing_enabled' => 0], $flags($id), "product {$id} must stay hidden");
        }
        $this->assertSame('2026-09-01 00:00:00', DB::table('products')->where('id', $already)->value('available_at'), 'an existing stamp is kept');
        $this->assertSame([$listed], DB::table('pah_tools_listing_backup')->pluck('product_id')->map(fn ($v) => (int) $v)->all());

        $migration->down();

        $this->assertSame(['is_available_product' => 0, 'listing_enabled' => 0], $flags($listed));
        $this->assertNull(DB::table('products')->where('id', $listed)->value('available_at'));
        $this->assertFalse(Schema::hasTable('pah_tools_listing_backup'));
    }

    public function test_a_database_without_the_tools_type_is_left_alone(): void
    {
        DB::table('types')->where('slug', 'tools')->delete();
        (require base_path(self::MIGRATION))->up();

        $this->assertFalse(Schema::hasTable('pah_tools_listing_backup'));
    }
}
