<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Services\VendorPriceSheetService;
use Tests\TestCase;

/**
 * The vendor price sheet is a piece of paper, and the things that ruin a piece of paper are not
 * the things that ruin an API. These tests pin the three that came out of the real catalogue:
 * a whole product type with no variants at all, two types whose variant vocabularies do not
 * overlap, and columns arriving in whatever order the rows happened to be inserted.
 */
final class VendorPriceSheetTest extends TestCase
{
    use RefreshDatabase;

    private function type(string $slug, string $name): int
    {
        return (int) Type::create(['name' => $name, 'slug' => $slug, 'language' => DEFAULT_LANGUAGE])->id;
    }

    /** @param array<int, array<int, array{name:string,value:string}>> $variants */
    private function product(string $name, int $typeId, array $variants, string $productType = 'variable'): Product
    {
        $p = Product::create([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name),
            'type_id' => $typeId, 'product_type' => $productType,
            'language' => DEFAULT_LANGUAGE, 'status' => 'publish', 'price' => 100, 'quantity' => 5,
            // The sheet only covers what the storefront actually sells.
            'is_available_product' => true, 'listing_enabled' => true,
        ]);
        foreach ($variants as $i => $pairs) {
            $p->variation_options()->create([
                'title'      => implode('/', array_column($pairs, 'value')),
                'price'      => 100 + $i,
                'quantity'   => 1,
                'options'    => $pairs,
                'is_disable' => false,
                'language'   => DEFAULT_LANGUAGE,
            ]);
        }

        return $p;
    }

    private function sheet(): array
    {
        return (new VendorPriceSheetService())->build(
            Product::query()->where('language', DEFAULT_LANGUAGE)->orderBy('name')
        );
    }

    private function group(array $sheet, string $label): ?array
    {
        foreach ($sheet['groups'] as $g) {
            if ($g['label'] === $label) {
                return $g;
            }
        }

        return null;
    }

    public function test_a_variant_product_gets_one_column_per_variant(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $this->product('Monstera', $plants, [
            [['name' => 'Size', 'value' => 'Small']],
            [['name' => 'Size', 'value' => 'Medium']],
            [['name' => 'Size', 'value' => 'Large']],
        ]);

        $g = $this->group($this->sheet(), 'Plants');
        $this->assertNotNull($g);
        $this->assertSame(['Small', 'Medium', 'Large'], array_column($g['columns'], 'label'));
        $this->assertSame(1, count($g['rows']));
    }

    /**
     * Tools are product_type=simple with no variation_options at all. A sheet built purely from
     * variants prints that entire type as empty rows -- 40 of them on staging.
     */
    public function test_a_product_with_no_variants_still_gets_a_price_box(): void
    {
        $tools = $this->type('tools-t', 'Tools');
        $this->product('Hedge Shears', $tools, [], 'simple');

        $g = $this->group($this->sheet(), 'Tools');
        $this->assertNotNull($g, 'a type with no variants must still appear on the sheet');
        $this->assertSame(['Price'], array_column($g['columns'], 'label'));
        $this->assertArrayHasKey(VendorPriceSheetService::PRICE_ONLY, $g['rows'][0]['cells']);
    }

    public function test_a_price_only_product_never_shares_a_header_with_variant_products(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $this->product('Monstera', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $this->product('Mystery Plant', $plants, [], 'simple');

        $sheet = $this->sheet();
        // Same TYPE, but a price-only row under a Small/Medium/Large header would be a lie.
        $this->assertGreaterThanOrEqual(2, count($sheet['groups']));
        foreach ($sheet['groups'] as $g) {
            $labels = array_column($g['columns'], 'label');
            $this->assertFalse(
                in_array('Price', $labels, true) && count($labels) > 1,
                'the price-only column must never sit beside variant columns'
            );
        }
    }

    public function test_two_types_do_not_pollute_each_others_columns(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $pots   = $this->type('pots-t', 'Pots');
        $this->product('Monstera', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $this->product('Clay Pot', $pots, [[['name' => 'Pot Size', 'value' => '6 inch']]]);

        $this->assertSame(['Small'], array_column($this->group($this->sheet(), 'Plants')['columns'], 'label'));
        $this->assertSame(['6 inch'], array_column($this->group($this->sheet(), 'Pots')['columns'], 'label'));
    }

    public function test_several_attributes_on_one_variant_make_one_combined_label(): void
    {
        $pots = $this->type('pots-t', 'Pots');
        $this->product('Planter', $pots, [[
            ['name' => 'Pot Size', 'value' => '6 inch'],
            ['name' => 'Height',   'value' => '2-3 ft'],
            ['name' => 'Material', 'value' => 'Ceramic'],
        ]]);

        $this->assertSame(
            ['6 inch / 2-3 ft / Ceramic'],
            array_column($this->group($this->sheet(), 'Pots')['columns'], 'label'),
            'a vendor must be able to tell exactly which variant they are pricing'
        );
    }

    public function test_columns_follow_the_catalogue_order_not_the_insertion_order(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('attribute_values', 'sort_order')) {
            $this->markTestSkipped('sort_order not in this schema');
        }
        $attr = DB::table('attributes')->insertGetId(['name' => 'Size', 'slug' => 'size', 'language' => DEFAULT_LANGUAGE]);
        foreach ([['Small', 10], ['Medium', 20], ['Large', 30]] as [$v, $o]) {
            DB::table('attribute_values')->insert([
                'attribute_id' => $attr, 'value' => $v, 'slug' => strtolower($v),
                'sort_order' => $o, 'language' => DEFAULT_LANGUAGE,
            ]);
        }
        $plants = $this->type('plants-t', 'Plants');
        // Inserted Large first on purpose.
        $this->product('Monstera', $plants, [
            [['name' => 'Size', 'value' => 'Large']],
            [['name' => 'Size', 'value' => 'Small']],
            [['name' => 'Size', 'value' => 'Medium']],
        ]);

        $this->assertSame(['Small', 'Medium', 'Large'], array_column($this->group($this->sheet(), 'Plants')['columns'], 'label'));
    }

    public function test_a_disabled_variant_is_not_offered_for_pricing(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $p = $this->product('Monstera', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $p->variation_options()->create([
            'title' => 'Large', 'price' => 900, 'quantity' => 1,
            'options' => [['name' => 'Size', 'value' => 'Large']],
            'is_disable' => true, 'language' => DEFAULT_LANGUAGE,
        ]);

        $this->assertSame(['Small'], array_column($this->group($this->sheet(), 'Plants')['columns'], 'label'));
    }

    private function endpoint(array $query): array
    {
        $request = \Illuminate\Http\Request::create('/vendor-price-sheet', 'GET', $query);
        $controller = app(\Marvel\Http\Controllers\ProductController::class);

        return json_decode($controller->priceSheet($request)->getContent(), true);
    }

    /** Brief section 2: a tick-box selection must beat whatever filters are on screen. */
    public function test_an_explicit_selection_wins_over_the_filters(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $pots   = $this->type('pots-t', 'Pots');
        $keep = $this->product('Monstera', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $this->product('Clay Pot', $pots, [[['name' => 'Pot Size', 'value' => '6 inch']]]);

        // A type filter that would otherwise EXCLUDE the selected product.
        $sheet = $this->endpoint(['ids' => [$keep->id], 'type' => 'pots-t']);

        $names = [];
        foreach ($sheet['groups'] as $g) {
            foreach ($g['rows'] as $r) {
                $names[] = $r['name'];
            }
        }
        $this->assertSame(['Monstera'], $names);
    }

    /**
     * `orderBy` reaches an ORDER BY clause, so it is allow-listed rather than passed through.
     * An unknown column must fall back, not reach the database.
     */
    public function test_an_unknown_sort_column_cannot_reach_the_query(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $this->product('Bravo', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $this->product('Alpha', $plants, [[['name' => 'Size', 'value' => 'Small']]]);

        $sheet = $this->endpoint(['orderBy' => 'name); drop table products;--', 'sortedBy' => 'asc']);

        $names = array_column($sheet['groups'][0]['rows'], 'name');
        $this->assertSame(['Alpha', 'Bravo'], $names, 'must fall back to the default name sort');
        $this->assertSame(2, \Marvel\Database\Models\Product::count(), 'the catalogue must still be there');
    }

    public function test_every_row_carries_the_key_the_existing_importer_needs(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $p = $this->product('Monstera', $plants, [[['name' => 'Size', 'value' => 'Small']]]);

        $row = $this->sheet()['groups'][0]['rows'][0];
        // VendorPriceSheetImport keys on `sku | product_id`; the size-pricing commands null the
        // parent sku, so without the #id fallback a filled-in sheet cannot be typed back in.
        $this->assertSame('#' . $p->id, $row['code']);
    }

    /** A vendor cannot quote something the storefront does not sell. */
    public function test_a_product_that_is_not_listed_never_reaches_the_sheet(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $this->product('Listed', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $hidden = $this->product('Not Listed', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $hidden->forceFill(['listing_enabled' => false])->save();

        $names = [];
        foreach ($this->endpoint([])['groups'] as $g) {
            foreach ($g['rows'] as $r) {
                $names[] = $r['name'];
            }
        }
        $this->assertSame(['Listed'], $names);
    }

    /**
     * The button sits on "All Products", which deliberately shows uncurated rows, so a ticked
     * product can be unlistable. Dropping it silently would hand the vendor a short sheet with
     * no explanation.
     */
    public function test_a_ticked_but_unlisted_product_is_reported_not_silently_dropped(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $ok = $this->product('Listed', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $no = $this->product('Not Listed', $plants, [[['name' => 'Size', 'value' => 'Small']]]);
        $no->forceFill(['is_available_product' => false])->save();

        $sheet = $this->endpoint(['ids' => [$ok->id, $no->id]]);

        $this->assertSame(1, $sheet['meta']['total']);
        $this->assertSame(1, $sheet['meta']['excluded_unlisted']);
    }

    /** "All the attributes should come" -- including ones never expanded into variations. */
    public function test_an_attached_attribute_with_no_variation_option_still_gets_a_box(): void
    {
        $plants = $this->type('plants-t', 'Plants');
        $p = $this->product('Monstera', $plants, [[['name' => 'Size', 'value' => 'Small']]]);

        $attr = DB::table('attributes')->insertGetId(['name' => 'Material', 'slug' => 'material-t', 'language' => DEFAULT_LANGUAGE]);
        $valueId = DB::table('attribute_values')->insertGetId([
            'attribute_id' => $attr, 'value' => 'Ceramic', 'slug' => 'ceramic-t', 'language' => DEFAULT_LANGUAGE,
        ]);
        DB::table('attribute_product')->insert(['product_id' => $p->id, 'attribute_value_id' => $valueId]);

        $labels = array_column($this->group($this->sheet(), 'Plants')['columns'], 'label');
        // Two attributes in one group, so the headers qualify themselves.
        $this->assertContains('Material: Ceramic', $labels);
        $this->assertContains('Size: Small', $labels);
    }

    public function test_the_route_is_registered_and_gated_on_read_permission(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/vendor-price-sheet' && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'the sheet route must not be swallowed by products/{product}');
        $mw = $route->gatherMiddleware();
        $this->assertContains('permission:products.view', $mw);
        // Generating a blank form must never need write access to the catalogue.
        $this->assertNotContains('permission:products.edit', $mw);
    }
}
