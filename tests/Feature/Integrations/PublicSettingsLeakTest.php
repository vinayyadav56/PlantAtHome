<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Three feature-settings endpoints are PUBLIC (Routes.php — outside the super-admin group), because
 * the storefront has to know whether a feature is switched on before rendering its entry point:
 *
 *   GET /plant-doctor/settings   GET /care-plans/settings   GET /ai-chat/settings
 *
 * They currently return only `enabled` (+ max_prompts). Nothing enforced that — it was a property of
 * how each controller happened to be written, and Slice 2 moved those controllers onto a service
 * that CAN return the service key. One careless `$setting->toArray()` would publish a credential to
 * anonymous callers, and `GET /settings` is edge-cached for 60s so it would be cached too.
 *
 * This test is the enforcement.
 */
final class PublicSettingsLeakTest extends TestCase
{
    use RefreshDatabase;

    /** Anything matching this in a public response body is a leak. */
    private const FORBIDDEN = ['service_api_key', 'api_key', 'secret', 'password', 'token', 'service_url'];

    public function test_public_feature_settings_endpoints_expose_no_credentials(): void
    {
        // Give every feature a real-looking secret so a leak has something recognisable to leak.
        $sentinel = 'LEAK-CANARY-a1b2c3d4e5';
        foreach ([
            'ai_chat_settings'      => 'service_api_key',
            'plant_doctor_settings' => 'service_api_key',
            'care_plan_settings'    => 'service_api_key',
        ] as $table => $column) {
            if (DB::table($table)->exists()) {
                DB::table($table)->update([$column => $sentinel, 'service_url' => 'https://internal.example']);
            } else {
                DB::table($table)->insert(['enabled' => true, $column => $sentinel, 'service_url' => 'https://internal.example']);
            }
        }

        foreach (['plant-doctor/settings', 'care-plans/settings', 'ai-chat/settings'] as $path) {
            $response = $this->getJson('/api/' . $path);
            $response->assertOk();

            $body = $response->getContent();
            $this->assertStringNotContainsString($sentinel, $body, "$path leaked a service credential");

            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $body,
                    "$path exposed '$needle' to an anonymous caller — these endpoints must return only the enabled flag"
                );
            }

            // Positive assertion: it still answers the question it exists to answer.
            $this->assertArrayHasKey('enabled', $response->json('data'), "$path must still report enabled");
        }
    }

    /**
     * The public settings blob is edge-cached, so a secret landing in it is cached too.
     */
    public function test_public_settings_options_contain_no_secret_shaped_keys(): void
    {
        $options = (array) (\Marvel\Database\Models\Settings::getData()->options ?? []);

        array_walk_recursive($options, function ($value, $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|_)(secret|password|api_key|access_token|private_key)($|_)/i',
                (string) $key,
                "settings.options.$key looks like a credential, and GET /settings is public"
            );
        });

        $this->assertTrue(true); // the walk above carries the assertions
    }
}
