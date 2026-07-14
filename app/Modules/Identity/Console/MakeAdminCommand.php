<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;
use App\Modules\Identity\Domain\RoleName;
use App\Modules\Identity\Infrastructure\Models\IdentityRole;
use App\Modules\Identity\Infrastructure\Models\IdentityUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * v2 Identity — mint/repair a platform admin (console-only; there is no public
 * registration on /api/v1 by design). Production ships with NO v2 users (the
 * seeder only creates demo users outside production), so this is the sanctioned
 * way to create the first real admin:
 *
 *   php artisan v2:make-admin ops@plantathome.in --role=super_admin
 *
 * Idempotent: find-or-create by email, re-activates, resets the password
 * (given or generated + printed ONCE). Ensures the RBAC baseline exists first.
 */
final class MakeAdminCommand extends Command
{
    protected $signature = 'v2:make-admin
        {email : Account email}
        {--name= : Display name (defaults to the email local part)}
        {--password= : Password to set; generated and printed once when omitted}
        {--role=super_admin : admin or super_admin}';

    protected $description = 'Create or repair a v2 (/api/v1) platform admin user';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $roleName = (string) $this->option('role');

        if (! in_array($roleName, [RoleName::ADMIN, RoleName::SUPER_ADMIN], true)) {
            $this->error("Role must be 'admin' or 'super_admin', got '{$roleName}'.");

            return self::FAILURE;
        }

        // RBAC baseline (roles/permissions/grants) — idempotent; on production
        // this seeds roles WITHOUT demo users.
        (new IdentityAccessSeeder())->run();

        $role = IdentityRole::where('name', $roleName)->firstOrFail();

        $password = (string) ($this->option('password') ?: Str::password(16));

        $user = IdentityUser::updateOrCreate(
            ['email' => $email],
            [
                'name'              => (string) ($this->option('name') ?: Str::before($email, '@')),
                'password'          => Hash::make($password),
                'role_id'           => $role->id,
                'nursery_id'        => null,
                'is_active'         => true,
                'email_verified_at' => now(),
            ],
        );

        $this->info("v2 {$roleName} ready: {$email} (uuid {$user->uuid})");
        if (! $this->option('password')) {
            $this->warn("Generated password (shown once): {$password}");
        }
        $this->line('Login: POST /api/v1/auth/login {email, password}');

        return self::SUCCESS;
    }
}
