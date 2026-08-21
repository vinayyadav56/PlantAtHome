<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\ProductRepository;
use Tests\TestCase;

/**
 * The PDP's identity contract: a slug resolves to THE product owning that slug, never to a
 * product whose numeric id happens to equal the slug's leading digits.
 *
 * The bug this pins: `where('slug', $x)->orWhere('id', $x)` let MySQL coerce
 * '30-cm-spiral-stick-lucky-bamboo-plant' to 30, so the URL opened product id 30 ("Exotic Veg
 * Box"). 32 live catalog slugs start with a size prefix; every one resolved to an arbitrary
 * product. fetchSingleProduct now delegates to findBySlugOrId, which these tests pin.
 */
final class ProductSlugResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
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
            $t->string('slug');
            $t->string('language')->default('en');
            $t->string('status')->default('publish');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        // The live shape of the bug: a decoy at the id the slug coerces to, and the real product.
        DB::table('products')->insert([
            ['id' => 30, 'name' => 'Exotic Veg Box', 'slug' => 'exotic-veg-box', 'language' => 'en', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 1631, 'name' => '30 cm Spiral Stick Lucky Bamboo Plant', 'slug' => '30-cm-spiral-stick-lucky-bamboo-plant', 'language' => 'en', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function repo(): ProductRepository
    {
        return app(ProductRepository::class);
    }

    public function test_a_digit_prefixed_slug_resolves_to_its_own_product(): void
    {
        $p = $this->repo()->findBySlugOrId('30-cm-spiral-stick-lucky-bamboo-plant');
        $this->assertSame(1631, (int) $p->id, 'the slug coerced to id 30 — the storefront bug is back');
        $this->assertSame('30 cm Spiral Stick Lucky Bamboo Plant', $p->name);
    }

    public function test_a_fully_numeric_param_still_resolves_by_id(): void
    {
        $p = $this->repo()->findBySlugOrId('30');
        $this->assertSame('Exotic Veg Box', $p->name);
        $p2 = $this->repo()->findBySlugOrId(1631);
        $this->assertSame('30-cm-spiral-stick-lucky-bamboo-plant', $p2->slug);
    }

    public function test_an_unknown_slug_is_a_404_not_a_coerced_match(): void
    {
        // The live proof of the old bug: /products/8-inch-anything-fake-slug returned product 8.
        $this->expectException(ModelNotFoundException::class);
        $this->repo()->findBySlugOrId('8-inch-anything-fake-slug');
    }

    public function test_resolution_is_scoped_to_the_default_language(): void
    {
        DB::table('products')->insert([
            ['id' => 2000, 'name' => 'Hindi row', 'slug' => 'shared-slug', 'language' => 'hi', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2001, 'name' => 'English row', 'slug' => 'shared-slug', 'language' => 'en', 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $p = $this->repo()->findBySlugOrId('shared-slug');
        $this->assertSame('English row', $p->name, 'the old unparenthesized OR let the id branch escape the language filter');
    }
}
