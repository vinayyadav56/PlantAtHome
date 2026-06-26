<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as UserPermission;
use Marvel\Enums\Role as UserRole;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Repair (or create) a super-admin account so it can log into the admin dashboard.
 *
 *   php artisan plantathome:reset-admin --email=owner@example.com [--password=secret] [--name="Owner"]
 *
 * Non-interactive (option-driven) so it can run unattended in CI. Idempotent and
 * safe to re-run. It guarantees the account satisfies the admin login gate:
 *   - finds the user by email (case-insensitive); creates it if missing,
 *   - forces is_active = true + email_verified_at = now (token() requires is_active),
 *   - resets the password (given or generated) so Hash::check passes,
 *   - re-grants super_admin / store_owner / customer permissions + the super_admin
 *     role, which is what hasAccess(allowedRoles, permissions) needs in the admin.
 *
 * When --password is omitted a strong random one is generated and printed ONCE so
 * the operator (e.g. the GitHub Actions run log) can read it and then change it.
 */
class ResetAdminCommand extends Command
{
    protected $signature = 'plantathome:reset-admin
        {--email= : Email of the admin to repair/create (required)}
        {--name= : Display name to set when creating the user}
        {--password= : Password to set; if omitted a strong one is generated and printed}';

    protected $description = 'Repair or create a super-admin account so it can log into the admin dashboard.';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email is required, e.g. --email=owner@example.com');
            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? '');
        $generated = false;
        if ($password === '') {
            // 16-char alphanumeric; printed once below so the operator can use + rotate it.
            $password = Str::random(16);
            $generated = true;
        }

        // Make sure the permissions/role the admin gate needs actually exist (guard 'api'),
        // so givePermissionTo()/assignRole() can never throw "permission does not exist".
        foreach ([UserPermission::SUPER_ADMIN, UserPermission::STORE_OWNER, UserPermission::CUSTOMER] as $perm) {
            SpatiePermission::findOrCreate($perm, 'api');
        }
        SpatieRole::findOrCreate(UserRole::SUPER_ADMIN, 'api');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();
        $created = false;
        if (!$user) {
            $user = new User();
            $user->email = $email;
            $user->name = trim((string) ($this->option('name') ?? '')) ?: 'Admin';
            $created = true;
        }

        $user->password = Hash::make($password);
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $user->givePermissionTo([
            UserPermission::SUPER_ADMIN,
            UserPermission::STORE_OWNER,
            UserPermission::CUSTOMER,
        ]);
        $user->assignRole(UserRole::SUPER_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(($created ? 'Created' : 'Repaired') . " super-admin: {$user->email} (id {$user->id})");
        $this->line('  is_active=true, email_verified, role=super_admin, perms=[super_admin,store_owner,customer]');
        if ($generated) {
            $this->warn('  Generated password (change it after first login): ' . $password);
        } else {
            $this->line('  Password set from --password.');
        }

        return self::SUCCESS;
    }
}
