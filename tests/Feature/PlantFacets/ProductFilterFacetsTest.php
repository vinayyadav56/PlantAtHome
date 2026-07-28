<?php

namespace Tests\Feature\PlantFacets;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\PlantAttribute;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Services\ServiceAvailabilityService;
use Prettus\Repository\Criteria\RequestCriteria;
use Tests\TestCase;

/**
 * Plant-attribute filtering + the filter-facets endpoint.
 *
 * In-memory sqlite with hand-built minimal tables (the ImageBatchTestCase
 * idiom). Two deliberate choices:
 *
 *  - The filter tests exercise the REPOSITORY layer (fieldSearchable + the
 *    boot() bare-search guard + Prettus relation handling) rather than the
 *    full /api/products controller, which drags in half the schema. The risky
 *    code is exactly the repository layer.
 *
 *  - sqlite cannot reproduce the MySQL-STRICT string-vs-tinyint 500, so the
 *    boolean guard is asserted on its OBSERVABLE contract instead: what the
 *    guard rewrites `search` into, and what ends up in the query bindings.
 */
class ProductFilterFacetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->string('status')->default('publish');
            $t->string('visibility')->default('visibility_public');
            $t->string('product_type')->default('variable');
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

        // Size filtering goes through variations (BelongsToMany AttributeValue
        // via attribute_product) — mirror the real pivot shape.
        Schema::create('attribute_values', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('attribute_id');
            $t->string('value');
            $t->timestamps();
        });
        Schema::create('attribute_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('attribute_value_id');
            $t->unsignedBigInteger('product_id');
            $t->timestamps();
        });

        // Product uses the kodeine Metable trait, which touches products_meta
        // on every save — the table must exist even though no test writes meta.
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->nullable();
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('plant_attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('sunlight')->nullable();
            $t->string('water_requirement')->nullable();
            $t->string('indoor_outdoor')->nullable();
            $t->string('growth_rate')->nullable();
            $t->string('difficulty_level')->nullable();
            $t->string('height_range')->nullable();
            $t->boolean('pet_friendly')->nullable();
            $t->timestamps();
        });

        // The facets scope consults the vertical kill-switch. Its resolver
        // tables (types/settings) are out of scope here and the system is
        // fail-open by design, so bind a no-filter fake: all == available.
        $this->app->instance(ServiceAvailabilityService::class, new class extends ServiceAvailabilityService {
            public function __construct() {}
            public function allVerticals(): array { return ['plants']; }
            public function availableVerticalsForCity(?string $city): array { return ['plants']; }
        });
    }

    /**
     * Seed one published+public product with its plant attributes.
     *
     * ⚠️ The insert goes through the QUERY BUILDER, not Product::create(), and that is load-bearing.
     *
     * Product uses kodeine's Metable trait, whose hasColumn() caches the table's column list in a
     * FUNCTION-LEVEL `static $columns` keyed by class name — i.e. once per PHP process, not per
     * test. Whichever test class first touches Product freezes that list for the entire run.
     *
     * These tests build their own richer `products` table in setUp, so in a FULL suite run an
     * earlier class's narrower schema wins the cache and Metable stops believing `min_price`,
     * `max_price` and `status` are real columns. Eloquent then quietly diverts them into
     * products_meta and the actual columns stay NULL — which made drafts leak into the facets and
     * emptied the hide_unpriced gate. The tests passed in isolation and failed in CI, which is the
     * worst possible way for this to present.
     *
     * The query builder writes the columns literally, so the seed no longer depends on which test
     * class happened to run first.
     */
    private function plant(string $name, array $attrs = [], array $product = []): Product
    {
        $row = array_merge([
            'name'       => $name,
            'slug'       => str($name)->slug()->toString(),
            'language'   => 'en',
            'status'     => 'publish',
            'visibility' => 'visibility_public',
            'min_price'  => 359,
            'max_price'  => 929,
            'created_at' => now(),
            'updated_at' => now(),
        ], $product);

        $id = DB::table('products')->insertGetId($row);

        PlantAttribute::query()->create(array_merge(['product_id' => $id], $attrs));

        // Read back through the model so callers still get a Product (ids/relations), while the
        // stored column values are the ones written above.
        return Product::query()->findOrFail($id);
    }

    /**
     * Run the repository exactly as the API does: merge the query params into
     * the app request FIRST (the boot() guard reads it at construction), then
     * apply Prettus RequestCriteria.
     */
    private function repoIds(array $query): array
    {
        $this->app['request']->merge($query);
        $repo = $this->app->make(ProductRepository::class);
        $repo->pushCriteria($this->app->make(RequestCriteria::class));

        return $repo->get(['products.id'])->pluck('id')->sort()->values()->all();
    }

    /* ── plantAttribute filters ───────────────────────────────────────────── */

    public function test_single_plant_attribute_filter_narrows_results(): void
    {
        $bright1 = $this->plant('Monstera', ['sunlight' => 'Bright Indirect']);
        $bright2 = $this->plant('Pothos', ['sunlight' => 'Bright Indirect']);
        $this->plant('ZZ Plant', ['sunlight' => 'Low Light']);

        $ids = $this->repoIds([
            'search'     => 'plantAttribute.sunlight:Bright Indirect',
            'searchJoin' => 'and',
        ]);

        $this->assertSame([$bright1->id, $bright2->id], $ids);
    }

    public function test_multi_value_in_filter_matches_any_of_the_values(): void
    {
        $a = $this->plant('Monstera', ['water_requirement' => 'Low']);
        $b = $this->plant('Fern', ['water_requirement' => 'High']);
        $this->plant('Cactus', ['water_requirement' => 'Minimal']);

        $ids = $this->repoIds([
            'search'     => 'plantAttribute.water_requirement:Low,High',
            'searchJoin' => 'and',
        ]);

        $this->assertSame([$a->id, $b->id], $ids);
    }

    public function test_attribute_filters_combine_with_and(): void
    {
        $match = $this->plant('Monstera', ['sunlight' => 'Bright Indirect', 'water_requirement' => 'Moderate']);
        $this->plant('Pothos', ['sunlight' => 'Bright Indirect', 'water_requirement' => 'Low']);

        $ids = $this->repoIds([
            'search'     => 'plantAttribute.sunlight:Bright Indirect;plantAttribute.water_requirement:Moderate',
            'searchJoin' => 'and',
        ]);

        $this->assertSame([$match->id], $ids);
    }

    /* ── the boolean / bare-search guard ──────────────────────────────────── */

    public function test_bare_free_text_search_is_routed_into_name_only(): void
    {
        $rose = $this->plant('Desert Rose', ['pet_friendly' => false]);
        $this->plant('Monstera', ['pet_friendly' => true]);

        $ids = $this->repoIds(['search' => 'rose']);

        // The guard must rewrite the bare value into a name: term…
        $this->assertSame('name:rose', $this->app['request']->get('search'));
        // …and the query must therefore match by name, not smear across columns.
        $this->assertSame([$rose->id], $ids);
    }

    public function test_bare_segment_inside_a_compound_search_is_merged_into_name(): void
    {
        $this->plant('Monstera', []);

        $this->repoIds(['search' => 'rose;plantAttribute.sunlight:Low Light', 'searchJoin' => 'and']);

        $search = $this->app['request']->get('search');
        $this->assertStringContainsString('plantAttribute.sunlight:Low Light', $search);
        $this->assertStringContainsString('name:rose', $search);
        $this->assertStringNotContainsString(';rose', $search);
    }

    public function test_pet_friendly_values_are_coerced_to_numeric_booleans(): void
    {
        $friendly = $this->plant('Calathea', ['pet_friendly' => true]);
        $this->plant('Monstera', ['pet_friendly' => false]);

        foreach (['true', 'yes', '1'] as $truthy) {
            $ids = $this->repoIds([
                'search'     => 'plantAttribute.pet_friendly:' . $truthy,
                'searchJoin' => 'and',
            ]);
            $this->assertSame([$friendly->id], $ids, "pet_friendly:$truthy should match the friendly plant");
            $this->assertStringContainsString(
                'plantAttribute.pet_friendly:1',
                $this->app['request']->get('search'),
                "pet_friendly:$truthy must be coerced to 1 before it can reach the tinyint column"
            );
        }
    }

    public function test_unparseable_pet_friendly_value_is_dropped_not_compared(): void
    {
        $this->plant('Monstera', ['pet_friendly' => true]);

        $ids = $this->repoIds([
            'search'     => 'plantAttribute.pet_friendly:maybe',
            'searchJoin' => 'and',
        ]);

        // The term is dropped entirely: no pet_friendly term survives in the
        // rewritten search (so MySQL STRICT can never see a string-vs-tinyint
        // comparison), and the result set is unfiltered rather than a 500.
        $this->assertStringNotContainsString('pet_friendly', (string) $this->app['request']->get('search'));
        $this->assertCount(1, $ids);
    }

    public function test_in_stock_and_is_rental_values_are_coerced_or_dropped(): void
    {
        $stocked = $this->plant('Monstera', [], ['in_stock' => true]);
        $this->plant('Fern', [], ['in_stock' => false]);

        $ids = $this->repoIds(['search' => 'in_stock:true', 'searchJoin' => 'and']);
        $this->assertSame([$stocked->id], $ids);
        $this->assertStringContainsString('in_stock:1', $this->app['request']->get('search'));

        // The pre-existing hole this closes: an explicit non-boolean value on a
        // boolean column (is_rental was always searchable) must be dropped, not
        // compared — MySQL STRICT turns the comparison into a 500.
        $ids = $this->repoIds(['search' => 'is_rental:maybe', 'searchJoin' => 'and']);
        $this->assertStringNotContainsString('is_rental', (string) $this->app['request']->get('search'));
        $this->assertCount(2, $ids);
    }

    public function test_size_filters_through_the_variations_relation(): void
    {
        $small = $this->plant('Monstera', []);
        $large = $this->plant('Fern', []);
        DB::table('attribute_values')->insert([
            ['id' => 1, 'value' => 'Small', 'attribute_id' => 1],
            ['id' => 2, 'value' => 'Large', 'attribute_id' => 1],
        ]);
        DB::table('attribute_product')->insert([
            ['attribute_value_id' => 1, 'product_id' => $small->id],
            ['attribute_value_id' => 2, 'product_id' => $large->id],
        ]);

        $ids = $this->repoIds(['search' => 'variations.value:Small', 'searchJoin' => 'and']);

        $this->assertSame([$small->id], $ids);
    }

    /* ── the facets endpoint ──────────────────────────────────────────────── */

    public function test_filter_facets_counts_values_and_excludes_junk_and_unpublished(): void
    {
        $this->plant('Monstera', ['sunlight' => 'Bright Indirect', 'pet_friendly' => false], ['min_price' => 359, 'max_price' => 929]);
        $this->plant('Pothos', ['sunlight' => 'Bright Indirect', 'pet_friendly' => true], ['min_price' => 429, 'max_price' => 1119]);
        $this->plant('ZZ Plant', ['sunlight' => 'Low Light', 'pet_friendly' => true], ['min_price' => 199, 'max_price' => 509]);
        // Junk values that must never become facet options:
        $this->plant('Fern', ['sunlight' => 'None']);
        $this->plant('Palm', ['sunlight' => '  ']);
        // A DRAFT product whose value must not leak into the facets:
        $this->plant('Draft-only', ['sunlight' => 'Full Sun'], ['status' => 'draft']);

        $res = $this->getJson('/api/products/filter-facets');
        $res->assertOk();

        $sunlight = collect($res->json('facets.sunlight'));
        $this->assertSame(
            ['Bright Indirect' => 2, 'Low Light' => 1],
            $sunlight->mapWithKeys(fn ($r) => [$r['value'] => $r['count']])->all(),
            'facets must count published+public products only and exclude None/blank junk'
        );
        $this->assertNotContains('Full Sun', $sunlight->pluck('value')->all());

        $this->assertSame(['true' => 2, 'false' => 1], $res->json('facets.pet_friendly'));

        $price = $res->json('facets.price');
        $this->assertSame(199.0, (float) $price['min']);
        $this->assertNotEmpty($price['histogram']);
        // Histogram covers every priced, published product (draft excluded).
        // Monstera, Pothos, ZZ carry prices; Fern/Palm inherit the seed default.
        $this->assertSame(5, array_sum(array_column($price['histogram'], 'count')));
    }

    public function test_filter_facets_hide_unpriced_gate_matches_the_list(): void
    {
        $this->plant('Priced', ['sunlight' => 'Low Light'], ['min_price' => 100, 'max_price' => 200]);
        $this->plant('Unpriced', ['sunlight' => 'Full Sun'], ['price' => null, 'min_price' => null, 'max_price' => null]);

        $res = $this->getJson('/api/products/filter-facets?hide_unpriced=1')->assertOk();

        $values = collect($res->json('facets.sunlight'))->pluck('value')->all();
        $this->assertContains('Low Light', $values);
        $this->assertNotContains('Full Sun', $values, 'unpriced products must not contribute facet values under the gate');
    }
}
