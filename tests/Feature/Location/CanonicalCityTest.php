<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Address;
use Marvel\Services\AvailabilityService;
use Marvel\Services\LocationNormalizer;
use Tests\TestCase;

/**
 * One city, many districts.
 *
 *   India > Delhi (state) > Delhi (city) > South Delhi (district) > 110017 > Saket
 *
 * Every external address provider — Google, IP geolocation, India Post — answers "which city?"
 * with an administrative district, so "South Delhi" and "New Delhi" arrive in the `city` field and,
 * stored as-is, behave like separate cities. The catalogue splits, the shopping-city gate
 * mismatches, and the dashboard reports Delhi three times.
 *
 * The rule has two halves and BOTH are load-bearing: a row is a subdivision when its name is an
 * administrative variant of another city in the same state AND that other city is one we actually
 * ship to. Drop the second half and the rule eats genuine standalone districts — "East Siang" is a
 * real place, not a slice of Siang.
 */
final class CanonicalCityTest extends TestCase
{
    /** Every Delhi NCT district, including the three the master stores with a DOUBLE SPACE. */
    public static function delhiDistricts(): array
    {
        return [
            'South Delhi'        => ['South Delhi'],
            'North Delhi'        => ['North Delhi'],
            'East Delhi'         => ['East Delhi'],
            'West Delhi'         => ['West Delhi'],
            'New Delhi'          => ['New Delhi'],
            'Central Delhi'      => ['Central Delhi'],
            'South East Delhi'   => ['South East Delhi'],
            'South West Delhi'   => ['South West Delhi'],
            'North East Delhi'   => ['North East Delhi'],
            'North West Delhi'   => ['North West Delhi'],
            // The master really holds these with two spaces. strtolower(trim()) leaves the double
            // space in place, so every one of them missed the alias table entirely.
            'North East  Delhi (double space)' => ['North East  Delhi'],
            'North West  Delhi (double space)' => ['North West  Delhi'],
            'South West  Delhi (double space)' => ['South West  Delhi'],
        ];
    }

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
        // The cities index is memoized for the life of the process. Every test here rebuilds the
        // table from scratch, so without this the second test resolves against the first one's ids.
        LocationNormalizer::flush();

        Schema::create('states', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });
        Schema::create('districts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('state_id');
            $t->string('name');
        });
        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->unsignedBigInteger('state_id')->nullable();
            $t->string('state_name')->nullable();
            $t->unsignedBigInteger('district_id')->nullable();
            $t->unsignedBigInteger('parent_city_id')->nullable();
            $t->boolean('is_subdivision')->default(false);
            $t->boolean('is_serviceable')->default(false);
            $t->string('status', 16)->default('active');
        });
        Schema::create('postal_codes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('country_id')->nullable();
            $t->unsignedBigInteger('state_id');
            $t->unsignedBigInteger('district_id');
            $t->unsignedBigInteger('city_id')->nullable();
            $t->string('pincode', 10);
        });

        DB::table('states')->insert([
            ['id' => 32, 'name' => 'Delhi'],
            ['id' => 21, 'name' => 'Maharashtra'],
            ['id' => 3,  'name' => 'Arunachal Pradesh'],
        ]);

        // Delhi: the city plus its ten districts, exactly as the geo master holds them.
        DB::table('cities')->insert([
            ['id' => 290, 'name' => 'Delhi', 'state_id' => 32, 'state_name' => 'Delhi', 'is_serviceable' => true],
        ]);
        $districts = [
            289 => 'Central Delhi', 291 => 'East Delhi', 292 => 'New Delhi', 293 => 'North Delhi',
            294 => 'North East  Delhi', 295 => 'North West  Delhi', 297 => 'South Delhi',
            298 => 'South East Delhi', 299 => 'South West  Delhi', 300 => 'West Delhi',
        ];
        foreach ($districts as $id => $name) {
            DB::table('cities')->insert([
                'id' => $id, 'name' => $name, 'state_id' => 32, 'state_name' => 'Delhi', 'is_serviceable' => false,
            ]);
            DB::table('districts')->insert([
                'id' => $id, 'state_id' => 32, 'name' => trim(preg_replace('/\s+/', ' ', $name)),
            ]);
        }

        // Mumbai: the same shape via the SUFFIX rule rather than a directional prefix.
        DB::table('cities')->insert([
            ['id' => 845, 'name' => 'Mumbai', 'state_id' => 21, 'state_name' => 'Maharashtra', 'is_serviceable' => true],
            ['id' => 846, 'name' => 'Mumbai City', 'state_id' => 21, 'state_name' => 'Maharashtra', 'is_serviceable' => false],
            ['id' => 847, 'name' => 'Mumbai Suburban', 'state_id' => 21, 'state_name' => 'Maharashtra', 'is_serviceable' => false],
        ]);

        // The false-positive guard: Siang is a district, NOT a city we ship to.
        DB::table('cities')->insert([
            ['id' => 108, 'name' => 'Siang', 'state_id' => 3, 'state_name' => 'Arunachal Pradesh', 'is_serviceable' => false],
            ['id' => 96,  'name' => 'East Siang', 'state_id' => 3, 'state_name' => 'Arunachal Pradesh', 'is_serviceable' => false],
        ]);

        $this->stampSubdivisions();
    }

    /** The same pure predicate the migration and the seeder use. */
    private function stampSubdivisions(): void
    {
        foreach (DB::table('cities')->select('state_id')->distinct()->pluck('state_id') as $stateId) {
            $rows = DB::table('cities')->where('state_id', $stateId)->get(['id', 'name', 'is_serviceable'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'is_serviceable' => (bool) $r->is_serviceable])
                ->all();
            foreach (LocationNormalizer::resolveSubdivisions($rows) as $child => $parent) {
                DB::table('cities')->where('id', $child)->update([
                    'parent_city_id' => $parent, 'is_subdivision' => true, 'is_serviceable' => false,
                ]);
            }
        }
    }

    /**
     * @dataProvider delhiDistricts
     */
    public function test_every_delhi_district_normalizes_to_the_city_of_delhi(string $input): void
    {
        $res = (new LocationNormalizer())->normalize(['city' => $input, 'state' => 'Delhi']);

        $this->assertSame('Delhi', $res['city'], "$input must resolve to the city Delhi");
        $this->assertSame('Delhi', $res['state']);
        $this->assertSame(290, $res['city_id']);
        // Demoting the district must never LOSE it — that is the whole point of the split.
        $this->assertSame(
            trim(preg_replace('/\s+/', ' ', $input)),
            trim(preg_replace('/\s+/', ' ', (string) $res['district'])),
            "$input must be preserved as the district",
        );
    }

    public function test_the_pincode_beats_whatever_the_geocoder_called_the_city(): void
    {
        // 110017 is Saket: South Delhi district, city Delhi. The seeder used to bind this pin to
        // the "South Delhi" CITY row, so the most authoritative signal we have disagreed with the
        // canonical model.
        DB::table('postal_codes')->insert([
            'state_id' => 32, 'district_id' => 297, 'city_id' => 297, 'pincode' => '110017',
        ]);

        $res = (new LocationNormalizer())->normalize(['city' => 'Saket', 'zip' => '110017']);

        $this->assertSame('Delhi', $res['city'], 'a pin pointing at a subdivision must still walk up to the city');
        $this->assertSame('South Delhi', $res['district']);
        $this->assertSame(290, $res['city_id']);
    }

    public function test_a_suffix_subdivision_resolves_the_same_way(): void
    {
        $res = (new LocationNormalizer())->normalize(['city' => 'Mumbai Suburban', 'state' => 'Maharashtra']);

        $this->assertSame('Mumbai', $res['city']);
        $this->assertSame('Mumbai Suburban', $res['district']);
    }

    public function test_a_genuine_district_is_not_swallowed_by_the_rule(): void
    {
        // "East Siang" looks exactly like "East Delhi" to the prefix test. It survives only
        // because its parent, Siang, is not a city we ship to.
        $res = (new LocationNormalizer())->normalize(['city' => 'East Siang', 'state' => 'Arunachal Pradesh']);

        $this->assertSame('East Siang', $res['city'], 'East Siang is a place in its own right');
        $this->assertNull($res['district']);
        $this->assertFalse(
            (bool) DB::table('cities')->where('id', 96)->value('is_subdivision'),
            'a district whose parent is not a shopping city must never be demoted',
        );
    }

    public function test_an_unknown_city_is_left_exactly_as_the_customer_typed_it(): void
    {
        $res = (new LocationNormalizer())->normalize(['city' => 'Somewhere Nobody Seeded', 'state' => 'Delhi']);

        $this->assertSame('Somewhere Nobody Seeded', $res['city']);
        $this->assertNull($res['city_id']);
    }

    public function test_the_address_write_path_stores_the_city_and_the_district(): void
    {
        // The customer picked Delhi and typed a Saket address; Google called the city South Delhi.
        $clean = Address::sanitizePayload([
            'title'   => 'Home',
            'address' => [
                'street_address' => '12 Press Enclave Road',
                'area'           => 'Saket',
                'city'           => 'South Delhi',
                'state'          => 'Delhi',
                'zip'            => '110017',
            ],
        ]);

        $this->assertSame('Delhi', $clean['address']['city'], 'the stored city is the canonical one');
        $this->assertSame('South Delhi', $clean['address']['district']);
        $this->assertSame('Saket', $clean['address']['area'], 'the locality is untouched');
    }

    public function test_the_double_space_names_were_the_only_ones_the_alias_table_could_not_reach(): void
    {
        // The regression this guards: trim() alone leaves the internal double space, producing a
        // key ("north east  delhi") that appears nowhere in the alias map.
        foreach (['North East  Delhi', 'North West  Delhi', 'South West  Delhi'] as $name) {
            $this->assertSame(
                'delhi',
                AvailabilityService::canonicalCityKey($name),
                "$name must alias to delhi despite the double space",
            );
        }
    }

    public function test_a_delhi_shopper_still_matches_a_vendor_listed_under_a_district(): void
    {
        // The practical consequence for the catalogue: canonical keys have to agree in BOTH
        // directions, or a vendor who typed "South Delhi" is invisible to a Delhi customer.
        $variants = AvailabilityService::canonicalCityVariants('Delhi');

        foreach (['south delhi', 'new delhi', 'north delhi', 'west delhi'] as $spelling) {
            $this->assertContains($spelling, $variants, "a vendor area spelled '$spelling' must match Delhi");
        }
    }
}
