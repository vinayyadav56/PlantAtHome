<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConnectionTester;
use Tests\TestCase;

/**
 * The Plant Doctor "Test Connection" button must never buy a diagnosis. The probe reads /health
 * (is the service up, which models) and sends an EMPTY diagnosis request with the key: the
 * service answers 401 to a wrong key and a validation 4xx to a right one, before any model call.
 */
final class PlantDoctorProbeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://pd-probe.test';

    protected function setUp(): void
    {
        parent::setUp();
        // A probe that escapes the fakes would hit a real service: never.
        Http::preventStrayRequests();
        // The migrations backfill an integration_providers row for plant_doctor whose configuration
        // carries the seeded PRODUCTION service URL, and that row outranks everything else in
        // IntegrationService::config() — the first version of this test probed production with a
        // canary key. Drop it so resolution falls to the legacy settings row, which is where the
        // tester reads whether the key is SET at all.
        DB::table('integration_providers')->where('provider_slug', 'plant_doctor')->delete();
        DB::table('plant_doctor_settings')->update([
            'service_url' => self::URL,
            'service_api_key' => 'PROBE-KEY-CANARY',
        ]);
        config([
            'services.plant_doctor.service_url' => self::URL,
            'services.plant_doctor.service_api_key' => 'PROBE-KEY-CANARY',
        ]);
    }

    private function probe(): array
    {
        return (new ConnectionTester())->test('plant_doctor');
    }

    public function test_a_live_service_that_accepts_the_key_is_connected_and_reports_its_models(): void
    {
        Http::fake([
            self::URL . '/health' => Http::response(['status' => 'ok', 'gate_model' => 'gpt-5.4-mini', 'diagnosis_model' => 'gpt-5.4', 'plant_id' => false], 200),
            self::URL . '/plant-diagnosis' => Http::response(['code' => 'image_required', 'message' => 'Please add a photo'], 422),
        ]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_CONNECTED, $res['status'], json_encode($res));
        $this->assertTrue($res['ok']);
        $this->assertSame('gpt-5.4', $res['detail']['diagnosis_model']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/plant-diagnosis') && $r->hasHeader('X-Api-Key', 'PROBE-KEY-CANARY'));
    }

    public function test_a_rejected_key_is_auth_failed(): void
    {
        Http::fake([
            self::URL . '/health' => Http::response(['status' => 'ok'], 200),
            self::URL . '/plant-diagnosis' => Http::response(['detail' => 'Invalid or missing X-Api-Key header'], 401),
        ]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_AUTH_FAILED, $res['status']);
        $this->assertFalse($res['ok']);
    }

    public function test_a_service_that_is_down_is_unknown_not_connected(): void
    {
        Http::fake([self::URL . '/*' => Http::response('', 502)]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_UNKNOWN, $res['status'], json_encode($res));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('502', $res['message']);
    }
}
