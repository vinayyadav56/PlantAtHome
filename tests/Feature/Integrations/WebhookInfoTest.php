<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\IntegrationService;
use Marvel\Integrations\ProviderRegistry;
use Tests\TestCase;

/**
 * The webhook block tells an operator what to paste into a partner's dashboard. It is COMPUTED from
 * the shipping-service URL rather than stored, so the thing worth pinning is that it stays correct
 * and never carries the token itself.
 */
final class WebhookInfoTest extends TestCase
{
    use RefreshDatabase;

    private function card(string $slug): array
    {
        $controller = app(\Marvel\Http\Controllers\IntegrationController::class);
        $request = \Illuminate\Http\Request::create("/api/integrations/{$slug}", 'GET');
        $request->setUserResolver(fn () => new class {
            public function hasPermissionTo(): bool { return true; }
            public function can(): bool { return true; }
            public $id = 1;
        });

        return json_decode($controller->show($request, $slug)->getContent(), true)['data'] ?? [];
    }

    public function test_delivery_partners_expose_a_registerable_webhook_url(): void
    {
        app(IntegrationService::class)->put('shipping_service', configuration: ['url' => 'https://ship.example']);

        foreach (['porter', 'borzo', 'shiprocket'] as $slug) {
            $webhook = $this->card($slug)['webhook'] ?? null;

            $this->assertNotNull($webhook, "{$slug} should expose webhook registration details");
            $this->assertSame("https://ship.example/webhooks/{$slug}", $webhook['url']);
            $this->assertSame('X-Api-Key', $webhook['auth_header']);
            $this->assertFalse($webhook['secret_set'], 'no token configured yet, so it must report false');
        }
    }

    public function test_the_webhook_block_never_contains_the_token(): void
    {
        $secret = 'WEBHOOK-CANARY-11223344';
        app(IntegrationService::class)->put(
            'porter',
            credentials: ['api_key' => 'k', 'webhook_token' => $secret],
            configuration: []
        );
        app(IntegrationService::class)->put('shipping_service', configuration: ['url' => 'https://ship.example']);

        $card = $this->card('porter');

        $this->assertTrue($card['webhook']['secret_set'], 'a configured token should report true');
        $this->assertStringNotContainsString(
            $secret,
            json_encode($card),
            'the provider payload leaked the webhook token'
        );
    }

    public function test_providers_without_callbacks_expose_no_webhook_block(): void
    {
        // An AI or maps provider does not call us back; rendering an empty webhook panel for it
        // would imply there is something to register.
        foreach (['openai', 'google_maps', 'ai_chat'] as $slug) {
            $this->assertArrayHasKey($slug, ProviderRegistry::all());
            $this->assertNull($this->card($slug)['webhook'] ?? null, "{$slug} should have no webhook block");
        }
    }

    public function test_url_is_null_when_the_shipping_service_url_is_unknown(): void
    {
        IntegrationProvider::where('provider_slug', 'shipping_service')->delete();
        config(['services.shipping_service.url' => '']);

        $webhook = $this->card('porter')['webhook'];

        // Better to render nothing than to hand someone "/webhooks/porter" and have them register
        // a relative path in Porter's dashboard.
        // (assertNull on `$x ?? 'fallback'` cannot work — ?? fires on null, so it would always
        // return the fallback and the assertion could never pass.)
        $this->assertArrayHasKey('url', $webhook);
        $this->assertNull($webhook['url']);
    }
}
