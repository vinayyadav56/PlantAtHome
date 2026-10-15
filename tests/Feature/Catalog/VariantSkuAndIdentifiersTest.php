<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Marvel\Http\Resources\RelatedProductResource;
use Marvel\Rules\Gtin;
use Marvel\Services\SkuGenerator;
use Tests\TestCase;

/**
 * Product variants (contract 2026-10-15, sections 3 and 7): variant SKUs keep their case, blanks are
 * filled on create AND update as {product SKU}-{suffix} (value codes, else 4 letters/digits per
 * value), a SKU is never used twice, GTIN/MPN are validated, the 2026_10_16_000100 migration only
 * adds the unique SKU index when the data allows it, and a variable related product carries the
 * variant "Add all" puts in the cart.
 *
 * In-memory sqlite; the same fixture as VariationOptionsValidationTest (value ids from 101).
 */
final class VariantSkuAndIdentifiersTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_16_000100_variant_identifiers.php';

    private const SETTING = ['enableEmailForDigitalProduct' => false];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sqlite');

        foreach ([
            'settings'          => fn (Blueprint $t) => [$t->text('options')->nullable(), $t->string('language')->default('en')],
            'types'             => fn (Blueprint $t) => [$t->string('name'), $t->string('slug'), $t->string('language')->default('en')],
            'shops'             => fn (Blueprint $t) => [$t->string('name')],
            'products'          => fn (Blueprint $t) => [
                $t->string('name'), $t->string('slug')->nullable(), $t->string('sku')->nullable(), $t->string('language')->default('en'),
                $t->string('status')->default('publish'), $t->string('product_type')->default('simple'),
                $t->decimal('price')->nullable(), $t->decimal('sale_price')->nullable(), $t->decimal('min_price')->nullable(), $t->decimal('max_price')->nullable(),
                $t->integer('quantity')->default(0), $t->string('unit')->nullable(), $t->boolean('in_stock')->default(true),
                $t->unsignedBigInteger('type_id')->nullable(), $t->unsignedBigInteger('shop_id')->nullable(),
                $t->json('image')->nullable(), $t->json('gallery')->nullable(), $t->softDeletes(),
            ],
            'products_meta'     => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->string('type')->nullable(), $t->string('key'), $t->text('value')->nullable()],
            'product_images'    => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->string('url'), $t->string('thumbnail_url')->nullable(), $t->boolean('in_gallery')->default(true), $t->boolean('is_primary')->default(false), $t->integer('sort_order')->default(0), $t->string('source')->nullable()],
            'categories'        => fn (Blueprint $t) => [$t->string('name'), $t->string('slug'), $t->string('language')->default('en'), $t->softDeletes()],
            'category_product'  => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('category_id')],
            'attributes'        => fn (Blueprint $t) => [$t->string('slug'), $t->string('name'), $t->string('language')->default('en')],
            'attribute_values'  => fn (Blueprint $t) => [$t->unsignedBigInteger('attribute_id'), $t->string('value'), $t->string('slug')->nullable(), $t->string('code', 8)->nullable(), $t->integer('sort_order')->default(0), $t->string('language')->default('en')],
            'attribute_product' => fn (Blueprint $t) => [$t->unsignedBigInteger('attribute_value_id'), $t->unsignedBigInteger('product_id')],
            'variation_options' => fn (Blueprint $t) => [
                $t->string('title'), $t->string('price'), $t->string('sale_price')->nullable(), $t->unsignedBigInteger('quantity')->default(0),
                $t->boolean('is_disable')->default(false), $t->string('sku')->nullable(), $t->json('options'), $t->unsignedBigInteger('product_id'),
                $t->json('image')->nullable(), $t->boolean('is_digital')->default(false), $t->string('language')->default('en'),
            ],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->bigIncrements('id');
                $columns($t);
                $t->timestamps();
            });
        }

        DB::table('settings')->insert(['options' => '{}', 'language' => 'en']);
        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants'], ['id' => 2, 'name' => 'Tools', 'slug' => 'tools']]);
        DB::table('shops')->insert(['id' => 1, 'name' => 'PlantAtHome']);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Garden', 'slug' => 'garden']);
        DB::table('attributes')->insert([
            ['id' => 10, 'slug' => 'size', 'name' => 'Size'],
            ['id' => 11, 'slug' => 'colour', 'name' => 'Colour'],
            ['id' => 12, 'slug' => 'capacity', 'name' => 'Capacity'],
        ]);
        DB::table('attribute_values')->insert([
            ['id' => 101, 'attribute_id' => 10, 'value' => 'Small', 'code' => 'S', 'sort_order' => 1],
            ['id' => 102, 'attribute_id' => 10, 'value' => 'Medium', 'code' => 'M', 'sort_order' => 2],
            ['id' => 103, 'attribute_id' => 10, 'value' => 'Large', 'code' => 'L', 'sort_order' => 3],
            ['id' => 111, 'attribute_id' => 11, 'value' => 'Red', 'code' => null, 'sort_order' => 1],
            ['id' => 112, 'attribute_id' => 11, 'value' => 'Blue', 'code' => null, 'sort_order' => 2],
            ['id' => 113, 'attribute_id' => 11, 'value' => 'Green', 'code' => null, 'sort_order' => 3],
            ['id' => 121, 'attribute_id' => 12, 'value' => '5 L', 'code' => null, 'sort_order' => 1],
            ['id' => 122, 'attribute_id' => 12, 'value' => '8 L', 'code' => null, 'sort_order' => 2],
            ['id' => 123, 'attribute_id' => 12, 'value' => '50 L', 'code' => null, 'sort_order' => 3],
        ]);

        $this->product(1, 'Yucca', 1, null, [101, 102, 103], [
            11 => ['Small', [['Size', 'Small']], '249', 'yucca-small'],
            12 => ['Medium', [['Size', 'Medium']], '449', 'yucca-medium'],
            13 => ['Large', [['Size', 'Large']], '659', 'yucca-large'],
        ]);
        $this->product(2, 'Pressure Sprayer', 2, 'TLS-SPR-00002', [111, 112, 121, 122], [
            21 => ['Red/5 L', [['Colour', 'Red'], ['Capacity', '5 L']], '899', 'TLS-SPR-00002-RED-5L'],
            22 => ['Red/8 L', [['Colour', 'Red'], ['Capacity', '8 L']], '1099', 'TLS-SPR-00002-RED-8L'],
            23 => ['Blue/5 L', [['Colour', 'Blue'], ['Capacity', '5 L']], '899', 'TLS-SPR-00002-BLUE-5L'],
            24 => ['Blue/8 L', [['Colour', 'Blue'], ['Capacity', '8 L']], '1099', 'TLS-SPR-00002-BLUE-8L'],
        ]);
    }

    private function product(int $id, string $name, int $typeId, ?string $sku, array $valueIds, array $rows): void
    {
        DB::table('products')->insert([
            'id' => $id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => $sku,
            'product_type' => $rows ? 'variable' : 'simple', 'type_id' => $typeId, 'shop_id' => 1, 'unit' => '1 pc',
            'price' => $rows ? null : 300, 'min_price' => $rows ? 249 : 300, 'max_price' => $rows ? 1099 : 300,
        ]);
        DB::table('category_product')->insert(['product_id' => $id, 'category_id' => 1]);
        foreach ($valueIds as $valueId) {
            DB::table('attribute_product')->insert(['product_id' => $id, 'attribute_value_id' => $valueId]);
        }
        foreach ($rows as $rowId => [$title, $options, $price, $rowSku]) {
            DB::table('variation_options')->insert([
                'id' => $rowId, 'product_id' => $id, 'title' => $title, 'price' => $price, 'quantity' => 10, 'sku' => $rowSku,
                'options' => json_encode(array_map(fn ($o) => ['name' => $o[0], 'value' => $o[1]], $options)),
            ]);
        }
    }

    private function adminPayload(int $productId): array
    {
        return [
            'product_type'      => 'variable',
            'variations'        => DB::table('attribute_product')->where('product_id', $productId)->orderBy('id')->pluck('attribute_value_id')
                ->map(fn ($id) => ['attribute_value_id' => (int) $id])->all(),
            'variation_options' => [
                'upsert' => DB::table('variation_options')->where('product_id', $productId)->orderBy('id')->get()->map(fn ($r) => [
                    'id' => (int) $r->id, 'title' => $r->title, 'price' => $r->price, 'sale_price' => $r->sale_price, 'sku' => $r->sku,
                    'quantity' => (int) $r->quantity, 'is_disable' => (bool) $r->is_disable, 'options' => json_decode($r->options, true),
                ])->all(),
                'delete' => [],
            ],
        ];
    }

    private function validated($request)
    {
        $request->setContainer(app())->setRedirector(app('redirect'));
        try {
            $request->validateResolved();
        } catch (HttpResponseException $e) {
            $this->fail('422: ' . json_encode($e->getResponse()->getData(true)));
        }

        return $request;
    }

    private function updateRequest(int $productId, array $payload): ProductUpdateRequest
    {
        $request = ProductUpdateRequest::create("/api/products/{$productId}", 'PUT', $payload);
        $request->setRouteResolver(fn () => (new Route('PUT', 'api/products/{product}', []))->bind($request));

        return $request;
    }

    /** The 422 body of an update, or null when it passes. */
    private function updateErrors(int $productId, array $payload): ?array
    {
        $request = $this->updateRequest($productId, $payload)->setContainer(app())->setRedirector(app('redirect'));
        try {
            $request->validateResolved();

            return null;
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getData(true);
        }
    }

    private function update(int $productId, array $payload): void
    {
        app(ProductRepository::class)->updateProduct($this->validated($this->updateRequest($productId, $payload)), $productId, (object) ['options' => self::SETTING]);
    }

    private function skus(int $productId): array
    {
        return DB::table('variation_options')->where('product_id', $productId)->orderBy('id')->pluck('sku', 'title')->all();
    }

    public function test_blank_variant_skus_are_filled_on_create_also_when_the_product_has_a_sku(): void
    {
        $row = fn (array $options, ?string $sku) => ['price' => 499, 'quantity' => 3, 'sku' => $sku, 'options' => array_map(fn ($o) => ['name' => $o[0], 'value' => $o[1]], $options)];
        $create = fn (string $name, ?string $sku, ?string $blueSku) => app(ProductRepository::class)->storeProduct($this->validated(ProductCreateRequest::create('/api/products', 'POST', [
            'name' => $name, 'sku' => $sku, 'type_id' => 2, 'shop_id' => 1, 'unit' => '1 pc', 'product_type' => 'variable', 'categories' => [1],
            'variations' => [['attribute_value_id' => 111], ['attribute_value_id' => 112], ['attribute_value_id' => 121], ['attribute_value_id' => 123]],
            'variation_options' => ['upsert' => [
                $row([['Colour', 'Red'], ['Capacity', '5 L']], null),
                $row([['Colour', 'Red'], ['Capacity', '50 L']], null),
                $row([['Colour', 'Blue'], ['Capacity', '5 L']], $blueSku),
            ]],
        ])), (object) ['options' => []]);

        $withSku = $create('Hose Reel', 'HOSE-REEL', 'Hose-Blue-5L');
        $this->assertSame('HOSE-REEL', $withSku->fresh()->sku);
        // Blank titles become the option values; a typed SKU keeps its case; 5 L and 50 L stay apart.
        $this->assertSame(['Red/5 L' => 'HOSE-REEL-RED-5L', 'Red/50 L' => 'HOSE-REEL-RED-50L', 'Blue/5 L' => 'Hose-Blue-5L'], $this->skus($withSku->id));

        $generated = $create('Garden Hose', null, null);
        $base = 'TLS-GAR-' . str_pad((string) $generated->id, 5, '0', STR_PAD_LEFT);
        $this->assertSame($base, $generated->fresh()->sku);
        $this->assertSame(['Red/5 L' => "{$base}-RED-5L", 'Red/50 L' => "{$base}-RED-50L", 'Blue/5 L' => "{$base}-BLUE-5L"], $this->skus($generated->id));
    }

    public function test_blank_variant_skus_are_filled_on_update(): void
    {
        // A tool: a new Green / 5 L row and a SKU cleared on an existing row.
        $payload = $this->adminPayload(2);
        $payload['variations'][] = ['attribute_value_id' => 113];
        $payload['variation_options']['upsert'][1]['sku'] = null;
        $payload['variation_options']['upsert'][] = ['title' => 'Green/5 L', 'price' => 899, 'quantity' => 4, 'sku' => null, 'options' => [['name' => 'Colour', 'value' => 'Green'], ['name' => 'Capacity', 'value' => '5 L']]];
        $this->update(2, $payload);

        $skus = $this->skus(2);
        $this->assertSame('TLS-SPR-00002-RED-8L', $skus['Red/8 L']);
        $this->assertSame('TLS-SPR-00002-GREE-5L', $skus['Green/5 L'], 'no code: the first 4 letters of the value');
        $this->assertSame('TLS-SPR-00002', DB::table('products')->find(2)->sku, 'a variable product keeps its own SKU on update');

        // A plant (no product SKU): the Size value's code is the suffix.
        $plant = $this->adminPayload(1);
        $plant['variation_options']['upsert'][1]['sku'] = null;
        $this->update(1, $plant);
        $this->assertSame(['Small' => 'yucca-small', 'Medium' => 'PLT-GAR-00001-M', 'Large' => 'yucca-large'], $this->skus(1));
        $this->assertNull(DB::table('products')->find(1)->sku);
    }

    public function test_a_variable_products_price_range_comes_from_its_variants(): void
    {
        // The admin form sent min_price NULL whenever sale prices were blank (it compared a blank
        // sale price as the cheapest): every save of a sized plant showed "₹0 – …".
        $payload = $this->adminPayload(1);
        $payload['min_price'] = null;
        $payload['max_price'] = null;
        $this->update(1, $payload);
        $range = fn () => array_map('floatval', (array) DB::table('products')->where('id', 1)->first(['min_price', 'max_price']));
        $this->assertSame(['min_price' => 249.0, 'max_price' => 659.0], $range());

        // A lower sale price counts; a switched-off variant does not.
        $payload = $this->adminPayload(1);
        $payload['variation_options']['upsert'][1]['sale_price'] = 199;   // Medium on sale
        $payload['variation_options']['upsert'][2]['is_disable'] = true;  // Large off
        $this->update(1, $payload);
        $this->assertSame(['min_price' => 199.0, 'max_price' => 449.0], $range());
    }

    public function test_a_variant_sku_is_used_once(): void
    {
        $taken = $this->adminPayload(2);
        $taken['variation_options']['upsert'][0]['sku'] = 'yucca-small';
        $this->assertSame(['SKU yucca-small is already used.'], $this->updateErrors(2, $taken)['variation_options']);

        $twice = $this->adminPayload(2);
        $twice['variation_options']['upsert'][0]['sku'] = 'SPRAYER-X';
        $twice['variation_options']['upsert'][1]['sku'] = 'sprayer-x';
        $this->assertSame(['SKU sprayer-x is already used.'], $this->updateErrors(2, $twice)['variation_options']);

        // A SKU the variant already has is never refused, even when an older row shares it.
        DB::table('variation_options')->insert(['product_id' => 1, 'title' => 'Old', 'price' => '1', 'quantity' => 0, 'sku' => 'TLS-SPR-00002-RED-8L', 'options' => '[]']);
        $this->assertNull($this->updateErrors(2, $this->adminPayload(2)));

        // The product SKU is checked against products, its own row excepted.
        $this->assertNull($this->updateErrors(2, ['product_type' => 'variable', 'sku' => 'TLS-SPR-00002']));
        DB::table('products')->where('id', 1)->update(['sku' => 'PLT-YUC-00001']);
        $this->assertArrayHasKey('sku', $this->updateErrors(2, ['product_type' => 'variable', 'sku' => 'PLT-YUC-00001']));
    }

    public function test_multi_axis_suffixes_stay_unique(): void
    {
        $gen = new SkuGenerator();
        $product = Product::find(2);
        $make = fn (int $id, array $options) => Variation::forceCreate([
            'id' => $id, 'product_id' => 2, 'title' => 'x', 'price' => '1', 'quantity' => 0,
            'options' => array_map(fn ($o) => ['name' => $o[0], 'value' => $o[1]], $options),
        ]);

        // One part per axis: "5 L" and "50 L" stay apart (the old 4-letter title prefix gave both RED5).
        $this->assertSame('TLS-SPR-00002-RED-50L', $gen->forVariation($product, $make(31, [['Colour', 'Red'], ['Capacity', '50 L']])));
        // Row 21 already holds TLS-SPR-00002-RED-5L: another row with that combination gets its id.
        $this->assertSame('TLS-SPR-00002-RED-5L-32', $gen->forVariation($product, $make(32, [['Colour', 'Red'], ['Capacity', '5 L']])));
        $this->assertSame('TLS-SPR-00002-RED-5L', $gen->forVariation($product, Variation::find(21)), 'its own SKU is not a collision');
        // The plants' Size values carry codes (S/M/L).
        $this->assertSame('PLT-GAR-00001-L', $gen->forVariation(Product::find(1), Variation::find(13)));
    }

    public function test_gtin_takes_valid_gs1_numbers_only(): void
    {
        $passes = fn ($v) => (new Gtin())->passes('gtin', $v);
        foreach (['4006381333931', '012345678905', '96385074', '00012345600012'] as $valid) {
            $this->assertTrue($passes($valid), $valid);
        }
        foreach (['4006381333932', '12345', '400638133393', 'ABCDEFGHIJKLM', '4006381333931 ', '40063813339311'] as $invalid) {
            $this->assertFalse($passes($invalid), $invalid);
        }

        $payload = $this->adminPayload(2);
        $payload['gtin'] = '4006381333932';
        $payload['mpn'] = str_repeat('M', 65);
        $payload['variation_options']['upsert'][0]['gtin'] = '96385075';
        $payload['variation_options']['upsert'][1]['gtin'] = '96385074';
        $payload['variation_options']['upsert'][1]['mpn'] = 'GA-1990-20';
        $errors = $this->updateErrors(2, $payload);
        $this->assertSame(['gtin', 'mpn', 'variation_options.upsert.0.gtin'], array_keys($errors));
        $this->assertSame('The Red / 5 L GTIN must be a valid GTIN: 8, 12, 13 or 14 digits ending in the right check digit.', $errors['variation_options.upsert.0.gtin'][0]);
    }

    public function test_the_migration_nulls_blank_skus_and_adds_the_index_and_identifier_columns(): void
    {
        DB::table('variation_options')->where('id', 21)->update(['sku' => '']);
        DB::table('variation_options')->where('id', 22)->update(['sku' => '   ']);
        $migration = require base_path(self::MIGRATION);

        $migration->up();

        $this->assertNull(DB::table('variation_options')->find(21)->sku);
        $this->assertNull(DB::table('variation_options')->find(22)->sku);
        $this->assertTrue($this->hasSkuIndex());
        foreach (['products', 'variation_options'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['gtin', 'mpn']), $table);
        }
        try {
            DB::table('variation_options')->insert(['product_id' => 1, 'title' => 'Dup', 'price' => '1', 'quantity' => 0, 'sku' => 'yucca-small', 'options' => '[]']);
            $this->fail('a duplicate SKU was stored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
        $migration->up(); // twice is harmless

        $migration->down();
        $this->assertFalse($this->hasSkuIndex());
        $this->assertFalse(Schema::hasColumn('products', 'gtin'));
    }

    public function test_the_migration_skips_the_index_when_skus_are_duplicated(): void
    {
        DB::table('variation_options')->insert(['product_id' => 2, 'title' => 'Copy', 'price' => '1', 'quantity' => 0, 'sku' => 'yucca-small', 'options' => '[]']);
        Log::spy();

        (require base_path(self::MIGRATION))->up();

        $this->assertFalse($this->hasSkuIndex());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'duplicates') && $context['skus'] === ['yucca-small']);
        $this->assertTrue(Schema::hasColumns('variation_options', ['gtin', 'mpn']), 'the identifier columns do not wait for the index');
    }

    public function test_related_variable_products_carry_their_cheapest_enabled_variant(): void
    {
        // The sprayer's cheapest is Blue / 5 L on sale (799); a cheaper disabled row and a ₹0 row are skipped.
        DB::table('variation_options')->where('id', 23)->update(['sale_price' => '799']);
        DB::table('variation_options')->insert([
            ['id' => 25, 'product_id' => 2, 'title' => 'Red/50 L', 'price' => '499', 'quantity' => 1, 'is_disable' => true, 'sku' => null, 'options' => '[]'],
            ['id' => 26, 'product_id' => 2, 'title' => 'Blue/50 L', 'price' => '0', 'quantity' => 1, 'is_disable' => false, 'sku' => null, 'options' => '[]'],
        ]);
        $this->product(3, 'Trowel', 2, null, [], []);
        $this->product(4, 'Old Planter', 2, null, [111], [41 => ['Red', [['Colour', 'Red']], '350', null]]);
        DB::table('variation_options')->where('id', 41)->update(['is_disable' => true]);

        $related = app(ProductRepository::class)->fetchRelated('yucca', 10, 'en');
        $payload = collect(RelatedProductResource::collection($related)->toArray(Request::create('/')))->keyBy('id');

        $this->assertSame([2, 3, 4], $payload->keys()->sort()->values()->all());
        $this->assertEquals(['id' => 23, 'title' => 'Blue/5 L', 'price' => '899', 'sale_price' => '799', 'options' => [['name' => 'Colour', 'value' => 'Blue'], ['name' => 'Capacity', 'value' => '5 L']]], $payload[2]['default_variation']);
        $this->assertArrayNotHasKey('default_variation', $payload[3], 'a simple product is unchanged');
        $this->assertNull($payload[4]['default_variation'], 'nothing enabled to sell');
    }

    public function test_the_backfill_command_fills_variants_of_products_that_have_a_sku(): void
    {
        DB::table('variation_options')->where('id', 24)->update(['sku' => null]);

        $this->artisan('plantathome:generate-skus')->assertExitCode(0);

        $this->assertSame('TLS-SPR-00002-BLUE-8L', DB::table('variation_options')->find(24)->sku);
        $this->assertSame('TLS-SPR-00002', DB::table('products')->find(2)->sku);
        $this->assertSame('yucca-small', DB::table('variation_options')->find(11)->sku, 'a SKU already set is left alone');
    }

    private function hasSkuIndex(): bool
    {
        return collect(DB::select('PRAGMA index_list("variation_options")'))->contains(fn ($i) => $i->name === 'variation_options_sku_unique');
    }
}
