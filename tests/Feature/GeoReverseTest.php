<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /api/geo/reverse — the map-pin → city resolution endpoint (Shopping-City
 * redesign). With no Google key configured (test env), the service must still
 * resolve via the nearest cities-canon row (haversine ≤ 50 km); far-from-any-city
 * pins return nulls rather than a fabricated city. Coordinates are validated.
 */
final class GeoReverseTest extends TestCase
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
            ['id' => 1, 'name' => 'Gurugram', 'state_name' => 'Haryana', 'lat' => 28.4595, 'lng' => 77.0266, 'status' => 'active', 'is_serviceable' => 1],
        ]);

        Schema::create('states', fn (Blueprint $t) => $t->bigIncrements('id')->from(1));
        Schema::create('districts', fn (Blueprint $t) => $t->bigIncrements('id')->from(1));
        Schema::create('postal_codes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('pincode');
            $t->unsignedBigInteger('state_id')->nullable();
            $t->unsignedBigInteger('district_id')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
        });
    }

    public function test_pin_near_canon_city_resolves_with_serviceability(): void
    {
        $res = $this->getJson('/api/geo/reverse?lat=28.46&lng=77.03');
        $res->assertOk();
        $res->assertJson([
            'city'            => 'Gurugram',
            'normalized_city' => 'gurugram',
            'city_id'         => 1,
            'is_serviceable'  => true,
        ]);
    }

    public function test_pin_far_from_any_city_returns_nulls_not_a_guess(): void
    {
        // Middle of the Bay of Bengal — > 50 km from every canon city.
        $res = $this->getJson('/api/geo/reverse?lat=15.0&lng=88.0');
        $res->assertOk();
        $this->assertNull($res->json('city'));
        $this->assertNull($res->json('city_id'));
        $this->assertFalse($res->json('is_serviceable'));
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->getJson('/api/geo/reverse')->assertStatus(422);
        $this->getJson('/api/geo/reverse?lat=91&lng=77')->assertStatus(422);
        $this->getJson('/api/geo/reverse?lat=28.4&lng=181')->assertStatus(422);
    }
}
