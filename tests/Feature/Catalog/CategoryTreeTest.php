<?php

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Models\Category;
use Marvel\Database\Repositories\CategoryRepository;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Http\Controllers\CategoryController;
use Marvel\Http\Resources\CategoryResource;
use Marvel\Rules\ValidCategoryParent;
use Marvel\Services\ServiceAvailabilityService;
use Prettus\Repository\Criteria\RequestCriteria;
use Tests\TestCase;

/**
 * The category hierarchy (CategoryTree) and everything that reads it: the tree endpoint, the
 * descendant expansion of product category filters, parent validation, the delete guard and the
 * category resources.
 *
 * Fixture (Tools):
 *   Pruning (order 40) › Secateurs and Pruners › Bypass Pruners, Anvil Pruners (switched off)
 *   Planting (order 50) › Potting Tools › Root Hooks
 *   Indoor (order 100) › Bonsai Tools  (+ Root Hooks placed here: one page, every branch)
 * and one Plants root, which must never be touched.
 *
 * ⚠️ One API request per test method: the Prettus repository keeps its criteria between
 * requests inside one method (see HomepageCategoriesTest).
 */
class CategoryTreeTest extends TestCase
{
    private array $c = [];

    private array $p = [];

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
            $t->json('settings')->nullable();
            $t->json('promotional_sliders')->nullable();
            $t->string('icon')->nullable();
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
            $t->integer('display_order')->default(0);
            $t->boolean('noindex')->default(false);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
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
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->unsignedBigInteger('type_id')->nullable();
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
        // The facets endpoint reads these alongside the products.
        Schema::create('attribute_values', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('attribute_id');
            $t->string('value');
            $t->string('meta')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug');
            $t->string('name');
            $t->string('language')->default('en');
            $t->timestamps();
        });
        Schema::create('attribute_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('attribute_value_id');
            $t->unsignedBigInteger('product_id');
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
            $t->boolean('air_purifying')->nullable();
            $t->timestamps();
        });
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->nullable();
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
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

        // The facets scope consults the vertical kill-switch; fail-open fake, as in ProductFilterFacetsTest.
        $this->app->instance(ServiceAvailabilityService::class, new class extends ServiceAvailabilityService {
            public function __construct() {}
            public function allVerticals(): array { return ['plants', 'tools']; }
            public function availableVerticalsForCity(?string $city): array { return ['plants', 'tools']; }
        });

        $plants = DB::table('types')->insertGetId(['name' => 'Plants', 'slug' => 'plants']);
        $tools = DB::table('types')->insertGetId(['name' => 'Tools', 'slug' => 'tools']);
        $cat = function (string $slug, string $name, int $type, ?string $parent, int $order, array $extra = []) {
            $this->c[$slug] = DB::table('categories')->insertGetId(array_merge([
                'name' => $name, 'slug' => $slug, 'type_id' => $type,
                'parent' => $parent ? $this->c[$parent] : null, 'display_order' => $order,
            ], $extra));
        };
        $cat('indoor', 'Indoor', $tools, null, 100);
        $cat('pruning', 'Pruning', $tools, null, 40);
        $cat('planting', 'Planting', $tools, null, 50);
        $cat('secateurs', 'Secateurs and Pruners', $tools, 'pruning', 10);
        $cat('bypass', 'Bypass Pruners', $tools, 'secateurs', 10);
        $cat('anvil', 'Anvil Pruners', $tools, 'secateurs', 20, ['is_active' => false]);
        $cat('potting', 'Potting Tools', $tools, 'planting', 40);
        $cat('root-hooks', 'Root Hooks', $tools, 'potting', 40);
        $cat('bonsai', 'Bonsai Tools', $tools, 'indoor', 20);
        $cat('house-plants', 'House Plants', $plants, null, 0);
        DB::table('category_placements')->insert(['category_id' => $this->c['root-hooks'], 'parent_id' => $this->c['bonsai'], 'display_order' => 60]);

        $product = function (string $slug, ?string $category, array $extra = []) use ($tools) {
            $this->p[$slug] = DB::table('products')->insertGetId(array_merge([
                'name' => ucfirst($slug), 'slug' => $slug, 'type_id' => $tools, 'price' => 499,
            ], $extra));
            if ($category) {
                DB::table('category_product')->insert(['product_id' => $this->p[$slug], 'category_id' => $this->c[$category]]);
            }
        };
        $product('secateur', 'bypass');
        $product('root-hook', 'root-hooks');
        $product('draft-secateur', 'bypass', ['status' => 'draft']);
        $product('unpriced-pruner', 'pruning', ['price' => null]);
        $product('anvil-pruner', 'anvil');
        $product('fern', 'house-plants', ['type_id' => $plants]);
    }

    private function repoIds(array $query): array
    {
        $this->app['request']->merge($query);
        $repo = $this->app->make(ProductRepository::class);
        $repo->pushCriteria($this->app->make(RequestCriteria::class));

        return $repo->get(['products.id'])->pluck('id')->sort()->values()->all();
    }

    private function ids(string ...$slugs): array
    {
        $ids = array_map(fn ($s) => $this->p[$s], $slugs);
        sort($ids);

        return $ids;
    }

    private function asSuperAdmin(Request $request): Request
    {
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($p): bool { return (string) $p === 'super_admin'; }
        });

        return $request;
    }

    /* ── tree endpoint ───────────────────────────────────────────────────── */

    public function test_tree_is_nested_ordered_live_only_with_placements_and_rolled_up_counts(): void
    {
        $tree = $this->getJson('/api/categories/tree?type=tools')->assertOk()->json('data');

        $this->assertSame(['pruning', 'planting', 'indoor'], array_column($tree, 'slug'), 'display_order, not insertion order');
        $pruning = $tree[0];
        $secateurs = $pruning['children'][0];
        $this->assertSame(['bypass'], array_column($secateurs['children'], 'slug'), 'a switched-off category is left out');
        $this->assertSame(3, $secateurs['children'][0]['depth']);

        // Listable products only (no drafts, no unpriced), rolled up through the sub-tree.
        $this->assertSame(1, $secateurs['children'][0]['products_count']);
        $this->assertSame(2, $pruning['products_count'], 'secateur + the anvil pruner (its retired leaf still lists it)');

        // Root Hooks: canonical under Planting › Potting, and listed under Indoor › Bonsai too.
        $bonsai = $tree[2]['children'][0];
        $placed = $bonsai['children'][0];
        $this->assertSame('root-hooks', $placed['slug']);
        $this->assertTrue($placed['placement']);
        $this->assertSame($this->c['potting'], $placed['canonical_parent_id']);
        $this->assertSame(1, $tree[2]['products_count'], 'a placement rolls its products up into its other parents');
        $this->assertSame(1, $tree[1]['products_count']);

        $this->assertNotContains('house-plants', array_column($tree, 'slug'));
    }

    public function test_all_includes_switched_off_categories_for_a_super_admin_only(): void
    {
        $controller = app(CategoryController::class);
        $admin = $controller->tree($this->asSuperAdmin(Request::create('/api/categories/tree', 'GET', ['type' => 'tools', 'all' => 1])))->getData(true)['data'];
        $public = $controller->tree(Request::create('/api/categories/tree', 'GET', ['type' => 'tools', 'all' => 1]))->getData(true)['data'];

        $this->assertSame(['bypass', 'anvil'], array_column($admin[0]['children'][0]['children'], 'slug'));
        $this->assertSame(['bypass'], array_column($public[0]['children'][0]['children'], 'slug'), '`all` is ignored without super admin');
    }

    /* ── descendant expansion ─────────────────────────────────────────────── */

    public function test_a_parent_category_filter_finds_the_products_of_its_sub_tree(): void
    {
        $ids = $this->repoIds(['search' => 'categories.slug:pruning', 'searchJoin' => 'and']);

        $this->assertSame($this->ids('secateur', 'draft-secateur', 'unpriced-pruner', 'anvil-pruner'), $ids);
    }

    public function test_a_placement_counts_as_being_under_its_other_parent(): void
    {
        $this->assertSame($this->ids('root-hook'), $this->repoIds(['search' => 'categories.slug:bonsai', 'searchJoin' => 'and']));
    }

    public function test_a_leaf_filter_is_unchanged_and_other_verticals_are_untouched(): void
    {
        $this->assertSame($this->ids('secateur', 'draft-secateur'), $this->repoIds(['search' => 'categories.slug:bypass', 'searchJoin' => 'and']));
    }

    public function test_facets_on_a_parent_count_its_sub_tree(): void
    {
        $res = $this->getJson('/api/products/filter-facets?categories=pruning')->assertOk();

        // Published + public: secateur, unpriced-pruner, anvil-pruner (the draft is out).
        $this->assertSame(3, $res->json('total'));
    }

    /* ── validation ───────────────────────────────────────────────────────── */

    public function test_parent_rule_refuses_self_descendants_other_verticals_and_switched_off_parents(): void
    {
        $tools = (int) DB::table('types')->where('slug', 'tools')->value('id');
        $passes = fn ($parent, ?int $self) => Validator::make(['parent' => $parent], ['parent' => [new ValidCategoryParent($tools, $self)]])->passes();

        $this->assertFalse($passes($this->c['pruning'], $this->c['pruning']), 'its own parent');
        $this->assertFalse($passes($this->c['bypass'], $this->c['pruning']), 'one of its own sub-categories');
        $this->assertFalse($passes($this->c['house-plants'], $this->c['bypass']), 'another vertical');
        $this->assertFalse($passes($this->c['anvil'], null), 'a switched-off parent');
        $this->assertFalse($passes(999999, null), 'a parent that does not exist');
        $this->assertTrue($passes($this->c['planting'], $this->c['secateurs']), 'moving under another tools root');
        $this->assertTrue($passes(null, $this->c['secateurs']), 'becoming a root');
    }

    /* ── delete guard ─────────────────────────────────────────────────────── */

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $res = app(CategoryController::class)->destroy($this->c['secateurs']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(2, $res->getData(true)['children']);
        $this->assertNotNull(DB::table('categories')->find($this->c['secateurs']));
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $empty = DB::table('categories')->insertGetId(['name' => 'Empty', 'slug' => 'empty', 'type_id' => 2, 'parent' => $this->c['potting']]);

        $this->assertTrue((bool) app(CategoryController::class)->destroy($empty));
        $this->assertNull(DB::table('categories')->find($empty));
    }

    /* ── resources and endpoints ──────────────────────────────────────────── */

    public function test_resource_carries_parent_ids_ancestors_and_depth(): void
    {
        $leaf = (new CategoryResource(Category::query()->with('parentCategory')->find($this->c['bypass'])))->toArray(request());
        $root = (new CategoryResource(Category::query()->with('parentCategory')->find($this->c['pruning'])))->toArray(request());

        $this->assertSame(['id' => $this->c['secateurs'], 'slug' => 'secateurs', 'name' => 'Secateurs and Pruners'], $leaf['parent']);
        $this->assertSame(['pruning', 'secateurs'], array_column($leaf['ancestors'], 'slug'));
        $this->assertSame(3, $leaf['depth']);
        $this->assertSame(['id' => null, 'slug' => null, 'name' => null], $root['parent'], 'a root keeps an object, as before');
        $this->assertSame([], $root['ancestors']);
    }

    public function test_show_lists_live_children_in_order_with_placements(): void
    {
        $children = $this->getJson('/api/categories/bonsai?language=en')->assertOk()->json('children');

        $this->assertSame(['root-hooks'], array_column($children, 'slug'));
        $this->assertTrue($children[0]['placement']);
        $this->assertSame(1, $children[0]['products_count']);
    }

    public function test_show_leaves_out_switched_off_children(): void
    {
        $this->assertSame(['bypass'], array_column($this->getJson('/api/categories/secateurs?language=en')->json('children'), 'slug'));
    }

    public function test_a_bare_category_list_returns_roots_only(): void
    {
        // The released app asks exactly this for its chip strip.
        $slugs = array_column($this->getJson('/api/categories?limit=100&language=en')->json('data'), 'slug');

        $this->assertEqualsCanonicalizing(['indoor', 'pruning', 'planting', 'house-plants'], $slugs);
    }

    public function test_active_lists_switched_on_roots_in_display_order(): void
    {
        DB::table('categories')->where('id', $this->c['planting'])->update(['is_active' => false]);

        $slugs = array_column($this->getJson('/api/categories?type=tools&parent=null&active=1&limit=100&language=en')->json('data'), 'slug');

        $this->assertSame(['pruning', 'indoor'], $slugs);
    }

    public function test_category_writes_stay_super_admin_only(): void
    {
        $route = fn (string $method, string $uri) => collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => in_array($method, $r->methods(), true) && $r->uri() === $uri);

        foreach ([['POST', 'api/categories'], ['PUT', 'api/categories/{category}'], ['DELETE', 'api/categories/{category}']] as [$method, $uri]) {
            $this->assertContains('permission:super_admin', $route($method, $uri)->gatherMiddleware(), "{$method} {$uri}");
        }
        $this->assertSame('api/categories/tree', $route('GET', 'api/categories/tree')->uri(), 'the tree route is not swallowed by categories/{category}');
    }

    /* ── repository ───────────────────────────────────────────────────────── */

    public function test_placements_sync_to_exactly_the_given_parents(): void
    {
        $repo = app(CategoryRepository::class);

        $repo->syncPlacements($this->c['root-hooks'], [$this->c['secateurs'], $this->c['potting']]);

        $this->assertSame(
            [$this->c['secateurs']],
            DB::table('category_placements')->where('category_id', $this->c['root-hooks'])->pluck('parent_id')->map(fn ($v) => (int) $v)->all(),
            'bonsai dropped, secateurs added, the primary parent never doubles as a placement'
        );
    }

    public function test_a_typed_slug_that_is_taken_is_refused_and_a_name_slug_is_numbered(): void
    {
        $repo = app(CategoryRepository::class);

        $made = $repo->saveCategory(Request::create('/api/categories', 'POST', ['name' => 'Root Hooks', 'type_id' => 2, 'language' => 'en']));
        $this->assertSame('root-hooks-2', $made->slug);

        $this->expectException(HttpResponseException::class);
        $repo->saveCategory(Request::create('/api/categories', 'POST', ['name' => 'Hooks', 'slug' => 'root-hooks', 'type_id' => 2, 'language' => 'en']));
    }
}
