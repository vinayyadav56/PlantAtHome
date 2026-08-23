<?php

namespace Tests\Feature\Nursery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * The admin All Vendors page groups vendors by business vertical. A vendor has
 * no vertical of its own — its verticals are those of the categories it
 * supplies — and V2 nurseries resolve that through the LEGACY tables via
 * legacy_id (mirrors ShopController's whereHas('categories.type')).
 */
class NurseryVerticalFilterTest extends NurseryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stub only the legacy columns the vertical subquery reads.
        Schema::create('types', function (Blueprint $t) {
            $t->id();
            $t->string('slug');
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('type_id');
        });
        Schema::create('category_shop', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('category_id');
        });
    }

    public function test_vertical_param_filters_through_legacy_categories(): void
    {
        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));

        foreach ([['Plant Vendor', 'plant-vendor', 34], ['Pot Vendor', 'pot-vendor', 35]] as [$name, $slug, $legacyId]) {
            $this->postJson('/api/v1/nurseries', ['name' => $name, 'slug' => $slug], $admin)->assertStatus(201);
            DB::table('nursery_nurseries')->where('slug', $slug)->update(['legacy_id' => $legacyId]);
        }

        DB::table('types')->insert([
            ['id' => 1, 'slug' => 'plants'],
            ['id' => 2, 'slug' => 'pots'],
        ]);
        DB::table('categories')->insert([
            ['id' => 10, 'type_id' => 1],
            ['id' => 11, 'type_id' => 2],
        ]);
        // Shop 34 supplies a plants category; shop 35 a pots category.
        DB::table('category_shop')->insert([
            ['shop_id' => 34, 'category_id' => 10],
            ['shop_id' => 35, 'category_id' => 11],
        ]);

        // No vertical → both vendors.
        $all = $this->getJson('/api/v1/nurseries', $admin)->assertStatus(200)->json('data');
        $this->assertEqualsCanonicalizing(
            ['plant-vendor', 'pot-vendor'],
            collect($all)->pluck('slug')->all(),
        );

        // vertical=plants → only the vendor supplying plants categories.
        $plants = $this->getJson('/api/v1/nurseries?vertical=plants', $admin)->assertStatus(200)->json('data');
        $this->assertSame(['plant-vendor'], collect($plants)->pluck('slug')->all());

        // A vertical no vendor supplies → empty page, not an error.
        $this->getJson('/api/v1/nurseries?vertical=seeds', $admin)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
