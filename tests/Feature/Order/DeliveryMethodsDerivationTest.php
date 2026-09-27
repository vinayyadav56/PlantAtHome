<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Repositories\CheckoutRepository;
use Tests\TestCase;

/**
 * delivery_methods derivation: origin (vendor shop state → pickup state →
 * registration state) vs destination (postal_codes → states by pincode).
 * Fixture state names only — the production rule must never hardcode any.
 */
final class DeliveryMethodsDerivationTest extends TestCase
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

        Schema::create('states', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('code')->nullable();
        });
        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });
        Schema::create('districts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });
        Schema::create('postal_codes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('pincode', 6)->index();
            $t->unsignedBigInteger('state_id')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->unsignedBigInteger('district_id')->nullable();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->json('address')->nullable();
            $t->boolean('is_active')->default(1);
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('shop_id');
            $t->boolean('is_available')->default(1);
            // supplyingShops filters these UNguarded — the fixture must have them.
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
        });
        Schema::create('vendor_pickup_locations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('state')->nullable();
            $t->boolean('is_default')->default(0);
        });

        DB::table('states')->insert([
            ['id' => 1, 'name' => 'Alpha State', 'code' => 'AS'],
            ['id' => 2, 'name' => 'Beta State', 'code' => 'BS'],
        ]);
        DB::table('cities')->insert([['id' => 1, 'name' => 'Alpha City']]);
        DB::table('postal_codes')->insert([
            ['pincode' => '111111', 'state_id' => 1, 'city_id' => 1, 'district_id' => null],
            ['pincode' => '222222', 'state_id' => 2, 'city_id' => 1, 'district_id' => null],
        ]);
        DB::table('shops')->insert([
            ['id' => 5, 'name' => 'Vendor A', 'address' => json_encode(['state' => 'Alpha State']), 'is_active' => 1],
        ]);
        DB::table('vendor_product_prices')->insert([
            ['product_id' => 100, 'shop_id' => 5, 'is_available' => 1],
        ]);
    }

    private function methods(string $pincode, array $unavailable = []): ?array
    {
        $request = new Request([
            'products' => [['product_id' => 100, 'order_quantity' => 1]],
            'shipping_address' => ['zip' => $pincode],
        ]);
        $request->setUserResolver(fn () => null);
        return (new CheckoutRepository())->deliveryMethods($request, $unavailable);
    }

    private function bySlug(?array $methods, string $slug): ?array
    {
        foreach ((array) $methods as $m) {
            if (($m['slug'] ?? null) === $slug) {
                return $m;
            }
        }
        return null;
    }

    public function test_intra_state_offers_local_not_interstate(): void
    {
        $m = $this->methods('111111');
        $this->assertNotNull($m);
        $this->assertTrue($this->bySlug($m, 'local')['available']);
        $interstate = $this->bySlug($m, 'standard_interstate');
        $this->assertFalse($interstate['available']);
        $this->assertSame('same_state_origin', $interstate['reason']);
    }

    public function test_other_state_destination_offers_standard_interstate(): void
    {
        $m = $this->methods('222222');
        $this->assertTrue($this->bySlug($m, 'standard_interstate')['available']);
    }

    public function test_unknown_pincode_omits_the_key(): void
    {
        $this->assertNull($this->methods('999999'));
    }

    public function test_unavailable_cart_falls_back_to_interstate(): void
    {
        $m = $this->methods('111111', [100]);
        $this->assertFalse($this->bySlug($m, 'local')['available']);
        $this->assertTrue($this->bySlug($m, 'standard_interstate')['available']);
    }

    public function test_pickup_location_state_beats_missing_shop_state(): void
    {
        DB::table('shops')->where('id', 5)->update(['address' => json_encode([])]);
        DB::table('vendor_pickup_locations')->insert([
            ['shop_id' => 5, 'state' => 'Beta State', 'is_default' => 1],
        ]);
        // Origin now Beta; destination Alpha → interstate available.
        $m = $this->methods('111111');
        $this->assertTrue($this->bySlug($m, 'standard_interstate')['available']);
    }
}
