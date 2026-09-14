<?php

namespace Tests\Feature\Accounting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Marvel\Http\Controllers\AccountingReportController;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Spec §55 / tests 45-47: vendors are read-only on their own books; every accounting route is permission-gated. */
class SecurityTest extends OrdersTestCase
{
    private function routes(string $prefix): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), $prefix)) {
                $out[$r->uri() . ' ' . implode('|', $r->methods())] = $r->gatherMiddleware();
            }
        }
        return $out;
    }

    // 46/47 — every accounting/* route needs auth AND an accounting.* permission (super_admin holds them all).
    public function test_every_accounting_route_is_permission_gated(): void
    {
        $routes = $this->routes('api/accounting/');
        $this->assertGreaterThan(40, count($routes));
        foreach ($routes as $uri => $mw) {
            $this->assertContains('auth:sanctum', $mw, $uri . ' is not authenticated');
            $perm = array_values(array_filter($mw, fn ($m) => str_starts_with($m, 'permission:accounting.')));
            $this->assertNotEmpty($perm, $uri . ' has no permission:accounting.* middleware');
        }
        // writes need more than view
        foreach ($routes as $uri => $mw) {
            if (str_contains($uri, 'POST') || str_contains($uri, 'PUT') || str_contains($uri, 'DELETE')) {
                $this->assertEmpty(array_filter($mw, fn ($m) => $m === 'permission:accounting.view'), $uri . ' is a write behind view-only permission');
            }
        }
    }

    // 45 — vendor self-serve routes are authenticated and a vendor cannot read another shop's statement.
    public function test_vendor_cannot_read_another_shops_statement(): void
    {
        foreach ($this->routes('api/vendor/') as $uri => $mw) {
            $this->assertContains('auth:sanctum', $mw, $uri . ' is not authenticated');
        }
        $owner = new class { public $shops; };
        $owner->shops = collect([(object) ['id' => 11]]);
        $req = Request::create('/vendor/statement', 'GET', ['shop_id' => 12]);
        $req->setUserResolver(fn () => $owner);
        try {
            (new AccountingReportController())->myStatement($req);
            $this->fail('another shop\'s statement must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // and an owner with no shop at all is refused too
        $none = new class { public $shops; };
        $none->shops = collect();
        $req2 = Request::create('/vendor/statement', 'GET');
        $req2->setUserResolver(fn () => $none);
        try {
            (new AccountingReportController())->myStatement($req2);
            $this->fail('no shop → refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // return decisions are staff-only even for a user holding orders.edit (a store owner).
    public function test_return_decisions_are_staff_only(): void
    {
        $vendor = new class { public function hasPermissionTo($p) { return $p === 'store_owner'; } };
        $req = Request::create('/return-requests/1/approve', 'POST');
        $req->setUserResolver(fn () => $vendor);
        try {
            (new \Marvel\Http\Controllers\ReturnRequestController())->transition($req, 1, 'approve');
            $this->fail('vendors must not decide returns');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $routes = $this->routes('api/return-requests');
        $this->assertNotEmpty($routes);
        foreach ($routes as $uri => $mw) {
            $this->assertContains('auth:sanctum', $mw, $uri);
        }
    }
}
