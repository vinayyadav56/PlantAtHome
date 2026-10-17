<?php

namespace Tests\Feature\Garden;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\GardenLead;
use Marvel\Database\Models\Settings;
use Marvel\Enums\EventType;
use Marvel\Jobs\NotifyOwnerOfCorporateLead;
use Marvel\Services\ServiceAvailabilityService;
use Tests\TestCase;

/**
 * POST /api/corporate-leads — the "Request a Custom Proposal" form. What the page
 * leans on: company/email/city are required there (not on garden-leads), `meta`
 * keeps only the two known keys, the client can't pick its own `source`, a
 * pan-India enquiry is never city-gated or 503'd by an orders pause, the form has
 * its own rate-limit bucket, and the owner hears about it when the toggle is on.
 */
final class CorporateLeadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('garden_leads', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('phone');
            $t->string('email')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('garden_type')->nullable();
            $t->string('space_size')->nullable();
            $t->string('budget_range')->nullable();
            $t->text('message')->nullable();
            $t->string('status')->default('new');
            $t->string('source')->default('garden');
            $t->string('company')->nullable();
            $t->string('occasion')->nullable();
            $t->string('quantity')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
        // The owner-alert toggle lives in the settings matrix; empty = off.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'company' => 'Acme Pvt Ltd',
            'name' => 'Asha Rao',
            'email' => 'asha@acme.example',
            'phone' => '+919876543210',
            'occasion' => 'Employee gifting',
            'quantity' => '100-249',
            'budget_range' => '500-999',
            'city' => 'Gurugram',
            'message' => 'Logo on the pots please.',
            'meta' => [
                'preferred_date' => now()->addDays(7)->toDateString(),
                'plant_preferences' => ['indoor', 'other'],
            ],
        ], $over);
    }

    private function alertsOn(): void
    {
        Settings::create(['language' => DEFAULT_LANGUAGE, 'options' => [
            'smsEvent' => ['admin' => [EventType::CORPORATE_LEAD => true]],
            'ownerNotify' => ['number' => '919996469046'],
        ]]);
    }

    public function test_a_full_enquiry_is_stored_as_corporate_with_only_the_known_meta_keys(): void
    {
        $res = $this->postJson('/api/corporate-leads', $this->payload([
            'source' => 'garden',                      // the route decides, not the client
            'meta' => ['preferred_date' => now()->addDays(7)->toDateString(), 'plant_preferences' => ['indoor', 'other'], 'extra' => 'dropped'],
        ]));

        $res->assertStatus(201)->assertJsonPath('message', 'Thanks! Our gifting team will reach out with a tailored proposal.');
        $lead = GardenLead::findOrFail($res->json('data.id'));
        $this->assertSame('corporate', $lead->source);
        $this->assertSame('new', $lead->status);
        $this->assertSame('Acme Pvt Ltd', $lead->company);
        $this->assertSame(
            ['preferred_date' => now()->addDays(7)->toDateString(), 'plant_preferences' => ['indoor', 'other']],
            $lead->meta
        );
    }

    public function test_company_email_and_city_are_required_on_the_corporate_route_only(): void
    {
        $bare = ['name' => 'Asha Rao', 'phone' => '+919876543210'];

        $this->postJson('/api/corporate-leads', $bare)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company', 'email', 'city']);

        $this->postJson('/api/garden-leads', $bare)->assertStatus(201);
        $this->assertSame('garden', GardenLead::first()->source);
        $this->assertNull(GardenLead::first()->meta);
    }

    public function test_an_unknown_plant_preference_and_a_past_date_are_rejected_on_both_routes(): void
    {
        $bad = $this->payload(['meta' => [
            'preferred_date' => now()->subDay()->toDateString(),
            'plant_preferences' => ['bonsai'],
        ]]);

        foreach (['/api/corporate-leads', '/api/garden-leads'] as $uri) {
            $this->postJson($uri, $bad)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['meta.preferred_date', 'meta.plant_preferences']);
        }
        $this->assertSame(0, GardenLead::count());
    }

    public function test_an_orders_pause_and_a_paused_city_block_garden_leads_but_not_corporate_ones(): void
    {
        $stub = \Mockery::mock(ServiceAvailabilityService::class);
        $stub->shouldReceive('platformFlags')->andReturn(['stop_platform' => false, 'stop_orders' => true, 'stop_deliveries' => false, 'message' => null]);
        $stub->shouldReceive('resolve')->andReturn(['available' => false, 'status' => 'paused', 'reason' => 'city_paused', 'message' => null]);
        $this->app->instance(ServiceAvailabilityService::class, $stub);

        $this->postJson('/api/corporate-leads', $this->payload(['city' => 'Paused Town']))->assertStatus(201);
        $this->postJson('/api/garden-leads', $this->payload(['city' => 'Paused Town']))
            ->assertStatus(503)
            ->assertJsonPath('serviceable', false);
    }

    public function test_the_form_has_its_own_rate_limit_bucket(): void
    {
        $route = collect(\Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/corporate-leads');
        $this->assertContains('throttle:lead-submit', $route->gatherMiddleware());
        $garden = collect(\Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/garden-leads');
        $this->assertContains('throttle:lead-submit', $garden->gatherMiddleware(), 'fixing one route keeps the shared bucket');

        // Per address: ten different enquiries from one address, the eleventh is refused.
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/corporate-leads', $this->payload(['phone' => '+9198765432' . sprintf('%02d', $i)]))->assertStatus(201);
        }
        $this->postJson('/api/corporate-leads', $this->payload(['phone' => '+919876543299']))->assertStatus(429);
    }

    public function test_one_phone_number_is_limited_even_when_the_address_header_rotates(): void
    {
        // The address key comes from a client-controlled header outside Cloudflare, so the
        // per-phone bucket is what stops one caller filing unlimited leads by rotating it.
        for ($i = 1; $i <= 3; $i++) {
            $this->withHeader('CF-Connecting-IP', "203.0.113.{$i}")
                ->postJson('/api/corporate-leads', $this->payload())->assertStatus(201);
        }
        $this->withHeader('CF-Connecting-IP', '203.0.113.4')
            ->postJson('/api/corporate-leads', $this->payload())->assertStatus(429);
        // A different number from yet another address is still welcome.
        $this->withHeader('CF-Connecting-IP', '203.0.113.5')
            ->postJson('/api/corporate-leads', $this->payload(['phone' => '+919000000001']))->assertStatus(201);
    }

    public function test_the_owner_alert_is_dispatched_only_when_its_toggle_is_on(): void
    {
        Bus::fake();

        $this->postJson('/api/corporate-leads', $this->payload())->assertStatus(201);
        Bus::assertNotDispatched(NotifyOwnerOfCorporateLead::class);

        $this->alertsOn();
        $id = $this->postJson('/api/corporate-leads', $this->payload())->assertStatus(201)->json('data.id');
        Bus::assertDispatched(NotifyOwnerOfCorporateLead::class, fn ($job) => $job->leadId === $id);
    }

    public function test_the_owner_alert_reaches_the_configured_number_once(): void
    {
        config([
            'cache.default' => 'array',
            'auth.notify_gateway' => 'whatsapp',
            'services.whatsapp' => [
                'phone_number_id' => '1234567890', 'access_token' => 'test-token', 'api_version' => 'v21.0',
                'otp_template' => 'plantathome_otp', 'otp_lang' => 'en', 'otp_has_button' => true,
                'notify_template' => 'plantathome_update', 'notify_lang' => 'en',
                'otp_ttl_minutes' => 5, 'otp_max_attempts' => 5,
            ],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $this->alertsOn();
        $lead = GardenLead::create($this->payload() + ['status' => 'new', 'source' => 'corporate']);

        (new NotifyOwnerOfCorporateLead($lead->id))->handle();
        (new NotifyOwnerOfCorporateLead($lead->id))->handle(); // a retry must not re-send

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $text = $body['template']['components'][0]['parameters'][0]['text'] ?? '';

            return $body['to'] === '919996469046'
                && $text === 'New corporate gifting enquiry on PlantAtHome: Acme Pvt Ltd, Asha Rao (+919876543210), 100-249 for Employee gifting.';
        });
    }
}
