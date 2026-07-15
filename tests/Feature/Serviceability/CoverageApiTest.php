<?php

namespace Tests\Feature\Serviceability;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Marvel\Http\Controllers\DeliveryCoverageController;
use Marvel\Http\Controllers\LocationController;

/**
 * Marvel-side Delivery Coverage HTTP layer. The admin/vendor routes sit behind
 * permission:SUPER_ADMIN / STORE_OWNER + auth:sanctum, which need the full
 * marvel users/spatie-permission schema — NOT present in this isolated sqlite
 * TestCase. So the controllers are exercised DIRECTLY (real Requests, real
 * responses; the exact objects the routes dispatch to), while the truly public
 * marvel routes (locations/districts, locations/postal-codes, delivery-notify)
 * and the V2 geo routes are hit over HTTP.
 */
class CoverageApiTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        $this->controller = new DeliveryCoverageController();
    }

    /* ── Admin endpoints (direct controller dispatch) ─────────────────── */

    public function test_admin_crud_happy_path_store_index_summary_pincodes_destroy(): void
    {
        // store: a city rule for shop 1
        $rule = $this->controller->store(Request::create('/coverage', 'POST', [
            'shop_id' => 1, 'rule_type' => 'city', 'city_id' => $this->geo['gurugram_c'],
        ]));
        $this->assertSame('city:' . $this->geo['gurugram_c'], $rule->target_key);

        // store: an include pin for shop 1
        $this->controller->store(Request::create('/coverage', 'POST', [
            'shop_id' => 1, 'rule_type' => 'pincode_include', 'pincode' => '302001',
        ]));

        // index: filterable paginated rules with names resolved
        $page = $this->controller->index(Request::create('/coverage', 'GET', ['shop_id' => 1]));
        $this->assertSame(2, $page->total());
        $byType = collect($page->items())->keyBy('rule_type');
        $this->assertSame('Gurugram', $byType['city']->city_name);
        $this->assertSame('Green Roots', $byType['city']->shop_name);

        // summary + pincodes (projection: 122001 via city + 302001 manual)
        $summary = $this->controller->summary(Request::create('/coverage/summary', 'GET', ['shop_id' => 1]));
        $this->assertSame(2, $summary['totals']['covered_pincodes']);
        $this->assertSame(['302001'], $summary['manual_added']);

        $pins = $this->controller->pincodes(Request::create('/coverage/pincodes', 'GET', ['shop_id' => 1]));
        $this->assertSame(['122001', '302001'], collect($pins->items())->pluck('pincode')->all());

        // destroy: rule id → shop resolved from the rule; projection shrinks
        $res = $this->controller->destroy(Request::create('/coverage/x', 'DELETE'), $rule->id);
        $this->assertSame(1, (int) $res['shop_id']);
        $this->assertSame(
            ['302001'],
            DB::table('vendor_covered_pincodes')->where('shop_id', 1)->pluck('pincode')->all()
        );

        // destroy: unknown id → 404 json
        $notFound = $this->controller->destroy(Request::create('/coverage/x', 'DELETE'), 99999);
        $this->assertSame(404, $notFound->getStatusCode());
    }

    public function test_admin_store_rejects_invalid_targets_with_domain_codes(): void
    {
        $bad = $this->controller->store(Request::create('/coverage', 'POST', [
            'shop_id' => 1, 'rule_type' => 'pincode_include', 'pincode' => '999999',
        ]));
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertSame('PINCODE_UNKNOWN', $bad->getData(true)['code']);

        $badType = $this->controller->store(Request::create('/coverage', 'POST', [
            'shop_id' => 1, 'rule_type' => 'galaxy',
        ]));
        $this->assertSame(422, $badType->getStatusCode());
        $this->assertSame('INVALID_RULE_TYPE', $badType->getData(true)['code']);
    }

    public function test_admin_preview_and_sync(): void
    {
        $preview = $this->controller->preview(Request::create('/coverage/preview', 'POST', [
            'rules' => [
                ['rule_type' => 'state', 'state_id' => $this->geo['haryana']],
                ['rule_type' => 'pincode_exclude', 'pincode' => '122002'],
            ],
        ]));
        $this->assertSame(3, $preview['total']); // 122001, 121001, 121002
        $this->assertSame(0, DB::table('vendor_coverage_rules')->count()); // dry-run

        $stats = $this->controller->sync(Request::create('/coverage/1/sync', 'POST'), 1);
        $this->assertSame(0, $stats['pincodes']); // no rules yet — empty projection
    }

    public function test_admin_import_export_and_audit_round_trip(): void
    {
        $csv = implode("\n", [
            'shop,rule_type,state,district,city,pincode,active',
            'green-roots,city,Haryana,,Gurugram,,1',       // by slug + city name
            '1,pincode_include,,,,302001,1',                // by id + pin
            '2,district,Haryana,Gurgaon,,,0',               // inactive rule
            'green-roots,city,,,Atlantis,,1',               // unknown city → row error
            'nope-shop,state,Haryana,,,,1',                 // unknown shop → row error
        ]);
        $path = tempnam(sys_get_temp_dir(), 'cov');
        file_put_contents($path, $csv);

        $result = $this->controller->import(Request::create('/coverage/import', 'POST', [], [], [
            'csv' => new UploadedFile($path, 'rules.csv', 'text/csv', null, true),
        ]));

        $this->assertSame(3, $result['imported']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame([5, 6], array_column($result['errors'], 'row'));

        // The inactive imported rule is stored inactive and does not project.
        $this->assertSame(0, (int) DB::table('vendor_coverage_rules')->where('shop_id', 2)->value('is_active'));
        $this->assertSame(0, DB::table('vendor_covered_pincodes')->where('shop_id', 2)->count());
        $this->assertSame(
            ['122001', '302001'],
            DB::table('vendor_covered_pincodes')->where('shop_id', 1)->orderBy('pincode')->pluck('pincode')->all()
        );

        // export streams a re-importable CSV
        $response = $this->controller->export(Request::create('/coverage/export', 'GET', ['shop_id' => 1]));
        ob_start();
        $response->sendContent();
        $lines = array_values(array_filter(explode("\n", trim(ob_get_clean()))));
        $this->assertSame('shop,rule_type,state,district,city,pincode,active', $lines[0]);
        $this->assertCount(3, $lines); // header + shop 1's two rules
        $this->assertStringContainsString('green-roots,city,,,Gurugram,,1', implode("\n", $lines));

        // audit trail records the writes
        $audit = $this->controller->audit(Request::create('/coverage/audit', 'GET', ['shop_id' => 1]));
        $actions = collect($audit->items())->pluck('action');
        $this->assertTrue($actions->contains('rule_added'));

        @unlink($path);
    }

    /* ── Vendor self-serve ownership + rule sync ──────────────────────── */

    private function actingUser(array $ownedShopIds, bool $superAdmin = false): object
    {
        return new class($ownedShopIds, $superAdmin)
        {
            public $id = 7;

            public $shops;

            public function __construct(array $ids, private bool $admin)
            {
                $this->shops = collect(array_map(fn ($id) => (object) ['id' => $id], $ids));
            }

            public function hasPermissionTo($permission): bool
            {
                return $this->admin;
            }
        };
    }

    public function test_vendor_routes_enforce_ownership_and_sync_rules(): void
    {
        // Owner of shop 1 replaces their rule set.
        $request = Request::create('/my-coverage/1/rules', 'PUT', [
            'rules' => [
                ['rule_type' => 'district', 'district_id' => $this->geo['gurgaon']],
                ['rule_type' => 'pincode_exclude', 'pincode' => '122002'],
            ],
        ]);
        $request->setUserResolver(fn () => $this->actingUser([1]));
        $result = $this->controller->mySyncRules($request, 1);
        $this->assertSame(1, $result['shop_id']);
        $this->assertSame(1, $result['stats']['pincodes']); // 122001 only (122002 excluded)

        $summaryReq = Request::create('/my-coverage/1/summary', 'GET');
        $summaryReq->setUserResolver(fn () => $this->actingUser([1]));
        $summary = $this->controller->mySummary($summaryReq, 1);
        $this->assertSame(1, $summary['totals']['covered_pincodes']);

        // A vendor who does not own shop 1 is rejected…
        $foreign = Request::create('/my-coverage/1/summary', 'GET');
        $foreign->setUserResolver(fn () => $this->actingUser([2]));
        try {
            $this->controller->mySummary($foreign, 1);
            $this->fail('Expected 403 for a non-owner');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // …but a super admin may act on any shop (service-areas ownership rule).
        $admin = Request::create('/my-coverage/1/summary', 'GET');
        $admin->setUserResolver(fn () => $this->actingUser([], superAdmin: true));
        $this->assertSame(1, $this->controller->mySummary($admin, 1)['totals']['covered_pincodes']);
    }

    /* ── Public HTTP: marvel geo lookups + notify-me + V2 geo routes ──── */

    public function test_public_locations_districts_and_postal_codes_routes(): void
    {
        $districts = $this->getJson('/api/locations/districts?state_id=' . $this->geo['haryana']);
        $districts->assertStatus(200);
        $this->assertSame(['Faridabad', 'Gurgaon'], collect($districts->json())->pluck('name')->all());

        $pins = $this->getJson('/api/locations/postal-codes?district_id=' . $this->geo['gurgaon']);
        $pins->assertStatus(200);
        // 122099 is status=inactive — only active pins are returned.
        $this->assertSame(['122001', '122002'], collect($pins->json('data'))->pluck('pincode')->all());

        $search = $this->getJson('/api/locations/postal-codes?search=3020');
        $this->assertSame(['302001', '302002'], collect($search->json('data'))->pluck('pincode')->all());

        // cities can now be narrowed by district
        $cities = $this->getJson('/api/locations/cities?district_id=' . $this->geo['gurgaon']);
        $cities->assertStatus(200);
        $this->assertSame(['Gurugram'], collect($cities->json())->pluck('name')->all());
    }

    public function test_public_delivery_notify_captures_and_dedupes(): void
    {
        $ok = $this->postJson('/api/delivery-notify', ['pincode' => '999001', 'email' => 'lead@example.com']);
        $ok->assertStatus(200)->assertJson(['success' => true]);

        // Same pincode + email → still one row (refreshed, not duplicated).
        $this->postJson('/api/delivery-notify', ['pincode' => '999001', 'email' => 'lead@example.com', 'phone' => '9876543210'])
            ->assertStatus(200);
        $this->assertSame(1, DB::table('delivery_notify_requests')->count());
        $row = DB::table('delivery_notify_requests')->first();
        $this->assertSame('9876543210', $row->phone); // second contact filled in
        $this->assertSame('pending', $row->status);

        // A different contact for the same pin is a separate lead.
        $this->postJson('/api/delivery-notify', ['pincode' => '999001', 'phone' => '9000000001'])->assertStatus(200);
        $this->assertSame(2, DB::table('delivery_notify_requests')->count());

        // Validation: bad pincode / missing contact / bad email.
        $this->postJson('/api/delivery-notify', ['pincode' => '12', 'email' => 'a@b.com'])->assertStatus(422);
        $this->postJson('/api/delivery-notify', ['pincode' => '999001'])->assertStatus(422);
        $this->postJson('/api/delivery-notify', ['pincode' => '999001', 'email' => 'not-an-email'])->assertStatus(422);
    }

    public function test_v2_geo_routes_still_serve_the_same_master(): void
    {
        $districts = $this->getJson('/api/v1/serviceability/geo/districts?state_id=' . $this->geo['haryana']);
        $districts->assertStatus(200);
        $this->assertSame(['Faridabad', 'Gurgaon'], collect($districts->json('data'))->pluck('name')->all());

        $pins = $this->getJson('/api/v1/serviceability/geo/postal-codes?district_id=' . $this->geo['gurgaon']);
        $pins->assertStatus(200);
        $this->assertSame(['122001', '122002'], collect($pins->json('data'))->pluck('pincode')->all());
    }

    /* ── Admin geo: districts CRUD + postal-code remap (direct dispatch) ── */

    public function test_admin_district_crud_and_postal_code_remap(): void
    {
        $location = new LocationController();

        $created = $location->districtStore(Request::create('/districts', 'POST', [
            'state_id' => $this->geo['haryana'], 'name' => 'Rohtak',
        ]));
        $this->assertSame('Rohtak', $created->name);
        $this->assertSame(1, (int) $created->is_active);

        // Duplicate (case-insensitive, same state) → 422.
        $dup = $location->districtStore(Request::create('/districts', 'POST', [
            'state_id' => $this->geo['haryana'], 'name' => 'rohtak',
        ]));
        $this->assertSame(422, $dup->getStatusCode());

        $updated = $location->districtUpdate(
            Request::create('/districts/' . $created->id, 'PUT', ['is_active' => false]),
            $created->id
        );
        $this->assertSame(0, (int) $updated->is_active);

        $index = $location->districtIndex(Request::create('/districts', 'GET', ['search' => 'Roh']));
        $this->assertSame(1, $index->total());
        $this->assertSame('Haryana', $index->items()[0]->state_name);

        // Remap pin 122002 (no city) onto Gurugram + flip status.
        $pinId = (int) DB::table('postal_codes')->where('pincode', '122002')->value('id');
        $remapped = $location->postalCodeUpdate(
            Request::create('/postal-codes/' . $pinId, 'PUT', ['city_id' => $this->geo['gurugram_c'], 'status' => 'inactive']),
            $pinId
        );
        $this->assertSame($this->geo['gurugram_c'], (int) $remapped->city_id);
        $this->assertSame('inactive', $remapped->status);

        $missing = $location->postalCodeUpdate(Request::create('/postal-codes/0', 'PUT', []), 0);
        $this->assertSame(404, $missing->getStatusCode());
    }
}
