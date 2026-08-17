<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Marvel\Database\Models\Shop;
use Marvel\Http\Resources\VendorResource;
use Tests\TestCase;

/**
 * The 2026-08 admin IA/RBAC audit found four weak endpoints. Route-level assertions in the
 * PublicShopLeakTest style — what regresses here is MIDDLEWARE and serialisation, and a route
 * assertion pins the former without the full marvel schema.
 *
 *  1. POST orders/{id}/split-shipment sat in the plain auth-only group with no ownership check:
 *     any signed-in CUSTOMER could re-parcel any order by id (the controller findOrFail'd it and
 *     went straight to work). Its two siblings in that group check authorization in the
 *     controller; this one never did, so it needs the gate at the route.
 *  2. GET attachments was the one unauthenticated apiResource in the public block — an enumerable
 *     index of every upload (invoices, KYC paperwork), no gate at all.
 *  3. GET shops returned RAW Eloquent models to non-admins (no $hidden, `settings` json cast) —
 *     covered at the serialisation layer by ShopController::index returning PublicShopResource
 *     for non-admins; the route already demanded auth (asserted in PublicShopLeakTest).
 *  4. VendorResource flattened banking/tax identity for every caller the /vendors read gate
 *     admits — and that gate deliberately admits every store_owner, so any vendor could read
 *     every other vendor's account_number/ifsc/pan/gst/upi. Asserted directly on the resource.
 */
final class AdminRouteHardeningTest extends TestCase
{
    private function route(string $method, string $uri)
    {
        return collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));
    }

    public function test_split_shipment_is_admin_gated(): void
    {
        $route = $this->route('POST', 'api/orders/{id}/split-shipment');

        $this->assertNotNull($route, 'split-shipment route is missing');
        $this->assertContains(
            'permission:super_admin',
            $route->gatherMiddleware(),
            'split-shipment must be admin-gated — in the auth-only group ANY customer could re-parcel ANY order',
        );
    }

    public function test_attachment_enumeration_requires_authentication(): void
    {
        foreach ([['GET', 'api/attachments'], ['GET', 'api/attachments/{attachment}']] as [$m, $uri]) {
            $route = $this->route($m, $uri);
            $this->assertNotNull($route, "{$uri} route is missing");
            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware(),
                "{$uri} is an enumerable index of every upload and must not be public",
            );
        }
    }

    public function test_vendor_resource_hides_banking_from_non_admins(): void
    {
        $shop = new Shop([
            'name' => 'Leaf & Co', 'slug' => 'leaf-co', 'is_active' => true,
            'gst_number' => '07AAAAA0000A1Z5', 'upi' => 'leaf@upi',
            'settings' => [
                'banking'    => ['ifsc' => 'HDFC0000001', 'branch' => 'GK-1', 'upi' => 'leaf@upi'],
                'compliance' => ['gst' => '07AAAAA0000A1Z5', 'pan' => 'AAAAA0000A'],
            ],
        ]);
        // account/bank flatten from the BALANCE relation's payment_info, not settings.
        $shop->setRelation('balance', new \Marvel\Database\Models\Balance([
            'payment_info' => ['bank' => 'HDFC', 'name' => 'Leaf & Co', 'account' => '1234567890'],
            'admin_commission_rate' => 12,
        ]));

        $asRole = function (bool $admin) use ($shop): array {
            $request = Request::create('/api/vendors', 'GET');
            $request->setUserResolver(fn () => new class($admin)
            {
                public function __construct(private bool $admin)
                {
                }

                public function hasPermissionTo($perm): bool
                {
                    return $this->admin;
                }
            });

            return (new VendorResource($shop))->toArray($request);
        };

        $vendorView = $asRole(false);
        foreach (['account_number', 'ifsc', 'bank', 'account_holder', 'branch', 'upi', 'pan', 'gst_number', 'admin_commission_rate', 'owner_email'] as $field) {
            $this->assertTrue(
                !array_key_exists($field, $vendorView) || $vendorView[$field] instanceof \Illuminate\Http\Resources\MissingValue,
                "{$field} must not serialise for a non-admin — any store_owner can call /vendors",
            );
        }
        // The directory half stays: vendors legitimately browse the supplier list.
        $this->assertSame('Leaf & Co', $vendorView['name']);

        $adminView = $asRole(true);
        $this->assertSame('1234567890', $adminView['account_number'], 'admins must keep the full payload');
        $this->assertSame('HDFC0000001', $adminView['ifsc']);
    }
}
