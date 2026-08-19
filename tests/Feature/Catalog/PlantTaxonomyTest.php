<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\PlantAttributeDefinition;
use Marvel\Database\Models\PlantAttributeTerm;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Seeders\PlantAttributeDefinitionSeeder;
use Marvel\Database\Seeders\PlantTaxonomySeeder;
use Marvel\Http\Controllers\PlantTaxonomyController;
use Marvel\Services\VendorInventoryWriter;
use Tests\TestCase;

/**
 * The taxonomy layer: category restructure (in place, URLs + product links preserved),
 * characteristic-categories folded into plant facts, admin-extensible attributes, varieties
 * as linked products, and the vendor lockdown that keeps the master catalog admin-owned.
 */
final class PlantTaxonomyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        VendorProductPrice::resetReviewStatics();

        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->text('details')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->unsignedBigInteger('parent')->nullable();
            $t->string('language')->default('en');
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_homepage')->default(false);
            $t->integer('display_order')->default(0);
            $t->string('seo_title')->nullable();
            $t->text('seo_description')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('sku')->nullable();
            $t->json('image')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('master_product_id')->nullable();
            $t->string('variety_name')->nullable();
            $t->boolean('is_featured')->default(false);
            $t->boolean('is_premium')->default(false);
            $t->string('product_type')->default('simple');
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('min_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->string('unit')->nullable();
            $t->integer('quantity')->default(0);
            $t->text('description')->nullable();
            $t->string('status')->default('publish');
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->default('null');
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('plant_attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('scientific_name')->nullable();
            $t->string('hindi_name')->nullable();
            $t->json('common_names')->nullable();
            $t->boolean('pet_friendly')->nullable();
            $t->boolean('air_purifying')->nullable();
            $t->string('sunlight')->nullable();
            $t->timestamps();
        });
        Schema::create('plant_attribute_definitions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('type')->default('multi');
            $t->boolean('is_facet')->default(true);
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('plant_attribute_terms', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('definition_id');
            $t->string('value');
            $t->string('slug');
            $t->integer('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('plant_attribute_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('definition_id');
            $t->unsignedBigInteger('term_id')->nullable();
            $t->unsignedBigInteger('product_id');
            $t->string('value_text')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->string('period_type')->default('monthly');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->decimal('vendor_selling_price')->nullable();
            $t->decimal('cost_price')->nullable();
            $t->integer('stock_qty')->default(0);
            $t->integer('reserved_qty')->default(0);
            $t->boolean('track_stock')->default(false);
            $t->string('fulfillment_mode')->nullable();
            $t->integer('moq')->nullable();
            $t->integer('lead_time_days')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->string('dedupe_key')->nullable()->unique();
            $t->boolean('is_available')->default(true);
            $t->string('source')->nullable();
            $t->string('review_status')->default('pending_review');
            $t->text('review_comment')->nullable();
            $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('plant_collections', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->json('image')->nullable();
            $t->json('rules');
            $t->string('seo_title')->nullable();
            $t->text('seo_description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('approval_status')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_service_areas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('city')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city')->nullable();
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
            $t->decimal('min_price')->nullable();
            $t->decimal('display_price')->nullable();
            $t->integer('stock')->nullable();
            $t->integer('stock_override')->nullable();
            $t->integer('vendor_count')->default(0);
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('title');
            $t->string('sku')->nullable();
            $t->decimal('price')->nullable();
            $t->json('details')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_inventory_reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('vendor_product_price_id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->string('previous_status')->nullable();
            $t->string('new_status');
            $t->string('action');
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => '{}', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants', 'created_at' => now(), 'updated_at' => now()]]);
        // The live shape: two real classifications + two characteristic-categories.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Indoor', 'slug' => 'indoor', 'type_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Succulents & Cacti', 'slug' => 'succulents-cacti', 'type_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Pet Friendly', 'slug' => 'pet-friendly', 'type_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Medicinal', 'slug' => 'medicinal', 'type_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Snake Plant', 'slug' => 'snake-plant', 'type_id' => 1, 'shop_id' => 12, 'price' => 499, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Tulsi', 'slug' => 'tulsi', 'type_id' => 1, 'shop_id' => 12, 'price' => 199, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Archived Fern', 'slug' => 'archived-fern', 'type_id' => 1, 'shop_id' => 12, 'price' => 299, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('category_product')->insert([
            ['category_id' => 1, 'product_id' => 1],
            ['category_id' => 3, 'product_id' => 1], // Snake Plant is in "Pet Friendly"
            ['category_id' => 4, 'product_id' => 2], // Tulsi is in "Medicinal"
        ]);
        DB::table('plant_attributes')->insert([
            ['product_id' => 1, 'scientific_name' => 'Dracaena trifasciata', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function adminRequest(array $params = []): Request
    {
        $r = new Request($params);
        $r->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($p): bool { return true; }
            public function can($p): bool { return true; }
        });
        return $r;
    }

    public function test_taxonomy_seeder_restructures_in_place_and_preserves_urls(): void
    {
        (new PlantAttributeDefinitionSeeder())->run();
        (new PlantTaxonomySeeder())->run();

        // Existing classification kept its slug (its /c/indoor URL still resolves) and its links.
        $indoor = DB::table('categories')->where('slug', 'indoor')->first();
        $this->assertSame('Indoor Plants', $indoor->name, 'renamed in place, not recreated');
        $this->assertSame(1, (int) $indoor->id, 'same row — links and URLs survive');
        $this->assertSame(1, DB::table('category_product')->where('category_id', 1)->count());

        // The full classification tree exists.
        $this->assertSame(15, DB::table('categories')->where('type_id', 1)->where('is_active', true)->count());
        foreach (['trees', 'bonsai', 'rare-exotic', 'vegetable-plants'] as $slug) {
            $this->assertTrue(DB::table('categories')->where('slug', $slug)->exists(), "missing {$slug}");
        }
    }

    public function test_characteristic_categories_become_plant_facts_not_categories(): void
    {
        (new PlantAttributeDefinitionSeeder())->run();
        (new PlantTaxonomySeeder())->run();

        // "Pet Friendly" had a wide column already → the fact moved onto the plant.
        $this->assertTrue((bool) DB::table('plant_attributes')->where('product_id', 1)->value('pet_friendly'));
        // "Medicinal" had none → it became a reusable Special Characteristics term.
        $termId = DB::table('plant_attribute_terms')->where('slug', 'medicinal')->value('id');
        $this->assertNotNull($termId);
        $this->assertTrue(DB::table('plant_attribute_product')->where('product_id', 2)->where('term_id', $termId)->exists());

        // Both categories are retired — deactivated, never deleted (old links keep resolving).
        foreach (['pet-friendly', 'medicinal'] as $slug) {
            $row = DB::table('categories')->where('slug', $slug)->first();
            $this->assertNotNull($row, "{$slug} must not be deleted");
            $this->assertFalse((bool) $row->is_active);
        }
    }

    public function test_seeders_are_idempotent(): void
    {
        (new PlantAttributeDefinitionSeeder())->run();
        (new PlantTaxonomySeeder())->run();
        $cats = DB::table('categories')->count();
        $terms = DB::table('plant_attribute_terms')->count();
        $links = DB::table('plant_attribute_product')->count();

        (new PlantAttributeDefinitionSeeder())->run();
        (new PlantTaxonomySeeder())->run();

        $this->assertSame($cats, DB::table('categories')->count());
        $this->assertSame($terms, DB::table('plant_attribute_terms')->count());
        $this->assertSame($links, DB::table('plant_attribute_product')->count());
    }

    public function test_admin_can_extend_attributes_without_code_changes(): void
    {
        $c = new PlantTaxonomyController();
        $def = $c->storeDefinition($this->adminRequest(['name' => 'Flower Colour', 'type' => 'multi']))->getData(true);
        $this->assertSame('flower-colour', $def['slug']);

        $term = $c->storeTerm($this->adminRequest(['value' => 'Deep Red']), $def['id'])->getData(true);
        $this->assertSame('deep-red', $term['slug']);

        // Duplicate value refused
        $dup = $c->storeTerm($this->adminRequest(['value' => 'Deep Red']), $def['id']);
        $this->assertSame(422, $dup->getStatusCode());

        // Assignment + read-back through the public definitions endpoint
        $c->syncProductTerms($this->adminRequest(['term_ids' => [$term['id']]]), 1);
        $this->assertTrue(Product::find(1)->plantTerms()->where('plant_attribute_terms.id', $term['id'])->exists());

        $defs = collect($c->definitions(new Request())->getData(true));
        $this->assertTrue($defs->contains(fn ($d) => $d['slug'] === 'flower-colour'));
    }

    public function test_deactivating_a_definition_never_deletes_assignments(): void
    {
        $c = new PlantTaxonomyController();
        $def = $c->storeDefinition($this->adminRequest(['name' => 'Temp Attr', 'type' => 'multi']))->getData(true);
        $term = $c->storeTerm($this->adminRequest(['value' => 'X']), $def['id'])->getData(true);
        $c->syncProductTerms($this->adminRequest(['term_ids' => [$term['id']]]), 1);

        $c->destroyDefinition($this->adminRequest(), $def['id']);

        $this->assertFalse((bool) PlantAttributeDefinition::find($def['id'])->is_active);
        $this->assertTrue(DB::table('plant_attribute_product')->where('term_id', $term['id'])->exists(), 'assignments survive');
    }

    public function test_variety_is_a_linked_product_inheriting_the_master(): void
    {
        (new PlantAttributeDefinitionSeeder())->run();
        $c = new PlantTaxonomyController();
        $c->syncProductTerms($this->adminRequest([
            'term_ids' => [DB::table('plant_attribute_terms')->where('slug', 'fragrant')->value('id')],
        ]), 1);

        $variety = $c->storeVariety($this->adminRequest([
            'master_product_id' => 1,
            'variety_name'      => 'Laurentii',
            'botanical_name'    => "Dracaena trifasciata 'Laurentii'",
        ]))->getData(true);

        $this->assertSame('Snake Plant — Laurentii', $variety['name']);
        $this->assertSame(1, $variety['master_product_id']);
        $this->assertSame('publish', $variety['status']);
        // Inherits classification, botanical record and characteristics from its master.
        $this->assertTrue(DB::table('category_product')->where('product_id', $variety['id'])->where('category_id', 1)->exists());
        $this->assertSame("Dracaena trifasciata 'Laurentii'", DB::table('plant_attributes')->where('product_id', $variety['id'])->value('scientific_name'));
        $this->assertSame(1, DB::table('plant_attribute_product')->where('product_id', $variety['id'])->count());
        // The master lists it, and it does not masquerade as a master itself.
        $this->assertSame(1, Product::find(1)->varieties()->count());
        $this->assertSame(1, Product::find($variety['id'])->masterPlant->id);

        $dup = $c->storeVariety($this->adminRequest(['master_product_id' => 1, 'variety_name' => 'Laurentii']));
        $this->assertSame(422, $dup->getStatusCode());
    }

    public function test_new_listings_are_blocked_on_a_deactivated_master_plant(): void
    {
        VendorProductPrice::$adminActor = false;
        $res = (new VendorInventoryWriter())->writeItems(33, [
            ['product_id' => 3, 'vendor_selling_price' => 350], // product 3 is draft
        ], ['user_id' => 9]);

        $this->assertSame(0, $res['saved']);
        $this->assertStringContainsString('not active in the catalogue', $res['errors'][0]['error']);
        // A live plant is unaffected.
        $ok = (new VendorInventoryWriter())->writeItems(33, [['product_id' => 1, 'vendor_selling_price' => 450]], ['user_id' => 9]);
        $this->assertSame(1, $ok['saved']);
    }

    /** Security: the master catalog is Admin's. A vendor proposes; they never publish. */
    public function test_vendor_cannot_self_publish_a_master_plant(): void
    {
        $rc = new \ReflectionClass(\Marvel\Http\Controllers\ProductController::class);
        $src = file_get_contents($rc->getFileName());

        // The status a vendor sends is IGNORED (not merged-if-absent) — a crafted
        // `status: publish` in the payload must not reach the repository.
        $this->assertMatchesRegularExpression(
            '/if \(!\$isSuperAdmin\) \{\s*\$request->merge\(\[\x27status\x27 => .*UNDER_REVIEW\]\);/s',
            $src,
            'vendor creates must be forced to UNDER_REVIEW regardless of the payload',
        );
        // And a vendor may only manage their proposal while it is still theirs to correct.
        $this->assertStringContainsString('ProductStatus::UNDER_REVIEW,', $src);
    }

    public function test_admin_only_guard_rejects_a_vendor_on_taxonomy_writes(): void
    {
        $vendor = new Request(['name' => 'Sneaky Attribute', 'type' => 'multi']);
        $vendor->setUserResolver(fn () => new class {
            public $id = 9;
            public function hasPermissionTo($p): bool { return false; }
            public function can($p): bool { return false; }
        });

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new PlantTaxonomyController())->storeDefinition($vendor);
    }

    public function test_catalog_summary_counts_masters_and_varieties_separately(): void
    {
        (new PlantTaxonomyController())->storeVariety($this->adminRequest([
            'master_product_id' => 1, 'variety_name' => 'Moonshine',
        ]));
        $s = (new PlantTaxonomyController())->summary($this->adminRequest())->getData(true);

        $this->assertSame(3, $s['total_plants'], 'varieties are not counted as master plants');
        $this->assertSame(1, $s['varieties']);
    }
}
