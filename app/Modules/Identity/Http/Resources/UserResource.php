<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\IdentityUser;

/**
 * Public shape of an identity user. Never exposes the password or the internal
 * auto-increment id — UUIDs only over the wire (Section 11).
 */
final class UserResource
{
    public static function make(IdentityUser $user): array
    {
        return [
            'uuid'        => $user->uuid,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->roleName(),
            'nursery_id'  => $user->nursery_id,
            'permissions' => $user->permissionNames()->values()->all(),
            'is_active'   => (bool) $user->is_active,
        ];
    }
}
