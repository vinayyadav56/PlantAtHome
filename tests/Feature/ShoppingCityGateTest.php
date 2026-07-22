<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Repositories\CheckoutRepository;
use Tests\TestCase;

/**
 * Shopping-City redesign — the checkout mismatch gate (CheckoutRepository::
 * shoppingCityMismatch) + the geo canon it leans on. The gate is BACKWARD-COMPATIBLE
 * by construction: no `shopping_city` in the request ⇒ null (old clients unaffected).
 * Address-city resolution ladder: postal master (by pincode) > rg_city > typed city;
 * both sides alias-normalized (Gurgaon ≡ Gurugram).
 */
final class ShoppingCityGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);
        DB::purge('sqlite');

        // AvailabilityService's constructor chain reads settings — replicate it so the
        // normalizer resolves for real instead of vacuously passing via the fail-open catch.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert([
            ['options' => json_encode([]), 'language' => 'en', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Minimal geo canon replicas (only the columns the gate's joins read).
        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('state_name')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('status')->default('active');
            $t->boolean('is_serviceable')->default(true);
        });
        Schema::create('states', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });
        Schema::create('districts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });
        Schema::create('postal_codes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('pincode');
            $t->unsignedBigInteger('state_id')->nullable();
            $t->unsignedBigInteger('district_id')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
        });

        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Gurugram', 'state_name' => 'Haryana', 'lat' => 28.4595, 'lng' => 77.0266, 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 2, 'name' => 'Delhi', 'state_name' => 'Delhi', 'lat' => 28.7041, 'lng' => 77.1025, 'status' => 'active', 'is_serviceable' => 1],
        ]);
        DB::table('postal_codes')->insert([
            ['pincode' => '122001', 'city_id' => 1],
            ['pincode' => '110001', 'city_id' => 2],
        ]);
    }

    private function gate(array $request): ?array
    {
        return (new CheckoutRepository())->shoppingCityMismatch($request);
    }

    public function test_no_shopping_city_means_no_gate_old_clients_unaffected(): void
    {
        $this->assertNull($this->gate([
            'shipping_address' => ['city' => 'Delhi', 'zip' => '110001'],
        ]));
    }

    public function test_matching_city_passes(): void
    {
        $this->assertNull($this->gate([
            'shopping_city'    => 'Gurugram',
            'shipping_address' => ['city' => 'Gurugram', 'zip' => '122001'],
        ]));
    }

    public function test_alias_never_falsely_blocks(): void
    {
        // Shopping "Gurgaon" (exonym) with a canonical Gurugram address must pass.
        $this->assertNull($this->gate([
            'shopping_city'    => 'Gurgaon',
            'shipping_address' => ['city' => 'Gurugram', 'zip' => '122001'],
        ]));
    }

    public function test_mismatch_blocks_with_structured_payload(): void
    {
        $m = $this->gate([
            'shopping_city'    => 'Gurugram',
            'shipping_address' => ['city' => 'Delhi', 'zip' => '110001'],
        ]);
        $this->assertNotNull($m);
        $this->assertSame('SHOPPING_CITY_MISMATCH', $m['code']);
        $this->assertSame('Gurugram', $m['shopping_city']);
        $this->assertSame('Delhi', $m['address_city']);
        $this->assertStringContainsString('Delhi', $m['message']);
        $this->assertStringContainsString('Gurugram', $m['message']);
    }

    public function test_postal_master_beats_a_lying_typed_city(): void
    {
        // Typed city says Gurugram but the pincode is canonical Delhi — postal wins → block.
        $m = $this->gate([
            'shopping_city'    => 'Gurugram',
            'shipping_address' => ['city' => 'Gurugram', 'zip' => '110001'],
        ]);
        $this->assertNotNull($m);
        $this->assertSame('Delhi', $m['address_city']);
    }

    public function test_rg_city_wins_over_typed_city_when_no_postal_match(): void
    {
        $m = $this->gate([
            'shopping_city'    => 'Gurugram',
            'shipping_address' => ['city' => 'Gurugram', 'rg_city' => 'Delhi', 'zip' => '999999'],
        ]);
        $this->assertNotNull($m);
        $this->assertSame('Delhi', $m['address_city']);
    }

    public function test_no_resolvable_address_city_means_no_gate(): void
    {
        $this->assertNull($this->gate([
            'shopping_city'    => 'Gurugram',
            'shipping_address' => ['street_address' => 'somewhere'],
        ]));
    }

    public function test_delhi_postal_districts_normalize_to_delhi(): void
    {
        $norm = app(\Marvel\Services\AvailabilityService::class);
        foreach (['West Delhi', 'North West Delhi', 'Shahdara', 'New Delhi'] as $sub) {
            $this->assertSame('delhi', $norm->normalizeCityKey($sub), $sub);
        }
        // A Delhi-district address city must therefore pass the gate for a Delhi shopper.
        $this->assertNull($this->gate([
            'shopping_city'    => 'Delhi',
            'shipping_address' => ['city' => 'West Delhi', 'zip' => '999998'],
        ]));
    }

    public function test_city_without_supply_is_out_of_stock_gated(): void
    {
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city');
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
        });

        $repo = new CheckoutRepository();

        // No supply anywhere → declared shopping city is browse-only.
        $blocked = $repo->shoppingCityOutOfStock(['shopping_city' => 'Gurugram']);
        $this->assertNotNull($blocked);
        $this->assertSame('CITY_OUT_OF_STOCK', $blocked['code']);
        $this->assertStringContainsString('out of stock in Gurugram', $blocked['message']);

        // Old clients that send no shopping_city are never gated.
        $this->assertNull($repo->shoppingCityOutOfStock([]));

        // A single supplied product flips the city back to orderable — including
        // via the Gurgaon alias.
        DB::table('product_city_availability')->insert([
            ['product_id' => 11, 'city' => 'gurugram', 'has_local' => 1, 'has_courier' => 0],
        ]);
        $this->assertNull($repo->shoppingCityOutOfStock(['shopping_city' => 'Gurgaon']));
    }

    public function test_reverse_geocode_service_nearest_city_fallback(): void
    {
        // No Google key in tests → the service must fall back to the nearest canon city.
        putenv('GOOGLE_MAPS_SERVER_KEY');
        putenv('GOOGLE_MAP_API_KEY');
        $svc = app(\Marvel\Services\ReverseGeocodeService::class);
        $out = $svc->resolve(28.46, 77.03); // ~Gurugram
        $this->assertSame('Gurugram', $out['city']);
        $this->assertSame('gurugram', $out['normalized_city']);
        $this->assertSame(1, (int) $out['city_id']);
        $this->assertTrue($out['is_serviceable']);
    }
}
