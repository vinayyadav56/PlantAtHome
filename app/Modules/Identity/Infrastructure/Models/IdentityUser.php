<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\RoleName;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * v2 identity user — its own table, deliberately separate from the legacy
 * marvel `users` table. Carries exactly one role and an optional nursery scope.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $name
 * @property string      $email
 * @property string      $password
 * @property int         $role_id
 * @property string|null $nursery_id
 * @property bool        $is_active
 */
class IdentityUser extends Authenticatable
{
    protected $table = 'identity_users';

    protected $fillable = [
        'uuid', 'name', 'email', 'password', 'role_id', 'nursery_id',
        'is_active', 'email_verified_at', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active'         => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
    ];

    /** Always available for authorization decisions. */
    protected $with = ['role'];

    protected static function booted(): void
    {
        static::creating(function (IdentityUser $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(IdentityRole::class, 'role_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'user_id');
    }

    /* ── authorization helpers ─────────────────────────────────────────────── */

    public function roleName(): string
    {
        return $this->role?->name ?? '';
    }

    public function hasRole(string $name): bool
    {
        return $this->roleName() === $name;
    }

    /** @param string[] $names */
    public function hasAnyRole(array $names): bool
    {
        return in_array($this->roleName(), $names, true);
    }

    public function isPlatformAdmin(): bool
    {
        return RoleName::isPlatformAdmin($this->roleName());
    }

    public function isNurseryRole(): bool
    {
        return RoleName::isNurseryRole($this->roleName());
    }

    /** True if the user's role grants the given permission (super_admin ⇒ all). */
    public function hasPermission(string $permission): bool
    {
        if ($this->hasRole(RoleName::SUPER_ADMIN)) {
            return true;
        }

        return $this->permissionNames()->contains($permission);
    }

    /** @return \Illuminate\Support\Collection<int,string> */
    public function permissionNames()
    {
        return $this->role
            ? $this->role->permissions->pluck('name')
            : collect();
    }
}
