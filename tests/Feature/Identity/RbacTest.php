<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;

/**
 * Phase 1 acceptance (part 2): role enforcement (a nursery_staff token is
 * rejected on an admin route) and vendor scoping (a nursery user cannot reach
 * another nursery's data; platform admins can reach any).
 */
class RbacTest extends IdentityTestCase
{
    /* ── role enforcement on the admin route ───────────────────────────────── */

    public function test_nursery_staff_is_rejected_on_the_admin_route(): void
    {
        $token = $this->accessToken('staff.a@plantathome.test');

        $this->getJson('/api/v1/admin/ping', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'FORBIDDEN');
    }

    public function test_customer_is_rejected_on_the_admin_route(): void
    {
        $token = $this->accessToken('customer@plantathome.test');

        $this->getJson('/api/v1/admin/ping', $this->bearer($token))
            ->assertStatus(403);
    }

    public function test_admin_is_allowed_on_the_admin_route(): void
    {
        $token = $this->accessToken('admin@plantathome.test');

        $this->getJson('/api/v1/admin/ping', $this->bearer($token))
            ->assertStatus(200)
            ->assertJsonPath('data.area', 'admin');
    }

    public function test_admin_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/ping')->assertStatus(401);
    }

    /* ── vendor (nursery) scoping ──────────────────────────────────────────── */

    public function test_nursery_owner_can_access_their_own_nursery(): void
    {
        $token = $this->accessToken('owner.a@plantathome.test');

        $this->getJson('/api/v1/nursery/'.IdentityAccessSeeder::NURSERY_A.'/dashboard', $this->bearer($token))
            ->assertStatus(200)
            ->assertJsonPath('data.scope_ok', true)
            ->assertJsonPath('data.nursery_id', IdentityAccessSeeder::NURSERY_A);
    }

    public function test_nursery_owner_is_blocked_from_another_nursery(): void
    {
        $token = $this->accessToken('owner.a@plantathome.test');

        $this->getJson('/api/v1/nursery/'.IdentityAccessSeeder::NURSERY_B.'/dashboard', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'FORBIDDEN_SCOPE');
    }

    public function test_nursery_staff_is_also_scoped_to_their_nursery(): void
    {
        $token = $this->accessToken('staff.a@plantathome.test');

        // own nursery ok
        $this->getJson('/api/v1/nursery/'.IdentityAccessSeeder::NURSERY_A.'/dashboard', $this->bearer($token))
            ->assertStatus(200);
        // other nursery blocked
        $this->getJson('/api/v1/nursery/'.IdentityAccessSeeder::NURSERY_B.'/dashboard', $this->bearer($token))
            ->assertStatus(403);
    }

    public function test_platform_admin_can_access_any_nursery(): void
    {
        $token = $this->accessToken('admin@plantathome.test');

        $this->getJson('/api/v1/nursery/'.IdentityAccessSeeder::NURSERY_B.'/dashboard', $this->bearer($token))
            ->assertStatus(200)
            ->assertJsonPath('data.viewer.is_admin_view', true);
    }

    public function test_customer_is_rejected_from_nursery_routes(): void
    {
        $token = $this->accessToken('customer@plantathome.test');

        $this->getJson('/api/v1/nursery/'.IdentityAccessSeeder::NURSERY_A.'/dashboard', $this->bearer($token))
            ->assertStatus(403);
    }
}
