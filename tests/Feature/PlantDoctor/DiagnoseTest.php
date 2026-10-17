<?php

namespace Tests\Feature\PlantDoctor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\PlantDoctorLog;
use Marvel\Database\Models\User;
use Marvel\Http\Controllers\PlantDoctorController;
use Tests\TestCase;

/**
 * POST /api/plant-doctor/diagnose — the proxy in front of the staged Plant Doctor service.
 *
 * What it must never do: forward a photo it can tell is not an image, send words alone to be
 * "diagnosed", or show a client a diagnosis for a photo the service's gate did not pass. What it
 * must always do: price the per-stage usage from config, log the outcome, and pass the service's
 * own customer copy through for the 4xx codes it owns.
 */
final class DiagnoseTest extends TestCase
{
    private const SERVICE = 'https://pd.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'services.plant_doctor.service_url' => null,
            'services.plant_doctor.service_api_key' => 'test-shared-key',
            'services.plant_doctor.models' => ['diag-m', 'alt-m'],
            'services.plant_doctor.prices' => ['gate-m' => [1.0, 2.0], 'diag-m' => [10.0, 20.0]],
            'services.plant_doctor.fallback_price' => [100.0, 200.0],
            'services.plant_doctor.usd_to_inr' => 80.0,
            'services.plant_doctor.min_edge' => 160,
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

        foreach ([
            '2026_06_04_160000_create_plant_doctor_settings_table.php',
            '2026_06_04_160100_create_plant_doctor_logs_table.php',
            '2026_06_14_000100_add_trust_fields_to_plant_doctor_logs_table.php',
            '2026_10_18_000100_add_outcome_to_plant_doctor_logs_table.php',
        ] as $file) {
            (require base_path('packages/marvel/database/migrations/' . $file))->up();
        }
        DB::table('plant_doctor_settings')->update([
            'service_url' => self::SERVICE,
            'openai_model' => 'diag-m',
            'monthly_budget_inr' => 500,
            'plant_id_enabled' => false,
        ]);
    }

    /** A real 200×200 JPEG, made with GD so nothing lives on disk. */
    private function jpeg(int $w = 200, int $h = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 90, 140, 70));
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($im);
        return base64_encode($bytes);
    }

    private function serviceBody(string $outcome, array $over = []): array
    {
        $base = [
            'request_id' => 'r-1',
            'language' => 'en',
            'outcome' => $outcome,
            'rejection_reason' => $outcome === 'plant_detected' ? '' : 'Rejected by the gate.',
            'guidance' => ['ask_for' => ['closer_photo'], 'message' => 'Move closer.'],
            'stage_results' => [
                'gate' => ['rule' => $outcome === 'plant_detected' ? 'accept' : 'no_plant'],
                'identification' => $outcome === 'plant_detected' ? ['confidence' => 'medium'] : null,
            ],
            'usage' => [
                'model' => 'diag-m', 'input_tokens' => 1300, 'output_tokens' => 350,
                'stages' => [
                    ['stage' => 'gate', 'model' => 'gate-m', 'input_tokens' => 1000, 'output_tokens' => 50, 'latency_ms' => 400],
                    ['stage' => 'identification', 'model' => 'diag-m', 'input_tokens' => 300, 'output_tokens' => 300, 'latency_ms' => 900],
                ],
            ],
            'is_plant' => $outcome === 'plant_detected',
            'image_quality' => 'ok',
            'identification' => ['common_name' => 'Monstera', 'scientific_name' => 'Monstera deliciosa', 'confidence' => 0.6],
            'plant_name' => 'Monstera',
            'diagnosis' => [[
                'condition' => 'Brown leaf tips', 'severity' => 'low', 'confidence' => 0.6,
                'description' => 'Crisp brown tips.', 'causes' => ['Low humidity'], 'solutions' => ['Raise humidity'],
                'preventive_measures' => ['Use rested water'], 'products_recommended' => ['pebble tray'],
                'vet_consultation_needed' => false,
            ]],
            'overall_health_score' => 0.7,
            'immediate_action' => 'Trim the tips.',
            'long_term_care' => 'Bright indirect light.',
        ];
        return array_replace_recursive($base, $over);
    }

    private function diagnose(array $payload, array $headers = [])
    {
        return $this->postJson('/api/plant-doctor/diagnose', $payload, $headers);
    }

    public function test_disabled_feature_is_503(): void
    {
        DB::table('plant_doctor_settings')->update(['enabled' => false]);
        Http::fake();
        $this->diagnose(['image_base64' => $this->jpeg()])->assertStatus(503)->assertJsonPath('code', 'disabled');
        Http::assertNothingSent();
    }

    public function test_exhausted_budget_is_429_with_its_own_code(): void
    {
        DB::table('plant_doctor_settings')->update(['monthly_budget_inr' => 1]);
        PlantDoctorLog::create(['cost_usd' => 0.5, 'cost_inr' => 40, 'created_at' => now()]);
        Http::fake();
        $this->diagnose(['image_base64' => $this->jpeg()])
            ->assertStatus(429)
            ->assertJsonPath('code', 'budget_exhausted')
            ->assertJsonPath('message', "Plant Doctor has reached this month's limit. Please try again next month.");
        Http::assertNothingSent();
    }

    public function test_words_alone_are_not_diagnosed(): void
    {
        Http::fake();
        $this->diagnose([])->assertStatus(422)->assertJsonPath('code', 'image_required');
        $this->diagnose(['symptoms' => 'leaves are yellow', 'plant_name' => 'Tulsi'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'image_required')
            ->assertJsonPath('message', 'Please add a photo — Dr. Planty diagnoses from what it can see.');
        Http::assertNothingSent();
        $this->assertSame(0, PlantDoctorLog::count());
    }

    public function test_photos_that_are_not_usable_images_never_reach_the_service(): void
    {
        Http::fake();
        $this->diagnose(['image_base64' => 'definitely not base64 !!'])->assertStatus(422)->assertJsonPath('code', 'invalid_image');
        $this->diagnose(['image_base64' => base64_encode(str_repeat('x', 500))])->assertStatus(422)->assertJsonPath('code', 'invalid_image');
        $this->diagnose(['image_base64' => $this->jpeg(10, 10)])->assertStatus(422)->assertJsonPath('code', 'invalid_image');
        config(['services.plant_doctor.max_image_base64_chars' => 100]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertStatus(422);
        config(['services.plant_doctor.max_image_base64_chars' => 8 * 1024 * 1024, 'services.plant_doctor.max_image_bytes' => 100]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertStatus(422)->assertJsonPath('code', 'invalid_image');
        Http::assertNothingSent();
    }

    public function test_a_disallowed_image_url_is_refused_at_this_boundary(): void
    {
        Http::fake();
        $this->diagnose(['image_url' => 'https://evil.example/leaf.jpg'])->assertStatus(422)->assertJsonPath('code', 'invalid_image');
        $this->diagnose(['image_url' => 'http://169.254.169.254/latest/meta-data'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_every_rejection_outcome_shows_no_diagnosis_and_is_logged(): void
    {
        $outcomes = ['non_plant', 'uncertain', 'poor_quality', 'mixed_content'];
        // One fake with a sequence: Http::fake() merges stubs, and the first one registered keeps answering.
        $sequence = Http::sequence();
        foreach ($outcomes as $outcome) {
            // The service blanks the legacy fields itself; a reply that forgot to is blanked here anyway.
            $sequence->push($this->serviceBody($outcome), 200);
        }
        Http::fake([self::SERVICE . '/*' => $sequence]);
        foreach ($outcomes as $outcome) {
            $res = $this->diagnose(['image_base64' => $this->jpeg(), 'plant_name' => 'Monstera'])->assertOk();
            $res->assertJsonPath('data.outcome', $outcome)
                ->assertJsonPath('data.is_plant', false)
                ->assertJsonPath('data.diagnosis', [])
                ->assertJsonPath('data.plant_name', '')
                ->assertJsonPath('data.immediate_action', '')
                ->assertJsonPath('data.long_term_care', '')
                ->assertJsonPath('data.overall_health_score', 0)
                ->assertJsonPath('data.identification.scientific_name', '')
                ->assertJsonPath('data.rejection_reason', 'Rejected by the gate.')
                ->assertJsonPath('data.guidance.ask_for.0', 'closer_photo');
            $log = PlantDoctorLog::latest('id')->first();
            $this->assertSame($outcome, $log->outcome, $outcome);
            $this->assertFalse($log->is_plant);
            $this->assertNull($log->plant_name);
            $this->assertNull($log->identified_species);
            $this->assertSame('no_plant', $log->gate_rule);
            $this->assertStringStartsWith('Rejected: ', $log->condition_summary);
        }
    }

    public function test_a_diagnosis_keeps_the_legacy_fields_and_prices_each_stage(): void
    {
        Http::fake([self::SERVICE . '/*' => Http::response($this->serviceBody('plant_detected'), 200)]);
        $res = $this->diagnose(['image_base64' => $this->jpeg(), 'symptoms' => 'tips brown', 'language' => 'hi', 'session_id' => 's1'])->assertOk();
        $res->assertJsonPath('data.outcome', 'plant_detected')
            ->assertJsonPath('data.is_plant', true)
            ->assertJsonPath('data.identification.scientific_name', 'Monstera deliciosa')
            ->assertJsonPath('data.diagnosis.0.condition', 'Brown leaf tips')
            ->assertJsonPath('data.overall_health_score', 0.7)
            ->assertJsonPath('data.immediate_action', 'Trim the tips.');

        $log = PlantDoctorLog::latest('id')->first();
        $this->assertSame('plant_detected', $log->outcome);
        $this->assertSame('accept', $log->gate_rule);
        $this->assertSame('medium', $log->confidence_level);
        $this->assertSame('Monstera deliciosa', $log->identified_species);
        $this->assertSame('Monstera', $log->plant_name);
        $this->assertSame('Brown leaf tips', $log->condition_summary);
        $this->assertSame('low', $log->top_severity);
        $this->assertSame(1300, $log->prompt_tokens);
        $this->assertSame(350, $log->completion_tokens);
        // gate-m: 1000×1 + 50×2 = 1100; diag-m: 300×10 + 300×20 = 9000 → 10100 per 1M tokens.
        $this->assertEqualsWithDelta(0.0101, $log->cost_usd, 1e-6);
        $this->assertEqualsWithDelta(0.0101 * 80, $log->cost_inr, 1e-4);
        $this->assertSame('s1', $log->session_id);
        $this->assertNotNull($log->duration_ms);
        $this->assertNull($log->user_id);
    }

    public function test_the_request_to_the_service_carries_the_admin_model_the_flag_and_a_request_id(): void
    {
        DB::table('plant_doctor_settings')->update(['openai_model' => 'alt-m', 'plant_id_enabled' => true]);
        Http::fake([self::SERVICE . '/*' => Http::response($this->serviceBody('plant_detected'), 200)]);
        $this->diagnose(['image_base64' => $this->jpeg(), 'plant_name' => 'Money plant', 'language' => 'hi'])->assertOk();
        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_starts_with($request->url(), self::SERVICE . '/plant-diagnosis')
                && $request->hasHeader('X-Api-Key', 'test-shared-key')
                && $request->hasHeader('X-Request-Id')
                && $body['request_id'] === $request->header('X-Request-Id')[0]
                && $body['diagnosis_model'] === 'alt-m'
                && $body['use_plant_id'] === true
                && $body['language'] === 'hi'
                && $body['plant_name'] === 'Money plant'
                && !empty($body['image_base64']);
        });
    }

    public function test_an_unsupported_stored_model_is_not_forwarded(): void
    {
        DB::table('plant_doctor_settings')->update(['openai_model' => 'claude-opus-4-8']);
        Http::fake([self::SERVICE . '/*' => Http::response($this->serviceBody('plant_detected'), 200)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertOk();
        Http::assertSent(fn ($request) => $request->data()['diagnosis_model'] === null);
    }

    public function test_a_dated_model_snapshot_is_priced_as_its_base_model(): void
    {
        $body = $this->serviceBody('plant_detected');
        $body['usage']['stages'] = [
            ['stage' => 'gate', 'model' => 'gate-m-2026-03-17', 'input_tokens' => 1000, 'output_tokens' => 0, 'latency_ms' => 1],
        ];
        Http::fake([self::SERVICE . '/*' => Http::response($body, 200)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertOk();
        $this->assertEqualsWithDelta(0.001, PlantDoctorLog::latest('id')->first()->cost_usd, 1e-6); // 1000 × 1 / 1M, not the fallback
    }

    public function test_an_unpriced_model_is_billed_at_the_fallback_and_logged(): void
    {
        Log::spy();
        $body = $this->serviceBody('plant_detected', ['usage' => ['stages' => [
            ['stage' => 'gate', 'model' => 'mystery-m', 'input_tokens' => 1000, 'output_tokens' => 0, 'latency_ms' => 1],
        ]]]);
        $body['usage']['stages'] = [$body['usage']['stages'][0]];
        Http::fake([self::SERVICE . '/*' => Http::response($body, 200)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertOk();
        $this->assertEqualsWithDelta(0.1, PlantDoctorLog::latest('id')->first()->cost_usd, 1e-6); // 1000 × 100 / 1M
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'unpriced'))->once();
    }

    public function test_a_reply_without_an_outcome_is_shown_as_uncertain_with_no_diagnosis(): void
    {
        // The shape the pre-rewrite service returns: is_plant plus a full diagnosis, no gate.
        $legacy = $this->serviceBody('plant_detected');
        unset($legacy['outcome'], $legacy['stage_results'], $legacy['guidance']);
        Http::fake([self::SERVICE . '/*' => Http::response($legacy, 200)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertOk()
            ->assertJsonPath('data.outcome', 'uncertain')
            ->assertJsonPath('data.is_plant', false)
            ->assertJsonPath('data.diagnosis', [])
            ->assertJsonPath('data.rejection_reason', "I can't tell for sure whether this is a plant I can assess. Please send a clear, close-up photo of the plant.");
        $this->assertSame('uncertain', PlantDoctorLog::latest('id')->first()->outcome);
    }

    public function test_a_percentage_health_score_is_normalised(): void
    {
        Http::fake([self::SERVICE . '/*' => Http::response($this->serviceBody('plant_detected', ['overall_health_score' => 85]), 200)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertOk()->assertJsonPath('data.overall_health_score', 0.85);
    }

    public function test_service_4xx_with_a_known_code_passes_through(): void
    {
        Http::fake([self::SERVICE . '/*' => Http::response(['code' => 'image_too_large', 'message' => 'That photo is too large. Please send one under 6 MB.'], 422)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertStatus(422)
            ->assertJsonPath('code', 'image_too_large')
            ->assertJsonPath('message', 'That photo is too large. Please send one under 6 MB.');
        $this->assertSame(0, PlantDoctorLog::count());
    }

    public function test_service_failures_are_502_and_not_logged(): void
    {
        Http::fake([self::SERVICE . '/*' => Http::sequence()
            ->push(['code' => 'internal_error', 'message' => 'x'], 500)
            ->push(['code' => 'upstream_timeout', 'message' => 'x'], 504)
            ->push(['code' => 'bogus', 'message' => 'x'], 422)]);   // an unknown 4xx code is not passed through
        foreach ([1, 2, 3] as $_) {
            $this->diagnose(['image_base64' => $this->jpeg()])->assertStatus(502)->assertJsonPath('code', 'upstream');
        }
        $this->assertSame(0, PlantDoctorLog::count());
    }

    public function test_an_unreachable_service_is_502(): void
    {
        Http::fake([self::SERVICE . '/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertStatus(502)
            ->assertJsonPath('code', 'upstream')
            ->assertJsonPath('message', 'Could not reach the Plant Doctor service.');
        $this->assertSame(0, PlantDoctorLog::count());
    }

    public function test_a_signed_in_customer_is_recorded_on_the_log_row(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        $user = new User();
        $user->id = 77;
        Sanctum::actingAs($user);
        Http::fake([self::SERVICE . '/*' => Http::response($this->serviceBody('plant_detected'), 200)]);
        $this->diagnose(['image_base64' => $this->jpeg()])->assertOk();
        $this->assertSame(77, PlantDoctorLog::latest('id')->first()->user_id);
    }

    public function test_sanitise_is_strict_about_outcome_values(): void
    {
        $out = PlantDoctorController::sanitise(['outcome' => 'PLANT_DETECTED', 'is_plant' => true, 'diagnosis' => [['condition' => 'x']]]);
        $this->assertSame('uncertain', $out['outcome']);
        $this->assertSame([], $out['diagnosis']);
        $this->assertFalse($out['is_plant']);

        $out = PlantDoctorController::sanitise(['outcome' => 'plant_detected', 'diagnosis' => 'not a list', 'identification' => 'nope', 'overall_health_score' => 250]);
        $this->assertTrue($out['is_plant']);
        $this->assertSame([], $out['diagnosis']);
        $this->assertSame('', $out['identification']['common_name']);
        $this->assertSame(1.0, $out['overall_health_score']);
        $this->assertSame(['ask_for' => [], 'message' => ''], $out['guidance']);
    }
}
