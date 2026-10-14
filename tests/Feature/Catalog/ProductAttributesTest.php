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
        (require base_path('database/migrations/2026_10_15_000300_attribute_text_values_as_text.php'))->up();

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
        $capacity = $this->def('cutting-capacity', 'number', ['max_value' => 60], ['pruning' => true]);

        $this->assertTrue(Validator::make(['attribute_values' => [['definition_id' => $capacity, 'value' => 20]]], ['attribute_values' => [new ValidAttributeValues()]])->passes());
        $this->assertTrue(Validator::make(['attribute_values' => []], ['attribute_values' => [new ValidAttributeValues()]])->passes(), 'required ones are judged later, on the stored status');
        $v = Validator::make(['attribute_values' => [['definition_id' => $capacity, 'value' => 90]]], ['attribute_values' => [new ValidAttributeValues()]]);
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

    /** The categories the templates are scoped to. */
    private function templateCategories(): void
    {
        foreach (['hand-gardening-tools', 'watering-tools', 'digging-and-soil-preparation', 'pruning-cutting', 'sprayers-and-application-equipment',
            'power-gardening-tools', 'lawn-and-turf-care-equipment', 'soil-testing-and-garden-measurement', 'safety-and-gardening-accessories',
            'gardening-gloves', 'plant-support-and-training-tools', 'tool-sets'] as $slug) {
            DB::table('categories')->insert(['name' => $slug, 'slug' => $slug, 'type_id' => $this->tools]);
        }
    }

    public function test_the_templates_import_creates_once_and_never_re_dresses(): void
    {
        $this->templateCategories();
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

    public function test_a_re_import_keeps_the_scopes_an_admin_removed(): void
    {
        $this->templateCategories();
        $importer = app(ToolsTaxonomyImporter::class);
        $importer->importAttributes();
        $blade = (int) DB::table('plant_attribute_definitions')->where('code', 'blade-length')->value('id');
        DB::table('plant_attribute_scopes')->where('definition_id', $blade)->delete();   // the admin made it cover all of Tools

        $again = $importer->importAttributes();

        $this->assertSame(0, $again['scopes']);
        $this->assertSame(0, DB::table('plant_attribute_scopes')->where('definition_id', $blade)->count());
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

    /* ── review fixes (2026-10-14) ──────────────────────────────────────── */

    public function test_long_text_values_are_kept_whole(): void
    {
        $safety = $this->def('safety-instructions', 'text');
        $product = $this->product('secateur', 'bypass');
        $long = trim(str_repeat('Keep the blade closed when not in use. ', 6));   // past the old 191 characters

        $this->assertSame('text', Schema::getColumnType('plant_attribute_product', 'value_text'), 'production MySQL cut VARCHAR(191) silently');
        $this->assertSame([], $this->writer()->errors([['definition_id' => $safety, 'value' => $long]], $this->tools, [$this->c['bypass']], false));
        $this->writer()->write($product, [['definition_id' => $safety, 'value' => $long]]);
        $this->assertSame([$long], collect($this->writer()->specifications($product->id))->firstWhere('code', 'safety-instructions')['values']);
    }

    public function test_a_code_made_from_a_long_name_fits_its_column_and_stays_unique(): void
    {
        $controller = app(PlantTaxonomyController::class);
        $name = 'Maximum recommended operating pressure for the attached spray lance and its replaceable nozzle tips';
        $first = $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', ['name' => $name, 'type' => 'number'])))->getData(true);
        $second = $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', ['name' => "{$name} (two)", 'type' => 'number'])))->getData(true);

        $this->assertLessThanOrEqual(64, strlen($first['code']));
        $this->assertLessThanOrEqual(64, strlen($second['code']));
        $this->assertSame("{$first['code']}-2", $second['code'], 'the same first 60 characters: suffixed, not a 500');
    }

    public function test_the_plant_terms_panel_keeps_typed_specifications(): void
    {
        $spaces = (int) DB::table('plant_attribute_definitions')->where('code', 'suitable-spaces')->value('id');
        DB::table('plant_attribute_terms')->insert(['definition_id' => $spaces, 'value' => 'Balcony', 'slug' => 'balcony', 'sort' => 1, 'is_active' => true]);
        $capacity = $this->def('cutting-capacity', 'number');
        $product = $this->product('secateur', 'bypass');
        $this->writer()->write($product, [['definition_id' => $capacity, 'value' => 20]]);
        $before = (int) Cache::get('products:ver', 1);

        app(PlantTaxonomyController::class)->syncProductTerms($this->asAdmin(Request::create('/', 'PUT', ['term_ids' => [$this->term($spaces, 'balcony')]])), $product->id);

        $current = collect($this->writer()->current($product->id))->keyBy('definition_id');
        $this->assertSame(20.0, $current[$capacity]['value'], 'the typed value survives an options-only save');
        $this->assertSame([$this->term($spaces, 'balcony')], $current[$spaces]['term_ids']);
        $this->assertGreaterThan($before, (int) Cache::get('products:ver', 1));
    }

    public function test_plant_option_facets_keep_the_terms_param_and_other_kinds_use_attr(): void
    {
        $spaces = (int) DB::table('plant_attribute_definitions')->where('code', 'suitable-spaces')->value('id');
        DB::table('plant_attribute_terms')->insert(['definition_id' => $spaces, 'value' => 'Balcony', 'slug' => 'balcony', 'sort' => 1, 'is_active' => true]);
        $height = $this->def('mature-height', 'number', ['type_id' => $this->plants, 'unit' => 'cm']);
        $plant = $this->product('monstera', 'indoor', ['type_id' => $this->plants]);
        $this->writer()->write($plant, [['definition_id' => $spaces, 'term_ids' => [$this->term($spaces, 'balcony')]], ['definition_id' => $height, 'value' => 90]]);

        $dynamic = collect($this->getJson('/api/products/filter-facets?type=plants')->assertOk()->json('facets.dynamic'))->keyBy('code');

        $this->assertSame('terms', $dynamic['suitable-spaces']['param'], 'unchanged for the plants');
        $this->assertSame('attr', $dynamic['mature-height']['param'], 'terms cannot filter a number');
    }

    /** ProductController's required check: null when the save may go ahead, else the status code. */
    private function requiredCheck(array $input, ?Product $product, string $storedStatus): ?int
    {
        try {
            (new \ReflectionMethod(ProductController::class, 'assertRequiredSpecifications'))
                ->invoke(app(ProductController::class), Request::create('/', 'PUT', $input), $product, $storedStatus);

            return null;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse()->getStatusCode();
        }
    }

    public function test_required_specifications_are_judged_on_the_status_the_save_stores(): void
    {
        $capacity = $this->def('cutting-capacity', 'number', [], ['pruning' => true]);
        $draft = $this->product('draft-secateur', 'bypass', ['status' => 'draft']);
        $live = $this->product('live-hose', 'watering', ['status' => 'publish']);

        $this->assertSame(422, $this->requiredCheck(['status' => 'publish'], $draft, 'publish'), 'publishing without sending them');
        $this->assertSame(422, $this->requiredCheck(['status' => 'under_review'], $draft, 'publish'), 'the stored status decides (product review turns a proposal into publish)');
        $this->assertNull($this->requiredCheck(['status' => 'publish'], null, 'under_review'), "a vendor's proposal may be incomplete");
        $this->assertSame(422, $this->requiredCheck(['type_id' => $this->tools, 'categories' => [$this->c['bypass']]], null, 'publish'), 'a create that publishes');
        $this->assertNull($this->requiredCheck(['quantity' => 3], $live, 'publish'), 'an unrelated partial update of a live product');
        $this->assertSame(422, $this->requiredCheck(['categories' => [$this->c['bypass']]], $live, 'publish'), 'moving a live product into a category that requires it');
        $this->assertNull($this->requiredCheck(['categories' => [$this->c['bypass']], 'attribute_values' => [['definition_id' => $capacity, 'value' => 20]]], $live, 'publish'), 'sent with the save');
    }

    public function test_review_approval_refuses_a_product_missing_a_required_specification(): void
    {
        $this->def('cutting-capacity', 'number', [], ['pruning' => true]);
        $product = $this->product('proposal', 'bypass', ['status' => 'under_review']);

        try {
            app(ProductController::class)->updateStatus($this->asAdmin(Request::create('/', 'POST', ['id' => $product->id, 'status' => 'publish'])));
            $this->fail('approval went ahead without the required specification');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $response = $e->getResponse();
        }

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Cutting Capacity is required', $response->getData(true)['message']);
        $this->assertSame('under_review', DB::table('products')->where('id', $product->id)->value('status'));
    }

    public function test_malformed_values_are_refused_before_anything_is_saved(): void
    {
        $model = $this->def('model-number', 'text');
        $material = $this->def('material', 'multi', [], [], ['Steel']);
        $errors = fn (array $values) => $this->writer()->errors($values, $this->tools, [$this->c['bypass']], false);

        $this->assertNotEmpty($errors([['definition_id' => $model, 'value' => ['x']]]), 'a list where text belongs');
        $this->assertNotEmpty($errors([['definition_id' => $material, 'term_ids' => [[$this->term($material, 'steel')]]]]), 'a nested option id');
        $this->assertNotEmpty($errors([['definition_id' => [$model], 'value' => 'x']]), 'a list for the attribute');
        $this->assertNotEmpty($errors(['not an entry']));
        $this->assertFalse((new ValidAttributeValues())->passes('attribute_values', [['definition_id' => $model, 'value' => ['x']]]), 'refused, not waved through');
    }

    public function test_a_value_under_a_deleted_attribute_can_be_cleared_but_not_set(): void
    {
        $old = $this->def('old-spec', 'number');
        $product = $this->product('saw', 'bypass');
        $this->writer()->write($product, [['definition_id' => $old, 'value' => 5]]);
        DB::table('plant_attribute_definitions')->where('id', $old)->update(['deleted_at' => now()]);   // manual database work
        $errors = fn (array $entry) => $this->writer()->errors([$entry], $this->tools, [$this->c['bypass']], false, $product->id);

        $this->assertNotEmpty($errors(['definition_id' => $old, 'value' => 7]));
        $this->assertSame([], $errors(['definition_id' => $old, 'value' => null]), "the form's Remove");
        $this->writer()->write($product, [['definition_id' => $old, 'value' => null]]);
        $this->assertSame(0, DB::table('plant_attribute_product')->where('product_id', $product->id)->count());
    }

    public function test_yes_no_values_read_back_as_booleans(): void
    {
        $foldable = $this->def('foldable', 'boolean');
        $product = $this->product('saw', 'bypass');
        $value = fn () => collect($this->writer()->current($product->id))->firstWhere('definition_id', $foldable)['value'];

        $this->writer()->write($product, [['definition_id' => $foldable, 'value' => true]]);
        $this->assertTrue($value());
        $this->writer()->write($product, [['definition_id' => $foldable, 'value' => false]]);
        $this->assertFalse($value());
    }

    public function test_a_scoped_category_cannot_be_deleted_and_a_lost_scope_never_widens_the_attribute(): void
    {
        $this->def('hose-length', 'number', [], ['watering' => false]);

        $response = app(\Marvel\Http\Controllers\CategoryController::class)->destroy($this->c['watering']);
        $this->assertSame(422, $response->getStatusCode(), 'an attribute is scoped to it');

        DB::table('categories')->where('id', $this->c['watering'])->delete();   // gone some other way
        $this->assertFalse(app(ProductAttributeSchema::class)->forProduct($this->tools, [$this->c['bypass']])->contains('code', 'hose-length'));
        $listed = collect($this->getJson("/api/plant-attributes?type=tools&include_inactive=1")->assertOk()->json())->firstWhere('code', 'hose-length');
        $this->assertSame("Deleted category #{$this->c['watering']}", $listed['scopes'][0]['name'] ?? null, 'the admin sees the scope that still limits it');
    }

    public function test_stated_specifications_import_create_only(): void
    {
        $mechanism = $this->def('cutting-mechanism', 'single', [], ['pruning' => false], ['Bypass', 'Anvil']);
        $capacity = $this->def('cutting-capacity', 'number', ['unit' => 'mm'], ['pruning' => false]);
        $blade = $this->def('blade-material', 'single');
        $this->def('hose-length', 'number', ['unit' => 'm'], ['watering' => false]);
        $secateur = $this->product('secateur', 'bypass');
        $hose = $this->product('hose', 'watering');
        $this->writer()->write($secateur, [['definition_id' => $capacity, 'value' => 18]]);   // an admin's entry
        $dir = sys_get_temp_dir() . '/specs-' . uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/tools-specs.json", json_encode([
            'options'  => ['blade-material' => ['Carbon steel']],
            'products' => [
                'secateur' => [
                    ['code' => 'cutting-mechanism', 'value' => 'Bypass', 'quote' => 'bypass secateurs'],
                    ['code' => 'cutting-capacity', 'value' => 20, 'quote' => 'up to 20mm'],
                    ['code' => 'blade-material', 'value' => 'Carbon steel', 'quote' => 'carbon-steel'],
                    ['code' => 'hose-length', 'value' => 5, 'quote' => 'n/a'],
                ],
                'hose'  => [['code' => 'hose-length', 'value' => 15, 'quote' => '15-metre']],
                'ghost' => [['code' => 'hose-length', 'value' => 1, 'quote' => 'n/a']],
            ],
        ]));
        $importer = new ToolsTaxonomyImporter("{$dir}/tools-taxonomy.json");

        $dry = $importer->importSpecs(true);
        $this->assertSame(3, $dry['values']);
        $this->assertSame(1, DB::table('plant_attribute_product')->count(), 'a dry run writes nothing');

        $first = $importer->importSpecs();
        $again = $importer->importSpecs();

        $values = collect($this->writer()->specifications($secateur->id))->pluck('values', 'code');
        $this->assertSame(['Bypass'], $values['cutting-mechanism']);
        $this->assertSame(['18 mm'], $values['cutting-capacity'], "the admin's value stays");
        $this->assertSame(['Carbon steel'], $values['blade-material']);
        $this->assertSame(['15 m'], collect($this->writer()->specifications($hose->id))->pluck('values', 'code')['hose-length']);
        $this->assertSame(1, $first['options']);
        $this->assertSame([0, 0, 4], [$again['values'], $again['options'], $again['unchanged']], 'a re-run changes nothing');
        $this->assertCount(2, $first['skipped'], 'a fact outside its categories and an unknown product are reported, never forced');
        $this->assertSame(1, DB::table('plant_attribute_terms')->where('definition_id', $blade)->count());
        $this->assertSame($mechanism, (int) DB::table('plant_attribute_product')->where('product_id', $secateur->id)->whereNotNull('term_id')->where('definition_id', $mechanism)->value('definition_id'));
    }

    public function test_a_template_waits_until_all_its_categories_exist(): void
    {
        $this->templateCategories();
        DB::table('categories')->where('slug', 'pruning-cutting')->delete();
        $importer = app(ToolsTaxonomyImporter::class);

        $first = $importer->importAttributes();
        $this->assertNull(DB::table('plant_attribute_definitions')->where('code', 'cutting-capacity')->value('id'), 'never created unscoped');
        $this->assertNotEmpty(array_filter($first['skipped'], fn ($line) => str_starts_with($line, 'cutting-capacity:')));

        DB::table('categories')->insert(['name' => 'pruning-cutting', 'slug' => 'pruning-cutting', 'type_id' => $this->tools]);
        $importer->importAttributes();
        $id = DB::table('plant_attribute_definitions')->where('code', 'cutting-capacity')->value('id');
        $this->assertSame(1, DB::table('plant_attribute_scopes')->where('definition_id', $id)->count(), 'created later, with its scope');
    }

    public function test_the_plants_seeder_sets_code_and_vertical_and_never_re_dresses(): void
    {
        DB::table('plant_attribute_definitions')->where('slug', 'suitable-spaces')->update(['type_id' => null]);   // the admin chose "every vertical"

        (new \Marvel\Database\Seeders\PlantAttributeDefinitionSeeder())->run();

        $this->assertNull(DB::table('plant_attribute_definitions')->where('slug', 'suitable-spaces')->value('type_id'));
        $special = DB::table('plant_attribute_definitions')->where('slug', 'special-characteristics')->first();
        $this->assertSame('special-characteristics', $special->code);
        $this->assertSame($this->plants, (int) $special->type_id);
    }

    public function test_deactivating_an_attribute_and_reordering_options_refresh_product_caches(): void
    {
        $material = $this->def('material', 'multi', [], [], ['Steel', 'Plastic']);
        $controller = app(PlantTaxonomyController::class);
        $version = fn () => (int) Cache::get('products:ver', 1);

        $before = $version();
        $controller->reorderTerms($this->asAdmin(Request::create('/', 'POST', ['ids' => [$this->term($material, 'plastic'), $this->term($material, 'steel')]])), $material);
        $this->assertGreaterThan($before, $version());
        $before = $version();
        $controller->destroyDefinition($this->asAdmin(Request::create('/', 'DELETE')), $material);
        $this->assertGreaterThan($before, $version());
    }

    public function test_numbers_and_names_fit_their_columns(): void
    {
        $capacity = $this->def('capacity', 'number');
        $this->assertNotEmpty($this->writer()->errors([['definition_id' => $capacity, 'value' => 25000000000]], $this->tools, [], false), 'DECIMAL(14,4) would clip it');

        $controller = app(PlantTaxonomyController::class);
        try {
            $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', ['name' => 'Huge', 'type' => 'number', 'max_value' => 1e11])));
            $this->fail('a limit wider than the column was accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('max_value', $e->errors());
        }

        $name = str_repeat('a', 191);
        $first = $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', ['name' => $name, 'type' => 'text'])))->getData(true);
        $second = $controller->storeDefinition($this->asAdmin(Request::create('/', 'POST', ['name' => $name, 'type' => 'text'])))->getData(true);
        $this->assertLessThanOrEqual(191, strlen($second['slug']));
        $this->assertNotSame($first['slug'], $second['slug']);
    }

    public function test_an_image_less_product_stays_editable(): void
    {
        $product = $this->product('saw', 'bypass');
        $request = \Marvel\Http\Requests\ProductUpdateRequest::create("/api/products/{$product->id}", 'PUT', []);
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('PUT', 'api/products/{product}', []))->bind($request));
        $rules = array_intersect_key($request->rules(), array_flip(['image', 'gallery', 'video']));

        $this->assertTrue(Validator::make(['image' => null, 'gallery' => null, 'video' => null], $rules)->passes(), 'the seeded tools have no image: their specifications must be savable');
        $this->assertTrue(Validator::make(['image' => 'x'], $rules)->fails());
    }

    public function test_array_attr_and_text_params_do_not_break_the_listing(): void
    {
        // What the listing reads besides the fixture's tables.
        foreach (['reviews' => fn ($t) => [$t->unsignedBigInteger('product_id'), $t->integer('rating')->default(5), $t->softDeletes()],
                  'product_inclusions' => fn ($t) => [$t->unsignedBigInteger('parent_id'), $t->unsignedBigInteger('child_id'), $t->integer('quantity')->default(1), $t->integer('sort_order')->default(0), $t->string('relation')->default('bundle')],
                  'availabilities' => fn ($t) => [$t->unsignedBigInteger('product_id'), $t->string('bookable_type')->nullable(), $t->unsignedBigInteger('bookable_id')->nullable(), $t->date('from')->nullable(), $t->date('to')->nullable()],
                  'tags' => fn ($t) => [$t->string('name'), $t->string('slug')],
                  'product_tag' => fn ($t) => [$t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('tag_id')],
                  'shops' => fn ($t) => [$t->string('name')->nullable(), $t->string('slug')->nullable(), $t->unsignedBigInteger('owner_id')->nullable(), $t->boolean('is_active')->default(true)],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->bigIncrements('id');
                $columns($t);
                $t->timestamps();
            });
        }
        $this->product('saw', 'bypass');

        $this->getJson('/api/products?attr[]=x&text[]=y&limit=5')->assertOk();
    }

    public function test_without_a_plants_vertical_no_facet_uses_the_terms_param(): void
    {
        DB::table('types')->where('slug', 'plants')->delete();
        $everywhere = $this->def('finish', 'multi', ['type_id' => null], [], ['Matte']);
        $this->writer()->write($this->product('saw', 'bypass'), [['definition_id' => $everywhere, 'term_ids' => [$this->term($everywhere, 'matte')]]]);

        $dynamic = collect($this->getJson('/api/products/filter-facets?type=tools')->assertOk()->json('facets.dynamic'))->keyBy('code');

        $this->assertSame('attr', $dynamic['finish']['param']);
    }
}
