<?php

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Models\Product;
use Marvel\Http\Controllers\PlantTaxonomyController;
use Marvel\Http\Controllers\ProductController;
use Marvel\Rules\ValidAttributeValues;
use Marvel\Services\AttributeFilter;
use Marvel\Services\AttributeValueWriter;
use Marvel\Services\ProductAttributeSchema;
use Marvel\Services\ServiceAvailabilityService;
use Marvel\Services\ToolsTaxonomyImporter;
use Tests\TestCase;

/**
 * Product attributes for every vertical (Gardening Tools brief 2026-10-14, Phase 4), built on
 * the existing plant_attribute_* layer: applicability through the category tree, typed and
 * validated values, the storefront's `attr` filter, facets, the templates import and the admin
 * endpoints. The plants' two attributes must behave exactly as before.
 *
 * sqlite in-memory; the attribute tables come from their REAL migrations (the original one and
 * 2026_10_15_000200), so the generalisation migration is exercised too.
 *
 * Fixture (Tools): Pruning (root) › Secateurs › Bypass Pruners;  Watering (root);  plus Plants.
 */
class ProductAttributesTest extends TestCase
{
    private int $tools;

    private int $plants;

    private array $c = [];

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
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->string('language')->default('en');
            $t->string('icon')->nullable();
            $t->json('image')->nullable();
            $t->text('details')->nullable();
            $t->unsignedBigInteger('parent')->nullable();
            $t->unsignedBigInteger('type_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_homepage')->default(false);
            $t->integer('display_order')->default(0);
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
        Schema::create('products', function (Blueprint $t) {
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('sku')->nullable();
            $t->string('language')->default('en');
            $t->unsignedBigInteger('type_id')->nullable();
            $t->unsignedBigInteger('manufacturer_id')->nullable();
            $t->unsignedBigInteger('master_product_id')->nullable();
            $t->string('status')->default('publish');
            $t->string('visibility')->default('visibility_public');
            $t->string('product_type')->default('simple');
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('min_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->boolean('is_rental')->default(false);
            $t->boolean('in_stock')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });
        // The facets endpoint reads these alongside the products.
        foreach (['attribute_values' => fn ($t) => [$t->unsignedBigInteger('attribute_id'), $t->string('value'), $t->string('slug')->nullable(), $t->string('meta')->nullable(), $t->integer('sort_order')->default(0)],
                  'attributes' => fn ($t) => [$t->string('slug'), $t->string('name'), $t->string('language')->default('en')],
                  'attribute_product' => fn ($t) => [$t->unsignedBigInteger('attribute_value_id'), $t->unsignedBigInteger('product_id')],
                  'products_meta' => fn ($t) => [$t->unsignedBigInteger('product_id'), $t->string('type')->nullable(), $t->string('key'), $t->text('value')->nullable()],
                  'plant_attributes' => fn ($t) => [$t->unsignedBigInteger('product_id'), $t->string('sunlight')->nullable(), $t->string('water_requirement')->nullable(), $t->string('indoor_outdoor')->nullable(), $t->string('growth_rate')->nullable(), $t->string('difficulty_level')->nullable(), $t->string('height_range')->nullable(), $t->boolean('pet_friendly')->nullable(), $t->boolean('air_purifying')->nullable()],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->bigIncrements('id');
                $columns($t);
                $t->timestamps();
            });
        }
        $this->app->instance(ServiceAvailabilityService::class, new class extends ServiceAvailabilityService {
            public function __construct() {}
            public function allVerticals(): array { return ['plants', 'tools']; }
            public function availableVerticalsForCity(?string $city): array { return ['plants', 'tools']; }
        });

        $this->plants = DB::table('types')->insertGetId(['name' => 'Plants', 'slug' => 'plants']);
        $this->tools = DB::table('types')->insertGetId(['name' => 'Tools', 'slug' => 'tools']);

        // The real attribute migrations: the original system, then its generalisation.
        (require base_path('database/migrations/2026_08_19_200100_create_plant_attribute_system.php'))->up();
        // An existing plant definition, as production has (it must stay on Plants).
        DB::table('plant_attribute_definitions')->insert(['name' => 'Suitable Spaces', 'slug' => 'suitable-spaces', 'type' => 'multi', 'is_facet' => true, 'is_active' => true, 'sort' => 1]);
        (require base_path('database/migrations/2026_10_15_000200_generalize_product_attributes.php'))->up();

        $cat = function (string $slug, ?string $parent, int $type) {
            $this->c[$slug] = DB::table('categories')->insertGetId([
                'name' => ucfirst($slug), 'slug' => $slug, 'type_id' => $type, 'parent' => $parent ? $this->c[$parent] : null,
            ]);
        };
        $cat('pruning', null, $this->tools);
        $cat('secateurs', 'pruning', $this->tools);
        $cat('bypass', 'secateurs', $this->tools);
        $cat('watering', null, $this->tools);
        $cat('indoor', null, $this->plants);
    }

    /** A definition with optional scopes [slug => required] and terms. */
    private function def(string $code, string $type, array $extra = [], array $scopes = [], array $terms = []): int
    {
        $id = DB::table('plant_attribute_definitions')->insertGetId(array_merge([
            'name' => ucwords(str_replace('-', ' ', $code)), 'slug' => $code, 'code' => $code, 'type' => $type,
            'type_id' => $this->tools, 'is_facet' => true, 'is_active' => true, 'sort' => 10,
        ], $extra));
        foreach ($scopes as $slug => $required) {
            DB::table('plant_attribute_scopes')->insert(['definition_id' => $id, 'category_id' => $this->c[$slug], 'is_required' => $required]);
        }
        foreach ($terms as $i => $value) {
            DB::table('plant_attribute_terms')->insert(['definition_id' => $id, 'value' => $value, 'slug' => str($value)->slug()->toString(), 'sort' => $i, 'is_active' => true]);
        }

        return $id;
    }

    private function term(int $definitionId, string $slug): int
    {
        return (int) DB::table('plant_attribute_terms')->where('definition_id', $definitionId)->where('slug', $slug)->value('id');
    }

    private function product(string $slug, string $category, array $extra = []): Product
    {
        $id = DB::table('products')->insertGetId(array_merge(['name' => ucfirst($slug), 'slug' => $slug, 'type_id' => $this->tools, 'price' => 499], $extra));
        DB::table('category_product')->insert(['product_id' => $id, 'category_id' => $this->c[$category]]);

        return Product::query()->findOrFail($id);
    }

    private function writer(): AttributeValueWriter
    {
        return app(AttributeValueWriter::class);
    }

    /* ── applicability ──────────────────────────────────────────────────── */

    public function test_a_definition_scoped_to_a_parent_applies_to_everything_beneath_it(): void
    {
        $capacity = $this->def('cutting-capacity', 'number', ['unit' => 'mm'], ['pruning' => true]);
        $material = $this->def('material', 'multi');                 // whole Tools vertical
        $hose = $this->def('hose-length', 'number', [], ['watering' => false]);
        $this->def('retired', 'text', ['is_active' => false]);

        $schema = app(ProductAttributeSchema::class)->forProduct($this->tools, [$this->c['bypass']])->keyBy('code');

        $this->assertTrue($schema->has('cutting-capacity'), 'inherited from Pruning, two levels up');
        $this->assertTrue((bool) $schema['cutting-capacity']->is_required);
        $this->assertTrue($schema->has('material'), 'unscoped: the whole vertical');
        $this->assertFalse($schema->has('hose-length'), 'scoped to another branch');
        $this->assertFalse($schema->has('retired'));
        $this->assertFalse($schema->has('suitable-spaces'), 'a plant attribute never reaches a tool');
        $this->assertTrue(app(ProductAttributeSchema::class)->forProduct($this->plants, [$this->c['indoor']])->contains('code', 'suitable-spaces'));
    }

    public function test_the_migration_kept_the_plant_attribute_on_plants(): void
    {
        $row = DB::table('plant_attribute_definitions')->where('slug', 'suitable-spaces')->first();

        $this->assertSame('suitable-spaces', $row->code);
        $this->assertSame($this->plants, (int) $row->type_id);
    }

    /* ── validation ─────────────────────────────────────────────────────── */

    public function test_values_are_checked_against_their_attribute(): void
    {
        $mechanism = $this->def('cutting-mechanism', 'single', [], [], ['Bypass', 'Anvil']);
        $capacity = $this->def('cutting-capacity', 'number', ['min_value' => 1, 'max_value' => 60, 'unit' => 'mm']);
        $reach = $this->def('extension-range', 'range');
        $foldable = $this->def('foldable', 'boolean');
        $model = $this->def('model-number', 'text', ['max_length' => 10]);
        $other = $this->def('other', 'single', [], [], ['X']);
        $errors = fn (array $values) => $this->writer()->errors($values, $this->tools, [$this->c['bypass']], false);

        $this->assertSame([], $errors([
            ['definition_id' => $mechanism, 'term_ids' => [$this->term($mechanism, 'bypass')]],
            ['definition_id' => $capacity, 'value' => 25],
            ['definition_id' => $reach, 'value' => 40, 'value_max' => 60],
            ['definition_id' => $foldable, 'value' => false],
            ['definition_id' => $model, 'value' => 'FX-200'],
        ]));
        $this->assertNotEmpty($errors([['definition_id' => $mechanism, 'term_ids' => [$this->term($mechanism, 'bypass'), $this->term($mechanism, 'anvil')]]]), 'single = one value');
        $this->assertNotEmpty($errors([['definition_id' => $mechanism, 'term_ids' => [$this->term($other, 'x')]]]), "another attribute's value");
        $this->assertNotEmpty($errors([['definition_id' => $capacity, 'value' => 'wide']]), 'not a number');
        $this->assertNotEmpty($errors([['definition_id' => $capacity, 'value' => 80]]), 'above the maximum');
        $this->assertNotEmpty($errors([['definition_id' => $reach, 'value' => 60, 'value_max' => 40]]), 'inverted range');
        $this->assertNotEmpty($errors([['definition_id' => $foldable, 'value' => 'maybe']]));
        $this->assertNotEmpty($errors([['definition_id' => $model, 'value' => str_repeat('A', 11)]]), 'too long');
        $this->assertNotEmpty($errors([['definition_id' => 999999, 'value' => 1]]), 'unknown attribute');
    }

    public function test_a_retired_value_stays_on_a_product_that_has_it_but_cannot_be_newly_picked(): void
    {
        $mechanism = $this->def('cutting-mechanism', 'single', [], [], ['Bypass', 'Anvil']);
        $anvil = $this->term($mechanism, 'anvil');
        $kept = $this->product('kept', 'bypass');
        DB::table('plant_attribute_product')->insert(['definition_id' => $mechanism, 'term_id' => $anvil, 'product_id' => $kept->id]);
        DB::table('plant_attribute_terms')->where('id', $anvil)->update(['is_active' => false]);
        $payload = [['definition_id' => $mechanism, 'term_ids' => [$anvil]]];

        $this->assertSame([], $this->writer()->errors($payload, $this->tools, [$this->c['bypass']], false, $kept->id));
        $this->assertNotEmpty($this->writer()->errors($payload, $this->tools, [$this->c['bypass']], false, null));
    }

    public function test_required_attributes_are_enforced_only_when_publishing(): void
    {
        $capacity = $this->def('cutting-capacity', 'number', [], ['pruning' => true]);
        $product = $this->product('secateur', 'bypass');

        $this->assertSame([], $this->writer()->errors([], $this->tools, [$this->c['bypass']], false), 'a draft may be incomplete');
        $this->assertNotEmpty($this->writer()->errors([], $this->tools, [$this->c['bypass']], true));
        $this->assertNotEmpty($this->writer()->errors([['definition_id' => $capacity, 'value' => '']], $this->tools, [$this->c['bypass']], true, $product->id), 'clearing it while publishing');

        DB::table('plant_attribute_product')->insert(['definition_id' => $capacity, 'product_id' => $product->id, 'value_number' => 20]);
        $this->assertSame([], $this->writer()->errors([], $this->tools, [$this->c['bypass']], true, $product->id), 'a stored value counts');
        $this->assertSame([], $this->writer()->errors([], $this->tools, [$this->c['watering']], true), 'not required outside its branch');
    }

    public function test_the_request_rule_wraps_the_writer(): void
    {
        $capacity = $this->def('cutting-capacity', 'number', ['max_value' => 60]);
        $rule = fn (bool $publishing) => new ValidAttributeValues($this->tools, [$this->c['bypass']], $publishing);

        $this->assertTrue(Validator::make(['attribute_values' => [['definition_id' => $capacity, 'value' => 20]]], ['attribute_values' => [$rule(true)]])->passes());
        $v = Validator::make(['attribute_values' => [['definition_id' => $capacity, 'value' => 90]]], ['attribute_values' => [$rule(false)]]);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('at most 60', $v->errors()->first('attribute_values'));
    }

    /* ── writing and reading ────────────────────────────────────────────── */

    public function test_write_replaces_only_the_attributes_sent_and_formats_specifications(): void
    {
        $material = $this->def('material', 'multi', [], [], ['Steel', 'Carbon steel', 'Plastic']);
        $capacity = $this->def('cutting-capacity', 'number', ['unit' => 'mm', 'sort' => 1]);
        $reach = $this->def('extension-range', 'range', ['unit' => 'cm', 'sort' => 2]);
        $foldable = $this->def('foldable', 'boolean', ['sort' => 3]);
        $model = $this->def('model-number', 'text', ['sort' => 4]);
        $product = $this->product('secateur', 'bypass');
        $before = (int) Cache::get('products:ver', 1);

        $this->writer()->write($product, [
            ['definition_id' => $material, 'term_ids' => [$this->term($material, 'steel'), $this->term($material, 'carbon-steel')]],
            ['definition_id' => $capacity, 'value' => '22.5'],
            ['definition_id' => $reach, 'value' => 40, 'value_max' => 60],
            ['definition_id' => $foldable, 'value' => true],
            ['definition_id' => $model, 'value' => ' FX-200 '],
        ]);
        $this->writer()->write($product, [['definition_id' => $foldable, 'value' => null]]);   // clears only this one

        $specs = collect($this->writer()->specifications($product->id))->keyBy('code');
        $this->assertSame(['22.5 mm'], $specs['cutting-capacity']['values']);
        $this->assertSame(['40 – 60 cm'], $specs['extension-range']['values']);
        $this->assertSame(['FX-200'], $specs['model-number']['values']);
        $this->assertEqualsCanonicalizing(['Steel', 'Carbon steel'], $specs['material']['values']);
        $this->assertFalse($specs->has('foldable'), 'cleared');
        $this->assertGreaterThan($before, (int) Cache::get('products:ver', 1), 'list, facet and product caches bumped');

        $current = collect($this->writer()->current($product->id))->keyBy('definition_id');
        $this->assertSame(22.5, $current[$capacity]['value']);
        $this->assertSame(60.0, $current[$reach]['value_max']);
        $this->assertCount(2, $current[$material]['term_ids']);
    }

    /* ── the storefront's attr filter ───────────────────────────────────── */

    public function test_attr_is_or_within_an_attribute_and_across_attributes(): void
    {
        $material = $this->def('material', 'multi', [], [], ['Steel', 'Plastic']);
        $capacity = $this->def('capacity', 'number');
        $reach = $this->def('extension-range', 'range');
        $foldable = $this->def('foldable', 'boolean');
        $a = $this->product('a', 'bypass');
        $b = $this->product('b', 'bypass');
        $c = $this->product('c', 'bypass');
        $this->writer()->write($a, [['definition_id' => $material, 'term_ids' => [$this->term($material, 'steel')]], ['definition_id' => $capacity, 'value' => 5], ['definition_id' => $foldable, 'value' => true], ['definition_id' => $reach, 'value' => 40, 'value_max' => 60]]);
        $this->writer()->write($b, [['definition_id' => $material, 'term_ids' => [$this->term($material, 'plastic')]], ['definition_id' => $capacity, 'value' => 9], ['definition_id' => $foldable, 'value' => false]]);
        $this->writer()->write($c, [['definition_id' => $material, 'term_ids' => [$this->term($material, 'plastic')]], ['definition_id' => $capacity, 'value' => 2]]);
        $ids = fn (string $attr) => AttributeFilter::apply(Product::query(), $attr)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$a->id, $b->id, $c->id], $ids('material:steel,plastic'), 'OR within');
        $this->assertSame([$b->id, $c->id], $ids('material:plastic'));
        $this->assertSame([$b->id], $ids('material:plastic|capacity:5-10'), 'AND across');
        $this->assertSame([$a->id, $b->id], $ids('capacity:4-'), 'open-ended window');
        $this->assertSame([$a->id], $ids('foldable:true'));
        $this->assertSame([$a->id], $ids('extension-range:55-80'), 'a range matches when it overlaps');
        $this->assertSame([$a->id, $b->id, $c->id], $ids('no-such-attribute:x'), 'an unknown attribute never empties the page');
    }

    public function test_facets_offer_only_attributes_the_listing_has_with_the_right_param(): void
    {
        $material = $this->def('material', 'multi', [], [], ['Steel', 'Plastic', 'Old']);
        $capacity = $this->def('capacity', 'number', ['unit' => 'L']);
        $foldable = $this->def('foldable', 'boolean');
        $this->def('unused', 'single', [], [], ['Nothing']);
        DB::table('plant_attribute_terms')->where('slug', 'old')->update(['is_active' => false]);
        $a = $this->product('a', 'bypass');
        $b = $this->product('b', 'bypass');
        $this->writer()->write($a, [['definition_id' => $material, 'term_ids' => [$this->term($material, 'steel')]], ['definition_id' => $capacity, 'value' => 5], ['definition_id' => $foldable, 'value' => true]]);
        $this->writer()->write($b, [['definition_id' => $capacity, 'value' => 9]]);
        DB::table('plant_attribute_product')->insert(['definition_id' => $material, 'term_id' => $this->term($material, 'old'), 'product_id' => $b->id]);

        $dynamic = collect($this->getJson('/api/products/filter-facets?type=tools')->assertOk()->json('facets.dynamic'))->keyBy('code');

        $this->assertSame('attr', $dynamic['material']['param']);
        $this->assertSame(['steel'], array_column($dynamic['material']['terms'], 'slug'), 'a retired value is not offered');
        $this->assertSame(['min' => 5, 'max' => 9, 'count' => 2], array_map(fn ($v) => (int) $v, $dynamic['capacity']['range']));
        $this->assertSame('L', $dynamic['capacity']['unit']);
        $this->assertSame(['true' => 1, 'false' => 0], $dynamic['foldable']['options']);
        $this->assertFalse($dynamic->has('unused'), 'never a filter that finds nothing');
    }

    /* ── templates import ───────────────────────────────────────────────── */

    public function test_the_templates_import_creates_once_and_never_re_dresses(): void
    {
        foreach (['hand-gardening-tools', 'watering-tools', 'digging-and-soil-preparation', 'pruning-cutting', 'sprayers-and-application-equipment',
            'power-gardening-tools', 'lawn-and-turf-care-equipment', 'soil-testing-and-garden-measurement', 'safety-and-gardening-accessories',
            'gardening-gloves', 'plant-support-and-training-tools', 'tool-sets'] as $slug) {
            DB::table('categories')->insert(['name' => $slug, 'slug' => $slug, 'type_id' => $this->tools]);
        }
        $importer = app(ToolsTaxonomyImporter::class);

        $first = $importer->importAttributes();
        DB::table('plant_attribute_definitions')->where('code', 'capacity')->update(['name' => 'Volume', 'unit' => 'ml']);
        $second = $importer->importAttributes();

        $this->assertSame(92, $first['definitions']);
        $this->assertSame([], $first['skipped']);
        $this->assertSame(0, $second['definitions'] + $second['scopes'] + $second['terms'], 'a re-run changes nothing');
        $this->assertSame('Volume', DB::table('plant_attribute_definitions')->where('code', 'capacity')->value('name'), 'admin edits survive');
        $blade = DB::table('plant_attribute_definitions')->where('code', 'blade-length')->first();
        $this->assertSame('mm', $blade->unit);
        $this->assertSame(3, DB::table('plant_attribute_scopes')->where('definition_id', $blade->id)->count(), 'one definition, three branches');
        $this->assertSame(4, DB::table('plant_attribute_terms')->where('definition_id', DB::table('plant_attribute_definitions')->where('code', 'power-source')->value('id'))->count());
        $this->assertSame(0, DB::table('plant_attribute_product')->count(), 'no product values are invented');
    }

    /* ── admin endpoints ────────────────────────────────────────────────── */

    private function asAdmin(Request $request): Request
    {
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($p): bool { return (string) $p === 'super_admin'; }
            public function can($p): bool { return false; }
        });

        return $request;
    }

    public function test_the_schema_endpoint_returns_what_a_product_there_should_describe(): void
    {
        $this->def('cutting-capacity', 'number', ['unit' => 'mm'], ['pruning' => true]);
        $this->def('hose-length', 'number', [], ['watering' => false]);

        $data = $this->getJson("/api/products/attribute-schema?type_id={$this->tools}&category_ids={$this->c['bypass']}")->assertOk()->json('data');

        $this->assertSame(['cutting-capacity'], array_column($data, 'code'));
        $this->assertTrue($data[0]['is_required']);
        $this->assertSame('mm', $data[0]['unit']);
    }

    public function test_a_definition_keeps_its_code_and_its_kind_of_value_once_used(): void
    {
        $controller = app(PlantTaxonomyController::class);
        $created = $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', [
            'name' => 'Cutting capacity', 'type' => 'number', 'unit' => 'mm', 'type_id' => $this->tools,
            'scopes' => [['category_id' => $this->c['pruning'], 'is_required' => true]],
        ])))->getData(true);
        $this->assertSame('cutting-capacity', $created['code']);
        $this->assertTrue($created['scopes'][0]['is_required']);

        $dupe = $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', ['name' => 'Other', 'type' => 'text', 'code' => 'cutting-capacity'])));
        $this->assertSame(422, $dupe->getStatusCode());

        DB::table('plant_attribute_product')->insert(['definition_id' => $created['id'], 'product_id' => $this->product('p', 'bypass')->id, 'value_number' => 10]);
        $change = $controller->updateDefinition($this->asAdmin(Request::create('/', 'PUT', ['type' => 'text', 'code' => 'renamed'])), $created['id']);
        $this->assertSame(422, $change->getStatusCode());
        $this->assertSame('cutting-capacity', DB::table('plant_attribute_definitions')->where('id', $created['id'])->value('code'));
    }

    /* ── catalogue duplicates ───────────────────────────────────────────── */

    public function test_the_same_name_from_another_brand_is_not_a_duplicate(): void
    {
        $this->product('bypass-pruners-a', 'bypass', ['name' => 'Bypass Pruners', 'manufacturer_id' => 1]);
        $find = fn (?int $brand) => (new \ReflectionMethod(ProductController::class, 'findCatalogDuplicate'))
            ->invoke(app(ProductController::class), 'Bypass Pruners', $this->tools, $brand);

        $this->assertNotNull($find(1), 'same brand: duplicate');
        $this->assertNull($find(2), 'another brand: allowed');
        $this->assertNull($find(null), 'unbranded vs branded: allowed');
    }
}
