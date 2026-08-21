<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\ProductController;
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
        $request = Request::create('/api/products', 'GET', ['catalog_scope' => 'all']);
        $request->headers->set('Authorization', 'Bearer an-admin-token');

        $this->assertSame(
            ['Curated, Listed', 'Curated, Off', 'Uncurated Palm'],
            $this->namesFor($request),
            'catalog_scope=all must reveal the uncurated pool to authenticated tooling',
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
}
