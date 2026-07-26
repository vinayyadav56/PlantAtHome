<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The CSV export routes stream a shop's whole catalogue and were reachable with
 * no authentication for any enumerable {shop_id}. They are now signature-gated,
 * with an authed + permission-checked issuer minting short-lived URLs.
 *
 * These assertions are deliberately route-level rather than end-to-end: the
 * exports need the full marvel schema to stream anything, but what regressed
 * here was the MIDDLEWARE, and middleware is exactly what a route assertion
 * pins. An unsigned request must never reach the controller.
 */
final class ExportRouteAuthTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function signedExportRoutes(): array
    {
        return [
            'products'          => ['api/export-products/{shop_id}', 'export_products.signed'],
            'variation options' => ['api/export-variation-options/{shop_id}', 'export_variation_options.signed'],
            'attributes'        => ['api/export-attributes/{shop_id}', 'export_attributes.signed'],
        ];
    }

    /** @dataProvider signedExportRoutes */
    public function test_export_routes_require_a_valid_signature(string $uri, string $name): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri);

        $this->assertNotNull($route, "route {$uri} is missing");
        $this->assertContains('signed', $route->gatherMiddleware(), "{$uri} is not signature-gated");
        $this->assertSame($name, $route->getName(), "{$uri} must be named for URL::temporarySignedRoute");
    }

    public function test_an_unsigned_export_request_is_rejected(): void
    {
        // 403 from the `signed` middleware — the controller is never entered, so
        // no catalogue bytes are streamed.
        $this->getJson('/api/export-products/1')->assertStatus(403);
        $this->getJson('/api/export-attributes/1')->assertStatus(403);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $this->getJson('/api/export-products/1?signature=deadbeef&expires=' . (time() + 300))
            ->assertStatus(403);
    }

    public function test_the_url_issuer_requires_authentication(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/export-urls/{shop_id}');

        $this->assertNotNull($route, 'export-urls issuer route is missing');
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
    }

    /** The paid-LLM endpoints were an open, unmetered spend tap. */
    public function test_ai_endpoints_require_authentication(): void
    {
        foreach (['api/generate-descriptions', 'api/suggest-categories'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($route, "route {$uri} is missing");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware(), "{$uri} is unauthenticated");
        }

        $this->postJson('/api/generate-descriptions', ['name' => 'x'])->assertStatus(401);
        $this->postJson('/api/suggest-categories', ['name' => 'x'])->assertStatus(401);
    }
}
