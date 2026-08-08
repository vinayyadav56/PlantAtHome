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
 * GET /api/products had NO default status filter, so a public caller that omitted
 * `search=status:publish` received the whole draft pool too — thousands of imported-catalogue
 * rows. That both leaked unpublished products and inflated the row set the endpoint then had to
 * serialize (a dominant cause of the slow tail).
 *
 * fetchProducts() now defaults anonymous (no Bearer) requests to published-only, unless the
 * caller explicitly constrains status. This exercises the query builder directly — asserting on
 * the returned rows' status — which pins the behaviour without dragging in the full serialize
 * path (whose N+1 accessors are a separate concern, verified live post-deploy).
 *
 * Hand-built sqlite tables, the ImageBatchTestCase idiom already used across this suite.
 */
final class ProductStatusDefaultTest extends TestCase
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
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // withCount('reviews')/withAvg('reviews','rating') + the eager loads need these tables
        // to exist even when empty.
        Schema::create('reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->integer('rating')->default(0);
            $t->timestamps();
            $t->softDeletes(); // Review uses SoftDeletes → withCount/withAvg add deleted_at IS NULL
        });
        Schema::create('availabilities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('bookable_type')->nullable();
            $t->date('from')->nullable();
            $t->date('to')->nullable();
        });

        // fetchProducts() consults ServiceAvailabilityService (which otherwise reads cities +
        // global vertical settings). Its vertical gate no-ops when nothing is disabled, so a
        // stub returning empty verticals is faithful to the tested behaviour and keeps this test
        // from re-deriving that whole schema by hand.
        $stub = \Mockery::mock(ServiceAvailabilityService::class);
        $stub->shouldReceive('availableVerticalsForCity')->andReturn([]);
        $stub->shouldReceive('allVerticals')->andReturn([]);
        $this->app->instance(ServiceAvailabilityService::class, $stub);

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Published Palm',  'status' => 'publish',      'language' => 'en'],
            ['id' => 2, 'name' => 'Draft Fern',      'status' => 'draft',        'language' => 'en'],
            ['id' => 3, 'name' => 'Pending Cactus',  'status' => 'under_review', 'language' => 'en'],
        ]);
    }

    private function statusesFor(Request $request): array
    {
        /** @var ProductController $controller */
        $controller = app(ProductController::class);
        return $controller->fetchProducts($request)->pluck('status')->sort()->values()->all();
    }

    public function test_anonymous_request_defaults_to_published_only(): void
    {
        $request = Request::create('/api/products', 'GET');

        $this->assertSame(['publish'], $this->statusesFor($request), 'a public listing must not leak drafts');
    }

    public function test_an_explicit_status_search_disables_the_publish_default(): void
    {
        // When the caller constrains status themselves (Prettus `search=status:draft`), MY code
        // must NOT also force publish — it steps aside. The actual narrowing to draft is the
        // repository's RequestCriteria (covered elsewhere); what this pins is that fetchProducts
        // no longer clamps the set to published, so the draft is reachable.
        $request = Request::create('/api/products', 'GET', ['search' => 'status:draft']);
        $statuses = $this->statusesFor($request);

        $this->assertContains('draft', $statuses, 'an explicit status filter must not be overridden by the publish default');
        $this->assertNotSame(['publish'], $statuses, 'the publish default must step aside when status is explicit');
    }

    public function test_a_bearer_request_is_not_forced_to_published(): void
    {
        // Admin/vendor tooling always carries a Bearer and manages every status.
        $request = Request::create('/api/products', 'GET');
        $request->headers->set('Authorization', 'Bearer some-admin-token');

        $this->assertSame(['draft', 'publish', 'under_review'], $this->statusesFor($request), 'authenticated tooling sees all statuses');
    }
}
