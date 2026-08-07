<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * GET /vendors, GET /shops and GET /near-by-shop were registered with NO middleware and
 * returned raw Eloquent models. VendorResource additionally flattens banking and compliance
 * into first-class fields, so on 2026-08-07 an anonymous request to production returned
 * account_number, bank, account_holder, owner_email, mobile and admin_commission_rate for
 * every shop; staging (which holds a real supplier) also returned ifsc, pan, gst_number
 * and upi. GET /shops additionally carried the owner's profile and geolocation columns.
 *
 * Route-level assertions, deliberately — like ExportRouteAuthTest, what regressed here was
 * the MIDDLEWARE, and a route assertion pins that without needing the full marvel schema.
 * The response-body canary lives in PublicShopLeakBodyTest, which does need the schema.
 *
 * `shops/{slug}` stays public on purpose: the storefront's maintenance banner reads the shop
 * name off it. It must serialise through PublicShopResource instead of the raw model.
 */
final class PublicShopLeakTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function authenticatedShopRoutes(): array
    {
        return [
            'vendor list'   => ['api/vendors'],
            'vendor detail' => ['api/vendors/{vendor}'],
            'shop list'     => ['api/shops'],
        ];
    }

    /** @dataProvider authenticatedShopRoutes */
    public function test_supplier_facing_routes_require_authentication(string $uri): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, "route {$uri} is missing");
        $this->assertContains(
            'auth:sanctum',
            $route->gatherMiddleware(),
            "{$uri} is PUBLIC — it exposes supplier banking/compliance data and owner PII"
        );
    }

    public function test_an_anonymous_vendor_request_is_rejected(): void
    {
        // 401 from auth:sanctum — the controller is never entered, so VendorResource
        // never gets the chance to serialise a bank account.
        $this->getJson('/api/vendors')->assertStatus(401);
        $this->getJson('/api/shops')->assertStatus(401);
    }

    /**
     * The literal sub-routes must still win over {vendor}. Routes match in registration
     * order, so wrapping the apiResource in a middleware group is only safe while the
     * where() constraint keeps `/vendors/list` from resolving as a slug.
     */
    public function test_vendor_slug_route_still_excludes_the_literal_sub_routes(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/vendors/{vendor}' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, 'the vendor detail route is missing');
        $this->assertSame(
            '(?!list$|check-unique$)[A-Za-z0-9._-]+',
            $route->wheres['vendor'] ?? null,
            'the {vendor} constraint is gone — /vendors/list now resolves as a vendor slug'
        );
    }

    /** The storefront still needs this one anonymously; it must simply not leak. */
    public function test_shop_detail_stays_public_for_the_storefront(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/shops/{slug}' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, 'the public shop detail route is missing');
        $this->assertNotContains(
            'auth:sanctum',
            $route->gatherMiddleware(),
            'shops/{slug} must stay public — the storefront maintenance banner reads it'
        );
    }
}
