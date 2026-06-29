<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Product;
use Marvel\Http\Middleware\ResolveLanguage;
use Marvel\Translation\Providers\GoogleTranslateProvider;
use Marvel\Translation\TranslationContext;
use Marvel\Translation\TranslationManager;
use Tests\TestCase;

/**
 * Read-only tests for the enterprise translation engine — config + Http::fake +
 * read-only overlay, so they never write to the database.
 */
class TranslationEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'translation.enabled' => true,
            'translation.default_language' => 'en',
            'translation.languages' => ['en', 'hi', 'ta'],
            'translation.default_provider' => 'google',
        ]);
    }

    private function resolveLanguage(Request $request): string
    {
        $captured = 'en';
        (new ResolveLanguage())->handle($request, function ($r) use (&$captured) {
            $captured = $r->language;
            return null;
        });
        return $captured;
    }

    /** ?language= query param takes precedence over Accept-Language. */
    public function test_query_param_language_wins_over_header(): void
    {
        $req = Request::create('/api/products?language=hi', 'GET');
        $req->headers->set('Accept-Language', 'ta');
        $this->assertSame('hi', $this->resolveLanguage($req));
    }

    /** Accept-Language is q-weighted and matched to the supported set. */
    public function test_accept_language_is_q_weighted(): void
    {
        $req = Request::create('/api/products', 'GET');
        $req->headers->set('Accept-Language', 'fr;q=0.9, ta;q=0.8, hi;q=0.7');
        $this->assertSame('ta', $this->resolveLanguage($req));
    }

    /** Unsupported / absent language falls back to the default (back-compat). */
    public function test_unsupported_language_falls_back_to_default(): void
    {
        $req = Request::create('/api/products', 'GET');
        $req->headers->set('Accept-Language', 'fr-FR,de;q=0.8');
        $this->assertSame('en', $this->resolveLanguage($req));
    }

    /** The manager resolves the configured default provider + lists all five. */
    public function test_manager_resolves_google_default_and_lists_providers(): void
    {
        $manager = new TranslationManager();
        $this->assertSame('google', $manager->active()->id());
        foreach (['google', 'openai', 'claude', 'azure', 'deepl'] as $p) {
            $this->assertContains($p, $manager->available());
        }
    }

    /** Google provider builds a correct v2 request and maps results back by key. */
    public function test_google_provider_builds_request_and_parses_response(): void
    {
        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => ['translations' => [
                    ['translatedText' => 'एक'],
                    ['translatedText' => 'दो'],
                ]],
            ], 200),
        ]);

        $provider = new GoogleTranslateProvider(['api_key' => 'AIza-test-key']);
        $out = $provider->translateBatch(['name' => 'one', 'desc' => 'two'], 'hi', 'en');

        $this->assertSame(['name' => 'एक', 'desc' => 'दो'], $out);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'language/translate/v2')
            && str_contains($r->url(), 'key=AIza-test-key')
            && ($r['target'] ?? null) === 'hi'
            && ($r['source'] ?? null) === 'en');
    }

    /** The overlay activates only for a supported, non-default language with the engine on. */
    public function test_context_is_active_only_for_supported_non_default_language(): void
    {
        $ctx = new TranslationContext();

        $ctx->setLanguage('en');
        $this->assertFalse($ctx->isActive(), 'default language should be inactive');

        $ctx->setLanguage('hi');
        $this->assertTrue($ctx->isActive(), 'supported non-default should be active');

        $ctx->setLanguage('zz');
        $this->assertFalse($ctx->isActive(), 'unsupported language should be inactive');

        config(['translation.enabled' => false]);
        $ctx->setLanguage('hi');
        $this->assertFalse($ctx->isActive(), 'kill-switch should disable the overlay');
    }

    /**
     * Fail-safe: under the sync driver with no provider key, an overlay miss must
     * return English (null) and NEVER throw or dispatch inline (the staging crash).
     */
    public function test_overlay_miss_returns_english_without_throwing(): void
    {
        config([
            'translation.enabled' => true,
            'queue.default' => 'sync',
            'translation.providers.google.api_key' => null,
        ]);

        $ctx = new TranslationContext();
        $ctx->setLanguage('hi');

        $product = new Product(['name' => 'Rose Plant']);
        $product->id = 999_999_999; // id that has no translation row
        $ctx->register($product);

        // Miss → English fallback (null) → caller uses the canonical column. No throw.
        $this->assertNull($ctx->translate($product, 'name'));
    }
}
