<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use Illuminate\Support\Facades\DB;

/**
 * The geographic hierarchy endpoints: lazy tree with counts, one search box
 * over every level, and the per-node detail drawer.
 *
 * The master is not a clean tree — only a third of cities sit under a district
 * and rural pincodes have no city — so most of what is asserted here is that
 * the endpoints say so instead of quietly dropping those rows.
 */
class LocationTreeTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
    }

    public function test_the_root_lists_states_with_pincode_counts_and_one_stats_block(): void
    {
        $res = $this->getJson('/api/locations/tree')->assertStatus(200);

        $haryana = collect($res->json('children'))->firstWhere('name', 'Haryana');
        $this->assertSame(2, $haryana['children_count'], 'Gurgaon + Faridabad');
        $this->assertSame(4, $haryana['pincodes_total'], 'the inactive 122099 is not counted');
        $this->assertSame(0, $haryana['pincodes_serviceable']);

        $this->assertSame(6, $res->json('stats.pincodes'));
        $this->assertSame(0, $res->json('stats.vendors_with_rules'));
    }

    public function test_serviceable_counts_come_from_the_coverage_projection(): void
    {
        $this->app->make(DeliveryCoverageService::class)
            ->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $root = $this->getJson('/api/locations/tree')->assertStatus(200);
        $haryana = collect($root->json('children'))->firstWhere('name', 'Haryana');
        $this->assertSame(2, $haryana['pincodes_serviceable'], '122001 + 122002');
        $this->assertSame(1, $root->json('stats.vendors_with_rules'));

        $districts = $this->getJson('/api/locations/tree?parent_type=state&parent_id=' . $this->geo['haryana'])
            ->assertStatus(200);
        $gurgaon = collect($districts->json('children'))->firstWhere('name', 'Gurgaon');
        $this->assertSame([2, 2], [$gurgaon['pincodes_total'], $gurgaon['pincodes_serviceable']]);
        $faridabad = collect($districts->json('children'))->firstWhere('name', 'Faridabad');
        $this->assertSame([2, 0], [$faridabad['pincodes_total'], $faridabad['pincodes_serviceable']]);
    }

    public function test_pincodes_with_no_city_get_their_own_bucket_under_the_district(): void
    {
        $res = $this->getJson('/api/locations/tree?parent_type=district&parent_id=' . $this->geo['gurgaon'])
            ->assertStatus(200);

        $children = collect($res->json('children'));
        $this->assertSame(1, $children->firstWhere('type', 'city')['pincodes_total'], '122001 only');

        // 122002 has no master city: a city-level rule could never reach it, so
        // it must still be visible and labelled.
        $orphans = $children->firstWhere('type', 'city_unassigned');
        $this->assertNotNull($orphans, 'the city-less pincodes must not vanish from the tree');
        $this->assertSame(1, $orphans['pincodes_total']);
        $this->assertNull($orphans['id']);
    }

    public function test_cities_never_rolled_up_to_a_district_are_still_reachable(): void
    {
        DB::table('cities')->insert([
            'name' => 'Hodal', 'state_id' => $this->geo['haryana'], 'district_id' => null,
            'status' => 'active', 'is_serviceable' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/locations/tree?parent_type=state&parent_id=' . $this->geo['haryana'])
            ->assertStatus(200);

        $bucket = collect($res->json('children'))->firstWhere('type', 'district_unassigned');
        $this->assertNotNull($bucket, 'two thirds of real cities land here');
        $this->assertSame(1, $bucket['children_count']);
    }

    public function test_search_spans_every_level_and_returns_the_full_path(): void
    {
        $pin = $this->getJson('/api/locations/search?q=122001')->assertStatus(200);
        $hit = collect($pin->json('results'))->firstWhere('type', 'pincode');
        $this->assertSame(['India', 'Haryana', 'Gurgaon', 'Gurugram', '122001'], $hit['path']);

        $district = collect($this->getJson('/api/locations/search?q=Gurgaon')->json('results'))
            ->firstWhere('type', 'district');
        $this->assertSame(['India', 'Haryana', 'Gurgaon'], $district['path']);

        $this->getJson('/api/locations/search?q=x')->assertStatus(200)->assertJson(['results' => []]);
    }

    public function test_a_city_with_no_district_omits_that_hop_instead_of_inventing_one(): void
    {
        DB::table('cities')->insert([
            'name' => 'Hodal', 'state_id' => $this->geo['haryana'], 'district_id' => null,
            'status' => 'active', 'is_serviceable' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $hit = collect($this->getJson('/api/locations/search?q=Hodal')->json('results'))
            ->firstWhere('type', 'city');

        $this->assertSame(['India', 'Haryana', 'Hodal'], $hit['path']);
        $this->assertNull($hit['district_id']);
    }

    public function test_the_pincode_drawer_reports_geo_localities_and_covering_vendors(): void
    {
        DB::table('postal_codes')->where('pincode', '122001')->update([
            'office_name' => 'Gurgaon H.O',
            'offices'     => json_encode([['name' => 'Gurgaon H.O', 'taluk' => 'Gurgaon'], ['name' => 'DLF QE']]),
        ]);
        $this->app->make(DeliveryCoverageService::class)
            ->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $res = $this->getJson('/api/locations/node?type=pincode&id=122001')->assertStatus(200);

        $this->assertSame('Gurugram', $res->json('node.city'));
        $this->assertSame('Gurgaon', $res->json('node.district'));
        $this->assertSame(['Gurgaon H.O', 'DLF QE'], array_column($res->json('localities'), 'name'));
        $this->assertCount(1, $res->json('vendors'));

        $this->getJson('/api/locations/node?type=pincode&id=999999')->assertStatus(404);
    }

    public function test_a_region_drawer_counts_its_scope_from_the_projection(): void
    {
        $this->app->make(DeliveryCoverageService::class)
            ->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $this->getJson('/api/locations/node?type=district&id=' . $this->geo['gurgaon'])
            ->assertStatus(200)
            ->assertJson(['pincodes_total' => 2, 'pincodes_serviceable' => 2, 'vendors' => 1]);
    }
}
