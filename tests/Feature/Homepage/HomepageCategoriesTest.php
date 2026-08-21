<?php

namespace Tests\Feature\Homepage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `GET /api/categories?home=1` — the query behind the per-vertical homepage rows.
 *
 * In-memory sqlite with hand-built minimal tables (the ImageBatchTestCase idiom
 * used by ProductFilterFacetsTest), because the full marvel schema is far too
 * heavy for what is being asserted.
 *
 * The two things genuinely worth pinning are the filter itself and the CACHE
 * KEY. The controller's own comment records that an earlier version omitted the
 * vertical from the key, so one unfiltered request poisoned every vertical and
 * Plants started showing Tools categories. `home` is the same hazard, and a
 * cache bug is invisible in a filter-only test — hence the isolation cases.
 */
class HomepageCategoriesTest extends TestCase
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
        Cache::flush(); // the endpoint caches for 600s; never inherit a sibling test's entry

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
            // the columns under test
            $t->boolean('show_on_homepage')->default(false);
            $t->integer('homepage_sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        // children() does ->withCount('products') through this pivot.
        Schema::create('products', function (Blueprint $t) {
            // Master Catalog membership + listing switch. Defaulted TRUE in stubs, not FALSE:
            // production starts empty by design, but a fixture that had to opt every product in
            // would make each existing test assert the new gate instead of what it was written for.
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->bigIncrements('id');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });

        $this->seedCategories();
    }

    private function seedCategories(): void
    {
        $plants = DB::table('types')->insertGetId(['name' => 'Plants', 'slug' => 'plants', 'language' => 'en']);
        $tools  = DB::table('types')->insertGetId(['name' => 'Tools', 'slug' => 'tools', 'language' => 'en']);

        $cat = function (string $name, int $type, array $attrs = []) {
            DB::table('categories')->insert(array_merge([
                'name'                => $name,
                'slug'                => strtolower(str_replace(' ', '-', $name)),
                'language'            => 'en',
                'type_id'             => $type,
                'parent'              => null,
                'show_on_homepage'    => false,
                'homepage_sort_order' => 0,
                'is_active'           => true,
            ], $attrs));
        };

        // Plants — deliberately inserted out of order, and with sort orders that
        // disagree with both insertion order and alphabetical order, so a passing
        // assertion cannot be an accident of either.
        $cat('Zebra Plants', $plants, ['show_on_homepage' => true, 'homepage_sort_order' => 1]);
        $cat('Indoor', $plants, ['show_on_homepage' => true, 'homepage_sort_order' => 3]);
        $cat('Aloe', $plants, ['show_on_homepage' => true, 'homepage_sort_order' => 2]);
        $cat('Hidden Plant', $plants);                                   // not flagged
        $cat('Retired Plant', $plants, ['show_on_homepage' => true, 'is_active' => false]);

        // A second vertical, all flagged — the cross-vertical bleed guard.
        $cat('Pruners', $tools, ['show_on_homepage' => true, 'homepage_sort_order' => 1]);
        $cat('Watering Cans', $tools, ['show_on_homepage' => true, 'homepage_sort_order' => 2]);
    }

    /** @return string[] category names, in response order */
    private function names(array $json): array
    {
        return array_map(static fn ($c) => $c['name'], $json['data'] ?? []);
    }

    public function test_home_filter_returns_only_flagged_active_categories_of_that_vertical(): void
    {
        $res = $this->getJson('/api/categories?home=1&type=plants&parent=null&limit=100&language=en');
        $res->assertOk();

        $names = $this->names($res->json());

        // Unflagged and de-activated categories are both excluded...
        $this->assertNotContains('Hidden Plant', $names, 'an unflagged category reached the homepage');
        $this->assertNotContains('Retired Plant', $names, 'an INACTIVE category reached the homepage');
        // ...and so is the other vertical, which is the bug this feature exists to fix.
        $this->assertNotContains('Pruners', $names, 'a Tools category leaked into the Plants row');

        $this->assertSame(['Zebra Plants', 'Aloe', 'Indoor'], $names,
            'expected homepage_sort_order (1,2,3), not insertion or alphabetical order');
    }

    public function test_without_the_flag_the_endpoint_is_unchanged(): void
    {
        // Every existing caller (filter sidebar, mega-menu, admin) omits `home`
        // and must keep seeing everything, including unflagged and inactive rows.
        $names = $this->names($this->getJson('/api/categories?type=plants&parent=null&limit=100&language=en')->json());

        $this->assertContains('Hidden Plant', $names);
        $this->assertContains('Retired Plant', $names);
        $this->assertCount(5, $names);
    }

    public function test_home_and_unfiltered_responses_do_not_share_a_cache_entry(): void
    {
        // Order matters: warm the UNFILTERED entry first, then ask for the
        // filtered one. If `home` were missing from the cache key, the second
        // call would be served the first's payload and quietly return everything.
        $all = $this->names($this->getJson('/api/categories?type=plants&parent=null&limit=100&language=en')->json());
        $home = $this->names($this->getJson('/api/categories?home=1&type=plants&parent=null&limit=100&language=en')->json());

        $this->assertCount(5, $all);
        $this->assertCount(3, $home, 'the filtered request was served the unfiltered cache entry');
    }

    /**
     * The other vertical, asserted in its OWN test method — deliberately, and the
     * reason is worth recording.
     *
     * ⚠️ Two requests for DIFFERENT verticals in one test method return nothing
     * for the second. The Prettus repository is resolved once per application
     * instance and its `whereHas` is not reset between requests, so the second
     * filter ANDs onto the first (plants AND tools = zero rows). This is NOT
     * caused by `home=1` — it reproduces with only the pre-existing `type`
     * filter — and it does not affect production, where php-fpm gives every
     * request a fresh process. It bites here because Laravel reuses one
     * container across getJson() calls within a method, and a fresh one per
     * method. If this app is ever moved onto Octane or another long-lived
     * worker, category filtering breaks and this is where to start.
     */
    public function test_a_second_vertical_gets_only_its_own_categories(): void
    {
        $this->assertSame(
            ['Pruners', 'Watering Cans'],
            $this->names($this->getJson('/api/categories?home=1&type=tools&parent=null&limit=100&language=en')->json())
        );
    }

    public function test_a_category_write_busts_the_homepage_cache(): void
    {
        $this->assertCount(3, $this->names($this->getJson('/api/categories?home=1&type=plants&parent=null&limit=100&language=en')->json()));

        DB::table('categories')->where('name', 'Hidden Plant')->update(['show_on_homepage' => true]);
        // The controller versions its key off `categories:ver`; admin writes bump
        // it. Without the bump a freshly flagged category stays invisible for
        // 10 minutes and reads as "the toggle does nothing".
        Cache::forever('categories:ver', (int) Cache::get('categories:ver', 1) + 1);

        $this->assertCount(4, $this->names($this->getJson('/api/categories?home=1&type=plants&parent=null&limit=100&language=en')->json()));
    }
}
