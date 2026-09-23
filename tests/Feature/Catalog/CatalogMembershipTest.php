<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Resources\ProductResource;
use Marvel\Services\ServiceAvailabilityService;
use Tests\TestCase;

/**
 * The Master Catalog gate: existing in `products` is no longer enough to be sold.
 *
 * A product reaches the website only once an admin has moved it into Available Products AND
 * switched its listing on — two deliberate acts, so nothing publishes by a single click. Both
 * columns default FALSE, which is what makes "Available Products starts empty" true by
 * construction rather than by a reset script.
 *
 * The bearer case is the one worth having. fetchProducts already keys its publish default off the
 * absence of a Bearer token, and reusing that signal here would have been the obvious move — but a
 * logged-in SHOPPER carries a Bearer exactly like admin tooling does, so it would have shown the
 * entire uncurated catalogue to every signed-in customer. The gate is default-closed instead, with
 * an explicit `catalog_scope=all` opt-out for the admin screens that must see what is NOT curated.
 *
 * Hand-built sqlite tables, the idiom this suite already uses (see ProductStatusDefaultTest).
 */
final class CatalogMembershipTest extends TestCase
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
            $t->boolean('is_available_product')->default(false);
            $t->boolean('listing_enabled')->default(false);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->integer('rating')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('availabilities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('bookable_type')->nullable();
            $t->date('from')->nullable();
            $t->date('to')->nullable();
        });

        // fetchSingleProduct eager-loads these; the catch-all inside it converts ANY throw into
        // a 404, so a missing stub table would masquerade as the gate firing.
        Schema::create('plant_attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('product_images', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('url')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('in_gallery')->default(true);
        });
        Schema::create('product_inclusions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('parent_id');
            $t->unsignedBigInteger('child_id');
            $t->string('relation')->default('bundle');
            $t->integer('quantity')->default(1);
            $t->integer('sort_order')->default(0);
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
        });
        // kodeine Metable reads this on any attribute miss; without the stub the resource
        // payload cannot be built at all (see the switch-state test below).
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('key')->nullable();
            $t->text('value')->nullable();
            $t->string('type')->nullable();
        });
        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
        });

        $stub = \Mockery::mock(ServiceAvailabilityService::class);
        $stub->shouldReceive('availableVerticalsForCity')->andReturn([]);
        $stub->shouldReceive('allVerticals')->andReturn([]);
        $this->app->instance(ServiceAvailabilityService::class, $stub);

        // All three are `publish`. Status is orthogonal to membership — that is the point.
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Uncurated Palm',  'status' => 'publish', 'language' => 'en', 'is_available_product' => false, 'listing_enabled' => false],
            ['id' => 2, 'name' => 'Curated, Off',    'status' => 'publish', 'language' => 'en', 'is_available_product' => true,  'listing_enabled' => false],
            ['id' => 3, 'name' => 'Curated, Listed', 'status' => 'publish', 'language' => 'en', 'is_available_product' => true,  'listing_enabled' => true],
        ]);
    }

    /** A request whose sanctum user resolves with the given permissions (spatie-style check). */
    private function authed(Request $request, array $perms): Request
    {
        $request->setUserResolver(fn () => new class($perms) {
            public $id = 1;
            public function __construct(public array $perms) {}
            public function hasPermissionTo($p): bool
            {
                return in_array((string) $p, $this->perms, true);
            }
        });
        return $request;
    }

    private function namesFor(Request $request): array
    {
        /** @var ProductController $controller */
        $controller = app(ProductController::class);
        return $controller->fetchProducts($request)->pluck('name')->sort()->values()->all();
    }

    public function test_the_storefront_shows_only_curated_and_listed_products(): void
    {
        $this->assertSame(
            ['Curated, Listed'],
            $this->namesFor(Request::create('/api/products', 'GET')),
            'a published product that was never curated in must not reach the website',
        );
    }

    public function test_membership_alone_does_not_publish(): void
    {
        DB::table('products')->where('id', 3)->update(['listing_enabled' => false]);

        $this->assertSame(
            [],
            $this->namesFor(Request::create('/api/products', 'GET')),
            'moving a product into the catalogue must leave it unlisted until the switch is flipped',
        );
    }

    public function test_a_signed_in_shopper_is_gated_exactly_like_an_anonymous_one(): void
    {
        // The regression this test exists for: a customer's Bearer is indistinguishable from an
        // admin's, so a token-based exemption would leak the whole uncurated catalogue to anyone
        // who logged in.
        $request = Request::create('/api/products', 'GET');
        $request->headers->set('Authorization', 'Bearer a-logged-in-customer-token');

        $this->assertSame(['Curated, Listed'], $this->namesFor($request), 'a Bearer alone must not lift the catalogue gate');
    }

    public function test_admin_tooling_opts_out_explicitly_to_curate(): void
    {
        // All Products has to show what is NOT in the catalogue — it is the screen you curate from.
        $request = $this->authed(Request::create('/api/products', 'GET', ['catalog_scope' => 'all']), ['super_admin']);

        $this->assertSame(
            ['Curated, Listed', 'Curated, Off', 'Uncurated Palm'],
            $this->namesFor($request),
            'catalog_scope=all must reveal the uncurated pool to authenticated tooling',
        );
    }

    public function test_the_pdp_is_gated_exactly_like_the_lists(): void
    {
        // The list was gated but the PDP was not, so a hidden product could be opened by URL or a
        // stale link, carted, and then refused at checkout with a bare "Unavailable" — which read
        // as checkout being broken. A product the platform will not sell must 404 for shoppers.
        DB::table('products')->where('id', 1)->update(['slug' => 'uncurated-palm']);

        $request = \Illuminate\Http\Request::create('/api/products/uncurated-palm', 'GET');
        $request->merge(['slug' => 'uncurated-palm']);

        $this->expectException(\Marvel\Exceptions\MarvelNotFoundException::class);
        app(\Marvel\Http\Controllers\ProductController::class)->fetchSingleProduct($request);
    }

    public function test_the_admin_edit_screen_still_opens_uncurated_products(): void
    {
        // catalog_scope=all + Bearer: curating uncurated products is what the edit screen is FOR.
        DB::table('products')->where('id', 1)->update(['slug' => 'uncurated-palm']);

        $request = $this->authed(
            \Illuminate\Http\Request::create('/api/products/uncurated-palm', 'GET', ['catalog_scope' => 'all']),
            ['super_admin'],
        );
        $request->merge(['slug' => 'uncurated-palm']);

        $product = app(\Marvel\Http\Controllers\ProductController::class)->fetchSingleProduct($request);
        $this->assertSame('Uncurated Palm', $product->name);
    }

    public function test_a_listed_product_pdp_still_opens_for_shoppers(): void
    {
        DB::table('products')->where('id', 3)->update(['slug' => 'curated-listed']);

        $request = \Illuminate\Http\Request::create('/api/products/curated-listed', 'GET');
        $request->merge(['slug' => 'curated-listed']);

        $product = app(\Marvel\Http\Controllers\ProductController::class)->fetchSingleProduct($request);
        $this->assertSame('Curated, Listed', $product->name);
    }

    public function test_all_products_can_exclude_drafts(): void
    {
        // Rule 13: a draft is unfinished work and belongs in My Draft Products, not in the
        // repository you curate from. A negation cannot be expressed through the Prettus search
        // grammar, which is why it is a server-side param rather than another `search=` key.
        DB::table('products')->insert([
            'id' => 4, 'name' => 'Half-written Fern', 'status' => 'draft', 'language' => 'en',
            'is_available_product' => false, 'listing_enabled' => false,
        ]);
        $request = $this->authed(Request::create('/api/products', 'GET', [
            'catalog_scope'  => 'all',
            'exclude_status' => 'draft',
        ]), ['super_admin']);

        $names = $this->namesFor($request);

        $this->assertNotContains('Half-written Fern', $names, 'drafts must not appear in All Products');
        $this->assertContains('Uncurated Palm', $names, 'the uncurated pool is still the point of this screen');
    }

    public function test_a_customer_cannot_lift_the_gate_with_the_param(): void
    {
        // The security-review finding. The first cut checked bearer-token PRESENCE, which any
        // signed-in shopper satisfies — one query param away from the whole uncurated pool. The
        // opt-out now demands a resolved user with a staff permission.
        $request = $this->authed(
            Request::create('/api/products', 'GET', ['catalog_scope' => 'all']),
            ['customer'],
        );
        $request->headers->set('Authorization', 'Bearer a-real-customer-token');

        $this->assertSame(
            ['Curated, Listed'],
            $this->namesFor($request),
            'a customer with catalog_scope=all must stay gated',
        );
    }

    public function test_the_opt_out_is_refused_without_authentication(): void
    {
        // Otherwise the gate is one query parameter deep.
        $this->assertSame(
            ['Curated, Listed'],
            $this->namesFor(Request::create('/api/products', 'GET', ['catalog_scope' => 'all'])),
            'an anonymous caller must not be able to opt out of the gate',
        );
    }

    public function test_the_list_payload_carries_the_catalogue_switch_state(): void
    {
        // Available Products renders its per-row switch from this field. ProductResource is an
        // explicit allowlist, and it omitted both columns — so the switch read `undefined`, drew
        // itself OFF for every row whatever the database said, and each click wrote `true`, got a
        // payload that still said nothing, and snapped back. "The toggle does nothing."
        $payload = (new ProductResource(Product::find(3)))
            ->toArray(Request::create('/api/products', 'GET'));

        $this->assertArrayHasKey('listing_enabled', $payload, 'the row switch has nothing to render from');
        $this->assertTrue($payload['listing_enabled']);
        $this->assertArrayHasKey('is_available_product', $payload);
        $this->assertTrue($payload['is_available_product']);

        $off = (new ProductResource(Product::find(2)))
            ->toArray(Request::create('/api/products', 'GET'));
        $this->assertFalse($off['listing_enabled'], 'a curated-but-unlisted row must report OFF, not absent');
    }
}
