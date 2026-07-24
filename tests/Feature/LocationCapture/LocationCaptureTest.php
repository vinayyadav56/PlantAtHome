<?php

namespace Tests\Feature\LocationCapture;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\LocationCaptureRequest;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Mail\LocationCaptureMail;
use Marvel\Services\LocationCaptureService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Location Capture Email System: token lifecycle, public capture flow,
 * reverse geocoding + target updates, and the flag-gated dispatch gate.
 * sqlite replicas + the real feature migrations; Google faked at HTTP level.
 */
final class LocationCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
            'queue.default'                     => 'sync',
            'location.google_maps_key'          => 'test-maps-key',
            'location.min_accuracy_meters'      => 500,
            'location.max_daily_requests'       => 3,
            'location.capture_link_expiry_hours' => 72,
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->json('settings')->nullable();
            $t->json('address')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->timestamps();
        });

        foreach ([
            '2026_07_24_120000_create_location_capture_requests_table.php',
            '2026_07_24_120010_add_verified_location_to_users_and_shops.php',
        ] as $migration) {
            (require base_path('packages/marvel/database/migrations/' . $migration))->up();
        }

        Mail::fake();
        $this->withoutMiddleware();
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'results' => [[
                    'formatted_address'  => '45, MG Road, Gurugram, Haryana 122003, India',
                    'place_id'           => 'test-place-id',
                    'address_components' => [
                        ['long_name' => 'Gurugram', 'types' => ['locality']],
                        ['long_name' => 'Haryana', 'types' => ['administrative_area_level_1']],
                        ['long_name' => 'India', 'types' => ['country']],
                        ['long_name' => '122003', 'types' => ['postal_code']],
                    ],
                ]],
            ], 200),
        ]);
    }

    private function customer(): User
    {
        return User::forceCreate(['name' => 'Vineet', 'email' => 'vineet@example.com']);
    }

    private function service(): LocationCaptureService
    {
        return app(LocationCaptureService::class);
    }

    /* ── creation ─────────────────────────────────────────────────────────── */

    public function test_create_stores_hashed_token_queues_email_and_supersedes(): void
    {
        $user  = $this->customer();
        $first = $this->service()->createForUser($user, null);

        $row = $first['request'];
        $this->assertSame('pending', $row->status);
        $this->assertStringContainsString('/location/', $first['capture_url']);
        $token = basename($first['capture_url']);
        $this->assertSame(hash('sha256', $token), $row->getAttributes()['token_hash']);
        $this->assertNotSame($token, $row->getAttributes()['token_hash']); // never plaintext
        Mail::assertQueued(LocationCaptureMail::class, fn ($m) => $m->hasTo('vineet@example.com'));

        // A new link supersedes the old pending one.
        $second = $this->service()->createForUser($user, null);
        $this->assertSame('superseded', $row->fresh()->status);
        $this->assertSame('pending', $second['request']->status);
    }

    public function test_daily_cap_blocks_spam(): void
    {
        $user = $this->customer();
        foreach (range(1, 3) as $i) {
            $this->service()->createForUser($user, null);
        }
        $this->expectException(HttpException::class);
        $this->service()->createForUser($user, null);
    }

    /* ── public page ──────────────────────────────────────────────────────── */

    public function test_public_page_states(): void
    {
        $user   = $this->customer();
        $result = $this->service()->createForUser($user, null);
        $token  = basename($result['capture_url']);

        $this->get('/location/' . $token)->assertOk()->assertSee('Share Your Location');
        $this->get('/location/' . str_repeat('x', 48))->assertOk()->assertSee('Invalid Request');

        $result['request']->update(['expires_at' => now()->subHour()]);
        $this->get('/location/' . $token)->assertOk()->assertSee('Link Expired');

        $result['request']->update(['expires_at' => now()->addHour(), 'status' => 'completed']);
        $this->get('/location/' . $token)->assertOk()->assertSee('Already Saved');

        // Page view marks opened.
        $this->assertNotNull($result['request']->fresh()->opened_at);
    }

    public function test_open_pixel_marks_opened(): void
    {
        $user   = $this->customer();
        $result = $this->service()->createForUser($user, null);
        $this->assertNull($result['request']->opened_at);

        $this->get('/location/open/' . $result['request']->uuid . '.gif')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
        $this->assertNotNull($result['request']->fresh()->opened_at);
    }

    /* ── submission ───────────────────────────────────────────────────────── */

    public function test_submit_geocodes_stores_and_is_one_time(): void
    {
        $this->fakeGoogle();
        $user   = $this->customer();
        $result = $this->service()->createForUser($user, null);
        $token  = basename($result['capture_url']);

        $payload = ['token' => $token, 'latitude' => 28.4595, 'longitude' => 77.0266, 'accuracy' => 22.5];
        $this->postJson('/location/submit', $payload)->assertOk()->assertJson(['success' => true]);

        $row = $result['request']->fresh();
        $this->assertSame('completed', $row->status);
        $this->assertSame('Gurugram', $row->city);
        $this->assertSame('India', $row->country);
        $this->assertSame('test-place-id', $row->google_place_id);
        $this->assertNotNull($row->completed_at);

        $user->refresh();
        $this->assertTrue((bool) $user->location_verified);
        $this->assertEqualsWithDelta(28.4595, (float) $user->verified_latitude, 0.0001);
        $this->assertSame('45, MG Road, Gurugram, Haryana 122003, India', $user->verified_address);
        $this->assertNotNull($user->location_verified_at);

        // One-time: replay is rejected and the stored location is untouched.
        $this->postJson('/location/submit', ['token' => $token, 'latitude' => 1, 'longitude' => 1])
            ->assertStatus(422)
            ->assertJson(['error' => 'used']);
        $this->assertEqualsWithDelta(28.4595, (float) $user->fresh()->verified_latitude, 0.0001);
    }

    public function test_vendor_submit_updates_shop_and_matching_columns(): void
    {
        $this->fakeGoogle();
        $owner = User::forceCreate(['name' => 'Owner', 'email' => 'owner@example.com']);
        $shop  = Shop::forceCreate(['name' => 'Green Nursery', 'slug' => 'green-nursery', 'owner_id' => $owner->id]);
        // Shop::owner relation resolves via owner_id.
        $result = $this->service()->createForVendor($shop->fresh(), null);
        $token  = basename($result['capture_url']);

        $this->postJson('/location/submit', ['token' => $token, 'latitude' => 12.9716, 'longitude' => 77.5946, 'accuracy' => 15])
            ->assertOk();

        $shop->refresh();
        $this->assertTrue((bool) $shop->location_verified);
        $this->assertEqualsWithDelta(12.9716, (float) $shop->lat, 0.0001);          // matching columns
        $this->assertEqualsWithDelta(12.9716, (float) data_get($shop->settings, 'location.lat'), 0.0001); // settings.location
        $this->assertSame('Gurugram', $shop->verified_city);
    }

    public function test_low_accuracy_is_rejected(): void
    {
        $this->fakeGoogle();
        $user   = $this->customer();
        $result = $this->service()->createForUser($user, null);
        $token  = basename($result['capture_url']);

        $this->postJson('/location/submit', ['token' => $token, 'latitude' => 28.4, 'longitude' => 77.0, 'accuracy' => 5000])
            ->assertStatus(422)
            ->assertJson(['error' => 'accuracy']);
        $this->assertSame('pending', $result['request']->fresh()->status); // still usable after user retries
    }

    public function test_expired_and_invalid_submissions(): void
    {
        $user   = $this->customer();
        $result = $this->service()->createForUser($user, null);
        $token  = basename($result['capture_url']);
        $result['request']->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/location/submit', ['token' => $token, 'latitude' => 28.4, 'longitude' => 77.0])
            ->assertStatus(422)->assertJson(['error' => 'expired']);
        $this->postJson('/location/submit', ['token' => str_repeat('y', 48), 'latitude' => 28.4, 'longitude' => 77.0])
            ->assertStatus(404)->assertJson(['error' => 'invalid']);
    }

    /* ── dispatch gate ────────────────────────────────────────────────────── */

    public function test_dispatch_gate_is_flag_gated(): void
    {
        $user  = $this->customer();
        $order = (object) ['customer_id' => $user->id];

        // Flag off (default): never blocks.
        config(['location.require_verified_for_dispatch' => false]);
        $this->service()->assertCustomerVerifiedForDispatch($order);

        // Flag on + unverified: blocks.
        config(['location.require_verified_for_dispatch' => true]);
        try {
            $this->service()->assertCustomerVerifiedForDispatch($order);
            $this->fail('Expected dispatch to be blocked.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Verified: passes. Guests: pass.
        $user->forceFill(['location_verified' => true])->save();
        $this->service()->assertCustomerVerifiedForDispatch($order);
        $this->service()->assertCustomerVerifiedForDispatch((object) ['customer_id' => null]);
    }

    /* ── admin endpoints ──────────────────────────────────────────────────── */

    public function test_admin_summary_store_regenerate_and_logs(): void
    {
        $this->fakeGoogle();
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com']);
        $this->actingAs($admin);
        $user = $this->customer();

        // Missing → send → pending.
        $this->getJson('/api/location-capture/summary?user_id=' . $user->id)
            ->assertOk()->assertJsonPath('status', 'missing');

        $create = $this->postJson('/api/location-capture/requests', ['user_id' => $user->id])->assertStatus(201);
        $this->assertNotEmpty($create->json('capture_url'));
        $uuid = $create->json('request.uuid');

        $this->getJson('/api/location-capture/summary?user_id=' . $user->id)
            ->assertOk()->assertJsonPath('status', 'pending');

        // Regenerate supersedes.
        $regen = $this->postJson("/api/location-capture/requests/{$uuid}/regenerate")->assertStatus(201);
        $this->assertNotSame($create->json('capture_url'), $regen->json('capture_url'));
        $this->assertSame('superseded', LocationCaptureRequest::where('uuid', $uuid)->first()->status);

        // Complete → verified; logs reflect everything with filters.
        $token = basename($regen->json('capture_url'));
        $this->postJson('/location/submit', ['token' => $token, 'latitude' => 28.4595, 'longitude' => 77.0266, 'accuracy' => 10])->assertOk();

        $this->getJson('/api/location-capture/summary?user_id=' . $user->id)
            ->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('location.city', 'Gurugram');

        $logs = $this->getJson('/api/location-capture/requests?status=completed')->assertOk();
        $this->assertSame(1, $logs->json('total'));
        $this->assertSame('completed', $logs->json('data.0.status'));
        $this->assertSame(2, $this->getJson('/api/location-capture/requests')->json('total'));

        // Exactly-one-target validation.
        $this->postJson('/api/location-capture/requests', [])->assertStatus(422);
    }
}
