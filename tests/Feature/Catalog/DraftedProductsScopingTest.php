<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Http\Controllers\ProductController;
use Tests\TestCase;

/**
 * Scoping of the draft/review queue (GET draft-products → fetchDraftedProducts).
 *
 * The super-admin arm used to return the raw query even when the request carried a
 * shop_id — so an admin opening a VENDOR's "My submitted products" page was shown the
 * entire platform review queue (thousands of import drafts), annotated 2026-10-01.
 * These tests pin the BUILT QUERY (sql + bindings), not rows: scoping is the contract.
 */
final class DraftedProductsScopingTest extends TestCase
{
    private function query(array $params, array $perms)
    {
        $request = Request::create('/api/draft-products', 'GET', $params);
        $user = \Mockery::mock();
        $user->shouldReceive('hasPermissionTo')
            ->andReturnUsing(fn ($p) => in_array($p, $perms, true));
        $request->setUserResolver(fn () => $user);

        return app(ProductController::class)->fetchDraftedProducts($request);
    }

    public function test_admin_with_a_shop_id_sees_only_that_vendors_proposals(): void
    {
        $q = $this->query(['shop_id' => 7], [Permission::SUPER_ADMIN]);
        $sql = $q->toSql();

        $this->assertStringContainsString('proposed_by_shop_id', $sql);
        $this->assertContains(7, $q->getBindings());
    }

    public function test_admin_without_a_shop_id_keeps_the_global_queue(): void
    {
        $q = $this->query([], [Permission::SUPER_ADMIN]);

        $this->assertStringNotContainsString('proposed_by_shop_id', $q->toSql());
    }

    public function test_store_owner_is_scoped_to_the_requested_shop(): void
    {
        $q = $this->query(['shop_id' => 7], [Permission::STORE_OWNER]);
        $sql = $q->toSql();

        $this->assertStringContainsString('proposed_by_shop_id', $sql);
        $this->assertContains(7, $q->getBindings());
    }
}
