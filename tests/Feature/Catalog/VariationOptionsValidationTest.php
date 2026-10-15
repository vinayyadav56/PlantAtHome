<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Tests\TestCase;

/**
 * Product variants (contract 2026-10-15, sections 1-2): a product save's variant rows are checked
 * before anything is written. A variant is its (axis name, value) pairs; one with orders or seller
 * prices is never deleted or given another combination; the FIRST message names the variant,
 * because the admin toasts only that one. The Plants Size payload saves exactly as before.
 *
 * In-memory sqlite. Value ids start at 101: updateProduct syncs [{attribute_value_id}] objects keyed
 * by position, which works only while no chosen value id is also a small position number.
 */
final class VariationOptionsValidationTest extends TestCase
{
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
            // Two of the five tables whose reference keeps a variant; the other three are absent (guarded).
            'order_items'           => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variation_option_id')->nullable()],
            'vendor_product_prices' => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variation_option_id')->nullable()],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->bigIncrements('id');
                $columns($t);
                $t->timestamps();
            });
        }

        DB::table('types')->insert([['id' => 1, 'name' => 'Plants', 'slug' => 'plants'], ['id' => 2, 'name' => 'Tools', 'slug' => 'tools']]);
        DB::table('shops')->insert(['id' => 1, 'name' => 'PlantAtHome']);
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
        ]);

        // A plant as production has it: Size rows titled by size, SKU {slug}-{size}, no product SKU.
        $this->product(1, 'Yucca', 1, null, [101, 102, 103], [
            11 => ['Small', [['Size', 'Small']], '249', 'yucca-small'],
            12 => ['Medium', [['Size', 'Medium']], '449', 'yucca-medium'],
            13 => ['Large', [['Size', 'Large']], '659', 'yucca-large'],
        ]);
        // A two-axis tool. Red / 5 L has an order line, Blue / 5 L a seller price.
        $this->product(2, 'Pressure Sprayer', 2, 'TLS-SPR-00002', [111, 112, 121, 122], [
            21 => ['Red/5 L', [['Colour', 'Red'], ['Capacity', '5 L']], '899', 'TLS-SPR-00002-RED-5L'],
            22 => ['Red/8 L', [['Colour', 'Red'], ['Capacity', '8 L']], '1099', 'TLS-SPR-00002-RED-8L'],
            23 => ['Blue/5 L', [['Colour', 'Blue'], ['Capacity', '5 L']], '899', 'TLS-SPR-00002-BLUE-5L'],
            24 => ['Blue/8 L', [['Colour', 'Blue'], ['Capacity', '8 L']], '1099', 'TLS-SPR-00002-BLUE-8L'],
        ]);
        DB::table('order_items')->insert(['product_id' => 2, 'variation_option_id' => 21]);
        DB::table('vendor_product_prices')->insert(['product_id' => 2, 'variation_option_id' => 23]);
    }

    private function product(int $id, string $name, int $typeId, ?string $sku, array $valueIds, array $rows): void
    {
        DB::table('products')->insert([
            'id' => $id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => $sku,
            'product_type' => 'variable', 'type_id' => $typeId, 'shop_id' => 1, 'unit' => '1 pc',
        ]);
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

    /** What the admin sends when the product is saved untouched. */
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

    private function updateRequest(int $productId, array $payload): ProductUpdateRequest
    {
        $request = ProductUpdateRequest::create("/api/products/{$productId}", 'PUT', $payload);
        $request->setRouteResolver(fn () => (new Route('PUT', 'api/products/{product}', []))->bind($request));

        return $request->setContainer(app())->setRedirector(app('redirect'));
    }

    /** The 422 body ({field: [messages]}), or null when the request passes. */
    private function errors($request): ?array
    {
        try {
            $request->validateResolved();

            return null;
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());

            return $e->getResponse()->getData(true);
        }
    }

    /** The message the admin toasts: Object.values(data).flat()[0]. */
    private function toast(int $productId, array $payload): ?string
    {
        $errors = $this->errors($this->updateRequest($productId, $payload));

        return $errors === null ? null : Arr::flatten($errors)[0];
    }

    private function save(int $productId, array $payload): void
    {
        $request = $this->updateRequest($productId, $payload);
        $this->assertNull($this->errors($request));
        app(ProductRepository::class)->updateProduct($request, $productId, (object) ['options' => self::SETTING]);
    }

    private function rows(int $productId): array
    {
        return DB::table('variation_options')->where('product_id', $productId)->orderBy('id')->get(['id', 'title', 'price', 'sale_price', 'sku', 'quantity', 'is_disable', 'options'])
            ->map(fn ($r) => array_merge((array) $r, ['options' => json_decode($r->options, true)]))->all();
    }

    public function test_an_unchanged_plants_size_payload_passes_and_saves_exactly_as_before(): void
    {
        $before = $this->rows(1);

        $this->save(1, $this->adminPayload(1));

        $this->assertEquals($before, $this->rows(1), 'same ids, titles, prices, SKUs (case kept) and options');
        $this->assertSame([101, 102, 103], DB::table('attribute_product')->where('product_id', 1)->orderBy('attribute_value_id')->pluck('attribute_value_id')->map(fn ($v) => (int) $v)->all());
        $this->assertNull(DB::table('products')->find(1)->sku, 'a plant without a product SKU stays without one');
    }

    public function test_options_must_be_the_chosen_axes_and_values_once_each(): void
    {
        $payload = $this->adminPayload(2);

        $missing = $payload;
        $missing['variation_options']['upsert'][] = ['title' => 'Red', 'price' => 999, 'quantity' => 1, 'options' => [['name' => 'Colour', 'value' => 'Red']]];
        $this->assertSame('Red needs a Capacity value.', $this->toast(2, $missing));

        $foreign = $payload;
        $foreign['variation_options']['upsert'][] = ['price' => 999, 'quantity' => 1, 'options' => [['name' => 'Colour', 'value' => 'Green'], ['name' => 'Capacity', 'value' => '5 L']]];
        $this->assertSame('Green is not a Colour value (Green / 5 L).', $this->toast(2, $foreign), 'Green exists, but this product did not choose it');

        $unknown = $payload;
        $unknown['variation_options']['upsert'][0]['options'][] = ['name' => 'Size', 'value' => 'Small'];
        $this->assertSame('Red / 5 L / Small: Size is not one of this product\'s options.', $this->toast(2, $unknown));

        $twice = $payload;
        $twice['variation_options']['upsert'][1]['options'] = [['name' => 'Colour', 'value' => 'Red'], ['name' => 'colour', 'value' => 'Blue']];
        $this->assertSame('Red / Blue: Colour is given twice.', $this->toast(2, $twice));
    }

    public function test_a_combination_cannot_appear_twice_in_the_payload_or_beside_a_stored_row(): void
    {
        // Same pairs in another order and case are the same variant.
        $payload = $this->adminPayload(2);
        $payload['variation_options']['upsert'][] = ['price' => 999, 'quantity' => 1, 'options' => [['name' => 'capacity', 'value' => '8 l'], ['name' => 'COLOUR', 'value' => 'red']]];
        $this->assertSame('8 l / red appears twice.', $this->toast(2, $payload));

        // A stored row the save neither sends nor deletes still counts.
        $partial = $this->adminPayload(2);
        $partial['variation_options']['upsert'] = [['price' => 999, 'quantity' => 1, 'options' => [['name' => 'Colour', 'value' => 'Blue'], ['name' => 'Capacity', 'value' => '8 L']]]];
        $this->assertSame('Blue / 8 L appears twice.', $this->toast(2, $partial));

        // ...unless it is deleted in the same save.
        $partial['variation_options']['delete'] = [24];
        $this->assertNull($this->toast(2, $partial));
    }

    public function test_a_variant_with_orders_or_seller_prices_is_never_deleted(): void
    {
        foreach ([21 => 'Red / 5 L', 23 => 'Blue / 5 L'] as $id => $label) {
            $payload = $this->adminPayload(2);
            $payload['variation_options']['upsert'] = array_values(array_filter($payload['variation_options']['upsert'], fn ($r) => $r['id'] !== $id));
            $payload['variation_options']['delete'] = [$id];

            $this->assertSame("'{$label}' has orders or seller prices — switch it off instead of deleting it.", $this->toast(2, $payload));
            $this->assertTrue(DB::table('variation_options')->where('id', $id)->exists());
        }
    }

    public function test_an_unreferenced_variant_is_deleted(): void
    {
        $payload = $this->adminPayload(2);
        $payload['variation_options']['upsert'] = array_values(array_filter($payload['variation_options']['upsert'], fn ($r) => $r['id'] !== 24));
        $payload['variation_options']['delete'] = [24];

        $this->save(2, $payload);

        $this->assertSame([21, 22, 23], array_column($this->rows(2), 'id'));
    }

    public function test_switching_to_simple_is_refused_while_a_variant_is_in_use(): void
    {
        // As the admin sends it, and from a client that leaves variation_options out.
        foreach ([['variation_options' => ['upsert' => [], 'delete' => [21, 22, 23, 24]]], []] as $extra) {
            $toast = $this->toast(2, ['product_type' => 'simple', 'variations' => [], 'price' => 999] + $extra);
            $this->assertSame("'Red / 5 L' has orders or seller prices — switch it off instead of deleting it.", $toast);
        }
        $this->assertSame('variable', DB::table('products')->find(2)->product_type);
        $this->assertCount(4, $this->rows(2));

        // Nothing in use: the plant may become simple (updateProduct then deletes its sizes).
        $this->assertNull($this->toast(1, ['product_type' => 'simple', 'variations' => [], 'price' => 299, 'variation_options' => ['upsert' => [], 'delete' => [11, 12, 13]]]));
    }

    public function test_a_variant_in_use_keeps_its_combination(): void
    {
        // Row 21 (ordered) relabelled as Blue / 8 L, the way the admin's position-bound ids did it.
        $payload = $this->adminPayload(2);
        $payload['variation_options']['upsert'][0]['options'] = [['name' => 'Colour', 'value' => 'Blue'], ['name' => 'Capacity', 'value' => '8 L']];
        $payload['variation_options']['upsert'] = [$payload['variation_options']['upsert'][0], $payload['variation_options']['upsert'][1], $payload['variation_options']['upsert'][2]];
        $payload['variation_options']['delete'] = [24];
        $this->assertSame("'Red / 5 L' has orders or seller prices, so it cannot become Blue / 8 L — switch it off and add Blue / 8 L as a new variant.", $this->toast(2, $payload));

        // An unused row may change (row 24, Blue / 8 L, becomes Green / 8 L).
        $free = $this->adminPayload(2);
        $free['variations'][] = ['attribute_value_id' => 113];
        $free['variation_options']['upsert'][3]['options'] = [['name' => 'Colour', 'value' => 'Green'], ['name' => 'Capacity', 'value' => '8 L']];
        $this->assertNull($this->toast(2, $free));
    }

    public function test_an_id_must_be_one_of_this_products_variants(): void
    {
        $payload = $this->adminPayload(2);
        $payload['variation_options']['upsert'][0]['id'] = 11; // the Yucca's Small

        $this->assertSame('Red / 5 L is not one of this product\'s variants (id 11) — reload the page and save again.', $this->toast(2, $payload));
    }

    public function test_price_sale_price_and_quantity_messages_name_the_variant(): void
    {
        // The framework's wording (resources/lang has no validation.php), with the variant named.
        $cases = [
            ['price', 0, 'The Red / 5 L price field must be greater than 0.'],
            ['sale_price', 899, 'The Red / 5 L sale price field must be less than 899.'],
            ['sale_price', 0, 'The Red / 5 L sale price field must be greater than 0.'],
            ['quantity', -1, 'The Red / 5 L quantity field must be at least 0.'],
        ];
        foreach ($cases as [$field, $value, $message]) {
            $payload = $this->adminPayload(2);
            $payload['variation_options']['upsert'][0][$field] = $value;
            $this->assertSame($message, $this->toast(2, $payload), $field);
        }
        $ok = $this->adminPayload(2);
        $ok['variation_options']['upsert'][0]['sale_price'] = 799;
        $ok['variation_options']['upsert'][0]['quantity'] = 0;
        $this->assertNull($this->toast(2, $ok));
    }

    public function test_create_checks_combinations_and_refuses_ids(): void
    {
        $create = fn (array $payload) => $this->errors(ProductCreateRequest::create('/api/products', 'POST', $payload + [
            'name' => 'Hose Reel', 'type_id' => 2, 'shop_id' => 1, 'unit' => '1 pc', 'product_type' => 'variable',
            'variations' => [['attribute_value_id' => 111], ['attribute_value_id' => 121]],
        ])->setContainer(app())->setRedirector(app('redirect')));
        $row = ['title' => 'Red/5 L', 'price' => 499, 'quantity' => 3, 'options' => [['name' => 'Colour', 'value' => 'Red'], ['name' => 'Capacity', 'value' => '5 L']]];

        $this->assertNull($create(['variation_options' => ['upsert' => [$row]]]));
        $this->assertSame(['Red / 5 L appears twice.'], $create(['variation_options' => ['upsert' => [$row, $row]]])['variation_options']);
        $this->assertArrayHasKey('variation_options.upsert.0.id', $create(['variation_options' => ['upsert' => [['id' => 21] + $row]]]));
        $this->assertSame(['The option value field has a duplicate value.'], $create(['variations' => [['attribute_value_id' => 111], ['attribute_value_id' => 111]]])['variations.1.attribute_value_id']);
    }
}
