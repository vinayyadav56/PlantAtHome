<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POST /api/cart/validate-city — the change-shopping-city cart migration
 * (Shopping-City redesign): given a target city + cart lines, split into
 * available (kept, repriced when pricing resolves) and unavailable (dropped).
 * Three availability regimes mirror AvailabilityService::cityScopeProductIds:
 *   1. city mapped in product_city_availability → STRICT id list;
 *   2. serviceable-but-unmapped city → full catalog (everything keeps);
 *   3. non-serviceable city → everything drops.
 */
final class CartValidateCityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        // Settings row so AvailabilityService/PricingService construction resolves.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert([
            ['options' => json_encode([]), 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('state_name')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('status')->default('active');
            $t->boolean('is_serviceable')->default(true);
        });
        DB::table('cities')->insert([
            // Gurugram: serviceable + MAPPED (strict inventory below).
            ['id' => 1, 'name' => 'Gurugram', 'state_name' => 'Haryana', 'status' => 'active', 'is_serviceable' => 1],
            // Jaipur: serviceable, no inventory mapping → full catalog.
            ['id' => 2, 'name' => 'Jaipur', 'state_name' => 'Rajasthan', 'status' => 'active', 'is_serviceable' => 1],
            // Shimla: NOT serviceable → empty scope.
            ['id' => 3, 'name' => 'Shimla', 'state_name' => 'Himachal Pradesh', 'status' => 'active', 'is_serviceable' => 0],
        ]);

        // Mirrors the real table (see 2026_08_05_000400_per_variant_product_city_availability).
        // variation_option_id is NOT optional scaffolding: the projection became
        // per-variant, with 0 meaning "the product-level rollup", and the query under
        // test filters on it. A stub without it is a fictional database — which is
        // exactly why this test started failing while production behaviour was fine.
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city');
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
            $t->decimal('min_price', 12, 2)->nullable();
            $t->decimal('display_price', 14, 2)->nullable();
            $t->integer('stock')->nullable();
            $t->integer('stock_override')->nullable();
            $t->integer('vendor_count')->default(0);
            $t->timestamp('updated_at')->nullable();
        });
        // Gurugram carries product 11 only (12 is NOT available there).
        // variation_option_id 0 = the product-level rollup row.
        DB::table('product_city_availability')->insert([
            ['product_id' => 11, 'city' => 'gurugram', 'variation_option_id' => 0, 'has_local' => 1, 'has_courier' => 0, 'vendor_count' => 1],
        ]);
    }

    public function test_mapped_city_splits_available_and_unavailable(): void
    {
        $res = $this->postJson('/api/cart/validate-city', [
            'city'  => 'Gurugram',
            'items' => [
                ['product_id' => 11, 'quantity' => 2],
                ['product_id' => 12, 'variation_option_id' => 5, 'quantity' => 1],
            ],
        ]);
        $res->assertOk();
        $data = $res->json('data');
        $this->assertSame('Gurugram', $data['city']);
        $this->assertCount(1, $data['available']);
        $this->assertSame(11, $data['available'][0]['product_id']);
        $this->assertSame(2, $data['available'][0]['quantity']);
        $this->assertCount(1, $data['unavailable']);
        $this->assertSame(12, $data['unavailable'][0]['product_id']);
        $this->assertSame(5, $data['unavailable'][0]['variation_option_id']);
    }

    public function test_alias_city_uses_same_strict_scope(): void
    {
        // "Gurgaon" must normalize to the gurugram projection, not fall to full catalog.
        $res = $this->postJson('/api/cart/validate-city', [
            'city'  => 'Gurgaon',
            'items' => [['product_id' => 12]],
        ]);
        $res->assertOk();
        $this->assertCount(0, $res->json('data.available'));
        $this->assertCount(1, $res->json('data.unavailable'));
    }

    public function test_serviceable_city_without_supply_is_display_only(): void
    {
        // Display-only policy: Jaipur is serviceable but NO nursery supplies it —
        // the catalog shows there, but a cart cannot survive a switch to it.
        $res = $this->postJson('/api/cart/validate-city', [
            'city'  => 'Jaipur',
            'items' => [['product_id' => 11], ['product_id' => 999]],
        ]);
        $res->assertOk();
        $this->assertCount(0, $res->json('data.available'));
        $this->assertCount(2, $res->json('data.unavailable'));
    }

    public function test_non_serviceable_city_drops_everything(): void
    {
        $res = $this->postJson('/api/cart/validate-city', [
            'city'  => 'Shimla',
            'items' => [['product_id' => 11]],
        ]);
        $res->assertOk();
        $this->assertCount(0, $res->json('data.available'));
        $this->assertCount(1, $res->json('data.unavailable'));
    }

    public function test_validation_rejects_missing_city_and_oversized_carts(): void
    {
        $this->postJson('/api/cart/validate-city', ['items' => []])->assertStatus(422);

        $items = array_map(fn ($i) => ['product_id' => $i], range(1, 101));
        $this->postJson('/api/cart/validate-city', ['city' => 'Gurugram', 'items' => $items])
            ->assertStatus(422);
    }
}
