<?php

namespace Tests\Feature\Integrations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Marvel\Http\Controllers\IntegrationController;
use Tests\TestCase;

/**
 * The security contract of the Integration Management API: a credential VALUE never leaves the
 * server.
 *
 * This is the whole point of the module — a screen that centralises every third-party secret is
 * also the single best place to leak them all at once. Reads answer "which fields are set", never
 * what they are set to.
 *
 * The controller is dispatched DIRECTLY (the pattern CourierPartnerProxyTest uses) because the live
 * routes sit behind auth:sanctum + permission middleware that needs the full ACL schema, which this
 * DB-less TestCase does not have. IntegrationService tolerates a missing table and falls through to
 * config()/env(), so these assertions run against the legacy-fallback path — which is exactly the
 * path a freshly-deployed environment uses before anything has been saved.
 */
final class IntegrationControllerMaskingTest extends TestCase
{
    private const RAZORPAY_SECRET = 'rzp-secret-SHOULD-NEVER-APPEAR';
    private const SHIPPING_KEY    = 'shipping-api-key-SHOULD-NEVER-APPEAR';
    private const MAPS_KEY        = 'google-maps-key-SHOULD-NEVER-APPEAR';

    private IntegrationController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.environment'          => 'production',
            'integrations.cache_ttl'            => 0,
            'shop.razorpay.key_id'              => 'rzp_test_publicid',
            'shop.razorpay.key_secret'          => self::RAZORPAY_SECRET,
            'services.shipping_service.url'     => 'https://shipping.test',
            'services.shipping_service.api_key' => self::SHIPPING_KEY,
            'location.google_maps_server_key'   => self::MAPS_KEY,
        ]);

        $this->controller = new IntegrationController();
    }

    private function req(string $uri, array $query = []): Request
    {
        return Request::create($uri, 'GET', $query);
    }

    public function test_index_never_returns_a_credential_value(): void
    {
        $json = $this->controller->index($this->req('/integrations'))->getContent();

        $this->assertStringNotContainsString(self::RAZORPAY_SECRET, $json);
        $this->assertStringNotContainsString(self::SHIPPING_KEY, $json);
        $this->assertStringNotContainsString(self::MAPS_KEY, $json);
    }

    public function test_show_never_returns_a_credential_value(): void
    {
        foreach (['razorpay', 'shipping_service', 'google_maps'] as $slug) {
            $json = $this->controller->show($this->req("/integrations/{$slug}"), $slug)->getContent();

            $this->assertStringNotContainsString(self::RAZORPAY_SECRET, $json, "{$slug} leaked the razorpay secret");
            $this->assertStringNotContainsString(self::SHIPPING_KEY, $json, "{$slug} leaked the shipping key");
            $this->assertStringNotContainsString(self::MAPS_KEY, $json, "{$slug} leaked the maps key");
        }
    }

    /**
     * Presence must still be reported, or the admin shows a working provider as unconfigured and an
     * operator "fixes" it by overwriting a good credential.
     */
    public function test_credentials_set_reports_presence_as_booleans(): void
    {
        $data = json_decode($this->controller->show($this->req('/integrations/razorpay'), 'razorpay')->getContent(), true)['data'];

        $this->assertArrayHasKey('credentials_set', $data);
        $this->assertTrue($data['credentials_set']['key_secret'], 'a config-sourced secret still counts as set');
        foreach ($data['credentials_set'] as $field => $value) {
            $this->assertIsBool($value, "credentials_set.{$field} must be a boolean, never a value");
        }
    }

    /**
     * A PUBLIC identifier is supposed to be visible — it is what the browser and the mobile app
     * need. Asserting it appears proves the masking above is field-aware rather than blanket
     * suppression that would leave the form unusable.
     */
    public function test_public_configuration_values_are_returned(): void
    {
        $data = json_decode($this->controller->show($this->req('/integrations/razorpay'), 'razorpay')->getContent(), true)['data'];

        $this->assertSame('rzp_test_publicid', $data['configuration']['key_id']);
    }

    public function test_unknown_provider_is_a_404_not_an_empty_success(): void
    {
        $res = $this->controller->show($this->req('/integrations/not-a-provider'), 'not-a-provider');

        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_index_exposes_every_registered_provider_with_a_health_state(): void
    {
        $body = json_decode($this->controller->index($this->req('/integrations'))->getContent(), true);

        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $card) {
            $this->assertArrayHasKey('health_status', $card);
            $this->assertArrayHasKey('configured', $card);
            $this->assertArrayNotHasKey('credentials', $card, 'the raw credential bag must never be serialized');
        }
    }

    /**
     * The routes must be gated on the fine-grained permissions AND throttled — test/sync make
     * outbound calls, so an ungated version is a way to bill us or probe a partner.
     */
    public function test_routes_are_permission_gated_and_throttled(): void
    {
        $expect = [
            'GET|api/integrations'              => ['settings.integrations.view', null],
            'PUT|api/integrations/{slug}'       => ['settings.integrations.edit', 'throttle:30,1'],
            'POST|api/integrations/{slug}/test' => ['settings.integrations.test', 'throttle:20,1'],
            'POST|api/integrations/{slug}/sync' => ['settings.integrations.edit', 'throttle:20,1'],
        ];

        foreach ($expect as $key => [$permission, $throttle]) {
            [$method, $uri] = explode('|', $key);
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
            );

            $this->assertNotNull($route, "route {$key} is not registered");
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "{$key} must require authentication");
            $this->assertContains("permission:{$permission}", $middleware, "{$key} must require {$permission}");
            if ($throttle !== null) {
                $this->assertContains($throttle, $middleware, "{$key} must be throttled");
            }
        }
    }
}
