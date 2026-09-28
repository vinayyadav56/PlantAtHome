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

        // `name` is required by postalGeo()'s join. It was never needed while these tests only
        // exercised the keyless nearest-city path — extracting a pincode is what reaches it.
        Schema::create('states', function (Blueprint $t) {
            $t->bigIncrements('id')->from(1);
            $t->string('name')->nullable();
        });
        Schema::create('districts', function (Blueprint $t) {
            $t->bigIncrements('id')->from(1);
            $t->string('name')->nullable();
        });
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

    /**
     * A Google-backed pin must fill the fields the address form cannot otherwise get:
     * pincode, formatted_address, country and area. Production returned null for ALL of these
     * on every pin because no server key was configured, so the service never called Google and
     * silently fell through to the nearest-city guess — which reads as a working answer.
     */
    public function test_a_google_backed_pin_fills_pincode_country_and_area(): void
    {
        config(['location.google_maps_key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            'maps.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'results' => [[
                    'formatted_address'  => '12, 5th Main Rd, Indiranagar, Bengaluru, Karnataka 560038, India',
                    'address_components' => [
                        ['long_name' => '5th Main Rd',  'short_name' => '5th Main Rd', 'types' => ['route']],
                        ['long_name' => 'Indiranagar',  'short_name' => 'Indiranagar', 'types' => ['sublocality_level_1', 'sublocality']],
                        ['long_name' => 'Bengaluru',    'short_name' => 'Bengaluru',   'types' => ['locality']],
                        ['long_name' => 'Bangalore Urban', 'short_name' => 'Bangalore Urban', 'types' => ['administrative_area_level_2']],
                        ['long_name' => 'Karnataka',    'short_name' => 'KA',          'types' => ['administrative_area_level_1']],
                        ['long_name' => 'India',        'short_name' => 'IN',          'types' => ['country']],
                        ['long_name' => '560038',       'short_name' => '560038',      'types' => ['postal_code']],
                    ],
                ]],
            ], 200),
        ]);

        $res = $this->getJson('/api/geo/reverse?lat=12.9716&lng=77.5946');
        $res->assertOk();

        $this->assertSame('560038', $res->json('pincode'));
        $this->assertSame('India', $res->json('country'));
        $this->assertSame('IN', $res->json('country_code'));
        $this->assertSame('Indiranagar', $res->json('area'), 'sublocality must outrank route');
        $this->assertNotNull($res->json('formatted_address'));
        $this->assertSame('google', $res->json('source'));
    }

    /** With no key the answer is still usable, but it must ADMIT it is a guess. */
    public function test_a_keyless_pin_is_labelled_as_a_nearest_city_guess(): void
    {
        config(['location.google_maps_key' => null]);

        $res = $this->getJson('/api/geo/reverse?lat=28.46&lng=77.03');
        $res->assertOk();

        $this->assertSame('Gurugram', $res->json('city'));
        $this->assertSame('nearest_city', $res->json('source'));
        $this->assertNull($res->json('pincode'), 'a nearest-city guess cannot know a pincode');
    }

    /** Area must never simply restate the city — a filled field carrying no information. */
    public function test_area_is_dropped_when_it_only_repeats_the_city(): void
    {
        config(['location.google_maps_key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            'maps.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'results' => [[
                    'formatted_address'  => 'Gurugram, Haryana, India',
                    'address_components' => [
                        ['long_name' => 'Gurugram', 'short_name' => 'Gurugram', 'types' => ['sublocality_level_1']],
                        ['long_name' => 'Gurugram', 'short_name' => 'Gurugram', 'types' => ['locality']],
                        ['long_name' => 'Haryana',  'short_name' => 'HR',       'types' => ['administrative_area_level_1']],
                        ['long_name' => 'India',    'short_name' => 'IN',       'types' => ['country']],
                    ],
                ]],
            ], 200),
        ]);

        $res = $this->getJson('/api/geo/reverse?lat=28.47&lng=77.04');
        $res->assertOk();
        $this->assertNull($res->json('area'));
    }

    /**
     * A key that is PRESENT but rejected still falls through to the nearest-city guess, and must
     * say so. Stamping the source from "was a key configured?" reported `google` while handing
     * back a 50km guess — which is exactly the failure a restricted key produces, and exactly the
     * thing `source` exists to make visible.
     */
    public function test_a_rejected_key_is_reported_as_a_guess_not_as_google(): void
    {
        config(['location.google_maps_key' => 'restricted-key-that-google-refuses']);
        \Illuminate\Support\Facades\Http::fake([
            'maps.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'status'        => 'REQUEST_DENIED',
                'error_message' => 'This IP is not authorized to use this API key.',
                'results'       => [],   // byte-identical to a genuine ZERO_RESULTS
            ], 200),
        ]);

        $res = $this->getJson('/api/geo/reverse?lat=28.46&lng=77.03');
        $res->assertOk();

        $this->assertSame('nearest_city', $res->json('source'));
        $this->assertSame('Gurugram', $res->json('city'), 'must still fail OPEN, never block checkout');
        $this->assertNull($res->json('pincode'));
    }

    /** A Google timeout must not escape, and must not masquerade as a Google answer either. */
    public function test_a_google_timeout_fails_open_and_is_labelled_a_guess(): void
    {
        config(['location.google_maps_key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        $res = $this->getJson('/api/geo/reverse?lat=28.46&lng=77.03');
        $res->assertOk();
        $this->assertSame('nearest_city', $res->json('source'));
        $this->assertSame('Gurugram', $res->json('city'));
    }
}
