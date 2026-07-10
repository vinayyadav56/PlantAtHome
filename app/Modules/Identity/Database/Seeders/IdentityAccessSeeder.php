<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Domain\AccessMatrix;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\RoleName;
use App\Modules\Identity\Infrastructure\Models\IdentityPermission;
use App\Modules\Identity\Infrastructure\Models\IdentityRole;
use App\Modules\Identity\Infrastructure\Models\IdentityUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the RBAC baseline (roles, permissions, and their default grants from
 * AccessMatrix) — idempotent, safe on every deploy. On non-production
 * environments it also seeds a small set of demo users so the auth flow and
 * scoping can be exercised end-to-end. Never seeds demo users in production.
 */
class IdentityAccessSeeder extends Seeder
{
    /** Deterministic demo nursery scope ids so scoping can be tested by URL. */
    public const NURSERY_A = '11111111-1111-1111-1111-111111111111';
    public const NURSERY_B = '22222222-2222-2222-2222-222222222222';

    public function run(): void
    {
        $this->seedPermissions();
        $roles = $this->seedRoles();
        $this->seedGrants($roles);

        if (! app()->environment('production')) {
            $this->seedDemoUsers($roles);
        }
    }

    private function seedPermissions(): void
    {
        foreach (Permission::all() as $name) {
            IdentityPermission::updateOrCreate(
                ['name' => $name],
                ['label' => Permission::label($name)],
            );
        }
    }

    /** @return array<string,IdentityRole> name => role */
    private function seedRoles(): array
    {
        $roles = [];
        foreach (RoleName::all() as $name) {
            $roles[$name] = IdentityRole::updateOrCreate(
                ['name' => $name],
                ['label' => RoleName::LABELS[$name], 'level' => RoleName::LEVELS[$name]],
            );
        }

        return $roles;
    }

    /** @param array<string,IdentityRole> $roles */
    private function seedGrants(array $roles): void
    {
        $permIds = IdentityPermission::pluck('id', 'name');

        foreach ($roles as $name => $role) {
            $ids = collect(AccessMatrix::grantsFor($name))
                ->map(fn ($p) => $permIds[$p] ?? null)
                ->filter()
                ->all();

            // sync = declarative: matches the matrix exactly, additive+removals.
            $role->permissions()->sync($ids);
        }
    }

    /** @param array<string,IdentityRole> $roles */
    private function seedDemoUsers(array $roles): void
    {
        $password = env('IDENTITY_DEMO_PASSWORD', 'Passw0rd!');

        $demo = [
            ['name' => 'Super Admin',   'email' => 'superadmin@plantathome.test', 'role' => RoleName::SUPER_ADMIN,   'nursery' => null],
            ['name' => 'Platform Admin','email' => 'admin@plantathome.test',      'role' => RoleName::ADMIN,         'nursery' => null],
            ['name' => 'Owner A',       'email' => 'owner.a@plantathome.test',    'role' => RoleName::NURSERY_OWNER, 'nursery' => self::NURSERY_A],
            ['name' => 'Staff A',       'email' => 'staff.a@plantathome.test',    'role' => RoleName::NURSERY_STAFF, 'nursery' => self::NURSERY_A],
            ['name' => 'Owner B',       'email' => 'owner.b@plantathome.test',    'role' => RoleName::NURSERY_OWNER, 'nursery' => self::NURSERY_B],
            ['name' => 'Customer',      'email' => 'customer@plantathome.test',   'role' => RoleName::CUSTOMER,      'nursery' => null],
        ];

        foreach ($demo as $d) {
            // uuid is NOT set here: the IdentityUser `creating` hook assigns it
            // once on insert, so re-running the seeder keeps each user's stable
            // uuid (regenerating it would invalidate previously-issued tokens).
            IdentityUser::updateOrCreate(
                ['email' => $d['email']],
                [
                    'name'              => $d['name'],
                    'password'          => Hash::make($password),
                    'role_id'           => $roles[$d['role']]->id,
                    'nursery_id'        => $d['nursery'],
                    'is_active'         => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
