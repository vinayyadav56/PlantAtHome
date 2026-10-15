<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\PlantAttributeDefinition;
use Marvel\Http\Controllers\AttributeController;
use Marvel\Http\Controllers\AttributeValueController;
use Marvel\Http\Controllers\PlantTaxonomyController;
use Marvel\Http\Requests\AttributeRequest;
use Marvel\Http\Requests\AttributeValueRequest;
use Marvel\Services\AttributeValueWriter;
use Marvel\Services\ProductAttributeSchema;
use Marvel\Services\VariantAxisSync;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 5 (product variants): a product-attribute definition flagged as a variant axis keeps ONE
 * global legacy attribute — the variant engine's axis — in step with it, and is never a product
 * specification. The plants' Size master must come through byte-identical.
 *
 * sqlite in-memory; the definition tables come from their real migrations.
 */
final class VariantAxisSyncTest extends TestCase
{
    private const TOOLS = 2;

    private int $pruning;

    private int $size;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sqlite');
        Cache::flush();

        $tables = [
            'types' => fn (Blueprint $t) => [$t->string('name'), $t->string('slug'), $t->string('language')->default('en')],
            'categories' => fn (Blueprint $t) => [
                $t->string('name'), $t->string('slug'), $t->string('language')->default('en'), $t->unsignedBigInteger('parent')->nullable(),
                $t->unsignedBigInteger('type_id'), $t->boolean('is_active')->default(true), $t->integer('display_order')->default(0), $t->softDeletes(),
            ],
            'category_placements' => fn (Blueprint $t) => [$t->unsignedBigInteger('category_id'), $t->unsignedBigInteger('parent_id'), $t->integer('display_order')->default(0)],
            'attributes' => fn (Blueprint $t) => [$t->string('slug'), $t->string('name'), $t->unsignedBigInteger('shop_id')->nullable(), $t->string('language')->default('en')],
            // The production shape, unique index included (2026_09_22_000000).
            'attribute_values' => fn (Blueprint $t) => [
                $t->string('slug'), $t->unsignedBigInteger('attribute_id'), $t->string('value'), $t->string('meta')->nullable(), $t->string('language')->default('en'),
                $t->string('code', 8)->nullable(), $t->integer('sort_order')->default(0), $t->decimal('delivery_charge', 10, 2)->nullable(),
                $t->unique(['attribute_id', 'value', 'language']),
            ],
            'attribute_product' => fn (Blueprint $t) => [$t->unsignedBigInteger('attribute_value_id'), $t->unsignedBigInteger('product_id')],
            'variation_options' => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->string('title'), $t->string('price'), $t->json('options')],
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->bigIncrements('id');
                $columns($t);
                $t->timestamps();
            });
        }
        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants'], ['id' => self::TOOLS, 'name' => 'Tools', 'slug' => 'tools']]);
        (require base_path('database/migrations/2026_08_19_200100_create_plant_attribute_system.php'))->up();
        (require base_path('database/migrations/2026_10_15_000200_generalize_product_attributes.php'))->up();
        $this->pruning = DB::table('categories')->insertGetId(['name' => 'Pruning', 'slug' => 'pruning', 'type_id' => self::TOOLS]);

        // The plants' Size master as production has it.
        $this->size = DB::table('attributes')->insertGetId(['slug' => 'size', 'name' => 'Size', 'language' => 'en']);
        foreach ([['Small', 'S', 100], ['Medium', 'M', 150], ['Large', 'L', 200]] as $i => [$value, $code, $charge]) {
            DB::table('attribute_values')->insert([
                'attribute_id' => $this->size, 'slug' => strtolower($value), 'value' => $value, 'code' => $code,
                'sort_order' => $i + 1, 'delivery_charge' => $charge,
            ]);
        }
    }

    private function admin(array $params = [], string $class = Request::class): Request
    {
        $request = $class::create('/', 'POST', $params);
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($p): bool { return true; }
            public function can($p): bool { return true; }
        });

        return $request;
    }

    private function controller(): PlantTaxonomyController
    {
        return app(PlantTaxonomyController::class);
    }

    /** The status a controller call ends with, returned or thrown. */
    private function statusOf(callable $call): int
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            return $e->status;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
    }

    /** A Tools axis created through the admin endpoints, its options in this order. */
    private function axis(string $name, array $options, array $extra = []): array
    {
        $def = $this->controller()->storeDefinition($this->admin(['name' => $name, 'type' => 'single', 'is_variant_axis' => true, 'type_id' => self::TOOLS] + $extra))->getData(true);
        foreach ($options as $i => $value) {
            $this->controller()->storeTerm($this->admin(['value' => $value, 'sort' => ($i + 1) * 10]), $def['id']);
        }

        return $def;
    }

    private function term(int $definitionId, string $value): int
    {
        return (int) DB::table('plant_attribute_terms')->where('definition_id', $definitionId)->where('value', $value)->value('id');
    }

    /** @return array<string, int> an attribute's values => sort_order, in display order */
    private function values(int $attributeId): array
    {
        return DB::table('attribute_values')->where('attribute_id', $attributeId)->orderBy('sort_order')->orderBy('id')
            ->pluck('sort_order', 'value')->map(fn ($v) => (int) $v)->all();
    }

    public function test_an_axis_keeps_one_global_attribute_with_its_options_in_order(): void
    {
        $def = $this->axis('Capacity', ['5 L', '8 L', '12 L']);
        $this->controller()->reorderTerms($this->admin(['ids' => [$this->term($def['id'], '12 L'), $this->term($def['id'], '5 L'), $this->term($def['id'], '8 L')]]), $def['id']);

        $attributes = DB::table('attributes')->where('slug', 'capacity')->get();
        $this->assertCount(1, $attributes);
        $this->assertSame('Capacity', $attributes[0]->name);
        $this->assertSame('en', $attributes[0]->language);
        $this->assertNull($attributes[0]->shop_id, 'global');
        $this->assertSame(['12 L' => 10, '5 L' => 20, '8 L' => 30], $this->values((int) $attributes[0]->id));
        $this->assertSame((int) $attributes[0]->id, $def['attribute_id'], 'returned on create');
        $listed = collect($this->controller()->definitions(new Request(['include_inactive' => 1]))->getData(true))->firstWhere('code', 'capacity');
        $this->assertSame((int) $attributes[0]->id, $listed['attribute_id']);
    }

    public function test_a_sync_is_idempotent(): void
    {
        $def = $this->axis('Capacity', ['5 L', '8 L']);
        $rows = fn () => [DB::table('attributes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), DB::table('attribute_values')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
        $before = $rows();

        app(VariantAxisSync::class)->sync(PlantAttributeDefinition::find($def['id']));
        app(VariantAxisSync::class)->sync(PlantAttributeDefinition::find($def['id']));

        $this->assertEquals($before, $rows());
    }

    public function test_renames_and_reorders_are_mirrored_while_unused(): void
    {
        $def = $this->axis('Capacity', ['5 L', '8 L']);
        $valueId = (int) DB::table('attribute_values')->where('attribute_id', $def['attribute_id'])->where('value', '5 L')->value('id');

        $this->assertSame(200, $this->statusOf(fn () => $this->controller()->updateTerm($this->admin(['value' => '5 Litre']), $this->term($def['id'], '5 L'))));
        $this->controller()->reorderTerms($this->admin(['ids' => [$this->term($def['id'], '8 L'), $this->term($def['id'], '5 Litre')]]), $def['id']);
        $this->assertSame(200, $this->statusOf(fn () => $this->controller()->updateDefinition($this->admin(['name' => 'Tank capacity']), $def['id'])));

        $this->assertSame(['8 L' => 10, '5 Litre' => 20], $this->values($def['attribute_id']));
        $this->assertSame($valueId, (int) DB::table('attribute_values')->where('value', '5 Litre')->value('id'), 'renamed in place, never re-created');
        $attribute = DB::table('attributes')->find($def['attribute_id']);
        $this->assertSame('Tank capacity', $attribute->name);
        $this->assertSame('capacity', $attribute->slug, 'the slug (= the code) never changes');
        $this->assertSame(2, DB::table('attributes')->count(), 'Size and this one');
    }

    public function test_a_clash_with_an_existing_attribute_or_a_non_single_axis_is_refused(): void
    {
        DB::table('attributes')->insert(['slug' => 'color', 'name' => 'Color', 'language' => 'en']);   // the demo one
        $store = fn (array $payload) => $this->statusOf(fn () => $this->controller()->storeDefinition($this->admin($payload + ['type' => 'single', 'is_variant_axis' => true])));

        $this->assertSame(422, $store(['name' => 'size']), "the plants' Size, whatever the case");
        $this->assertSame(422, $store(['name' => 'Pot size', 'code' => 'size']), 'its slug');
        $this->assertSame(422, $store(['name' => 'COLOR']));
        $this->assertSame(422, $store(['name' => 'Colour', 'type' => 'multi']), 'a variant has one value per axis');
        $this->assertSame(0, DB::table('plant_attribute_definitions')->count(), 'a refused axis keeps nothing');
        $this->assertSame(2, DB::table('attributes')->count());

        $def = $this->axis('Capacity', ['5 L']);
        $this->assertSame(422, $this->statusOf(fn () => $this->controller()->updateDefinition($this->admin(['name' => 'Size']), $def['id'])));
        $this->assertSame('Capacity', DB::table('plant_attribute_definitions')->where('id', $def['id'])->value('name'), 'rolled back');
        $this->assertSame('Capacity', DB::table('attributes')->where('id', $def['attribute_id'])->value('name'));
        $this->assertSame(422, $this->statusOf(fn () => $this->controller()->updateDefinition($this->admin(['type' => 'multi']), $def['id'])));
        $material = $this->controller()->storeDefinition($this->admin(['name' => 'Material', 'type' => 'multi']))->getData(true);
        $this->assertSame(422, $this->statusOf(fn () => $this->controller()->updateDefinition($this->admin(['is_variant_axis' => true]), $material['id'])));
        $this->assertNull($material['attribute_id'], 'an ordinary attribute has no legacy attribute');
    }

    public function test_an_option_or_axis_that_variants_use_is_never_renamed(): void
    {
        $def = $this->axis('Capacity', ['5 L', '8 L', '12 L']);
        $value = fn (string $v) => (int) DB::table('attribute_values')->where('attribute_id', $def['attribute_id'])->where('value', $v)->value('id');
        DB::table('attribute_product')->insert(['attribute_value_id' => $value('5 L'), 'product_id' => 1]);
        // 8 L only in a variant row's text (no product link), in another case.
        DB::table('variation_options')->insert(['product_id' => 2, 'title' => 'Red/8 L', 'price' => '499',
            'options' => json_encode([['name' => 'Colour', 'value' => 'Red'], ['name' => 'capacity', 'value' => '8 l']])]);
        $rename = fn (string $from, string $to) => $this->statusOf(fn () => $this->controller()->updateTerm($this->admin(['value' => $to]), $this->term($def['id'], $from)));

        $this->assertSame(422, $rename('5 L', '5 Litre'), 'a product links it');
        $this->assertSame(422, $rename('8 L', '8 Litre'), 'a variant row stores it');
        $this->assertSame(422, $rename('12 L', '8 l'), 'two options may not read the same');
        $this->assertSame(['5 L' => 10, '8 L' => 20, '12 L' => 30], $this->values($def['attribute_id']));
        $this->assertSame(200, $rename('12 L', '12 Litre'), 'an unused option can be renamed');
        $this->assertSame(200, $this->statusOf(fn () => $this->controller()->updateTerm($this->admin(['sort' => 99, 'is_active' => false]), $this->term($def['id'], '5 L'))), 'a used one re-sorted and retired');
        $this->assertSame(99, $this->values($def['attribute_id'])['5 L'], 'a retired option keeps its value');
        $this->assertSame(422, $this->statusOf(fn () => $this->controller()->updateDefinition($this->admin(['name' => 'Volume']), $def['id'])), 'the axis name is on every variant row too');
        $this->assertSame('Capacity', DB::table('attributes')->where('id', $def['attribute_id'])->value('name'));
    }

    public function test_the_plants_size_attribute_is_untouched(): void
    {
        $snapshot = fn () => [
            DB::table('attributes')->where('id', $this->size)->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('attribute_values')->where('attribute_id', $this->size)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $snapshot();

        $def = $this->axis('Capacity', ['Small', 'Large']);   // the sizes' own texts and slugs
        $this->controller()->updateTerm($this->admin(['value' => 'Smallest']), $this->term($def['id'], 'Small'));
        $this->assertSame(422, $this->statusOf(fn () => $this->axis('Size', ['XL'])));

        $this->assertEquals($before, $snapshot());
        $this->assertSame(['Smallest' => 10, 'Large' => 20], $this->values($def['attribute_id']));
    }

    public function test_an_axis_is_never_a_product_specification(): void
    {
        $this->axis('Capacity', ['5 L'], ['scopes' => [['category_id' => $this->pruning, 'is_required' => true]]]);
        $this->controller()->storeDefinition($this->admin(['name' => 'Material', 'type' => 'multi', 'type_id' => self::TOOLS]));

        $this->assertSame(['material'], app(ProductAttributeSchema::class)->forProduct(self::TOOLS, [$this->pruning])->pluck('code')->all());
        $this->assertSame([], app(AttributeValueWriter::class)->errors([], self::TOOLS, [$this->pruning], true), 'never required on publish');
    }

    public function test_the_legacy_attribute_screens_leave_a_managed_attribute_alone(): void
    {
        $def = $this->axis('Capacity', ['5 L']);
        $attributeId = (int) $def['attribute_id'];
        $valueId = (int) DB::table('attribute_values')->where('attribute_id', $attributeId)->value('id');
        $smallId = (int) DB::table('attribute_values')->where('attribute_id', $this->size)->where('value', 'Small')->value('id');
        $attributes = app(AttributeController::class);
        $values = app(AttributeValueController::class);
        $for = function (Request $request, int $id): Request {
            $request->id = $id;

            return $request;
        };

        $calls = [
            'rename it'        => fn () => $attributes->updateAttribute($for($this->admin(['name' => 'Volume', 'values' => []], AttributeRequest::class), $attributeId)),
            'delete it'        => fn () => $attributes->deleteAttribute($for($this->admin(), $attributeId)),
            'add a value'      => fn () => $values->store($this->admin(['value' => '9 L', 'attribute_id' => $attributeId], AttributeValueRequest::class)),
            'edit a value'     => fn () => $values->updateAttributeValues($for($this->admin(['value' => '5 Litre', 'attribute_id' => $attributeId], AttributeValueRequest::class), $valueId)),
            'move a value in'  => fn () => $values->updateAttributeValues($for($this->admin(['value' => 'Small', 'attribute_id' => $attributeId], AttributeValueRequest::class), $smallId)),
            'delete a value'   => fn () => $values->destroyAttributeValues($for($this->admin(), $valueId)),
        ];
        foreach ($calls as $what => $call) {
            try {
                $call();
                $this->fail("{$what}: went through");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode(), $what);
                $this->assertSame('Managed in Product Attributes', $e->getMessage());
            }
        }
        $this->assertSame('Capacity', DB::table('attributes')->where('id', $attributeId)->value('name'));
        $this->assertSame(['5 L' => 10], $this->values($attributeId));
        $this->assertSame($this->size, (int) DB::table('attribute_values')->where('id', $smallId)->value('attribute_id'));

        // Every other attribute keeps the legacy screens.
        $color = DB::table('attributes')->insertGetId(['slug' => 'color', 'name' => 'Color', 'language' => 'en']);
        $attributes->deleteAttribute($for($this->admin(), $color));
        $this->assertNull(DB::table('attributes')->find($color));
    }
}
