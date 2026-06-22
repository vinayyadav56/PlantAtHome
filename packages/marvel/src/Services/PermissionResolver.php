<?php

namespace Marvel\Services;

use Marvel\Database\Models\User;
use Marvel\Enums\ModulePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase B — resolves and MATERIALISES an employee's effective module permissions.
 *
 * Model: a Designation carries a default permission set; a user may override it
 * with {grant,revoke}. The effective set is:
 *
 *     source = 'designation'  ⇒  effective = designation.default_permissions
 *     source = 'custom'       ⇒  effective = (default ∪ grant) − revoke
 *
 * We then write `effective + ['staff','customer']` as the user's DIRECT Spatie
 * permissions (syncPermissions). Because enforcement everywhere reads
 * getAllPermissions()/the `permission:` middleware, nothing downstream needs to
 * know about designations — and `revoke` works (Spatie roles can't subtract).
 * The coarse `['staff','customer']` are always force-included so the admin
 * login-gate is never severed.
 */
class PermissionResolver
{
    /** The coarse perms every internal employee must always keep. */
    public const COARSE = ['staff', 'customer'];

    /** The effective, validated module-permission strings for a user. */
    public function resolveEffective(User $user): array
    {
        $designation = $user->designation; // may be null
        $defaults = $designation ? (array) ($designation->default_permissions ?? []) : [];

        $source = $user->permission_source ?? 'designation';
        if ($source === 'custom') {
            $overrides = (array) ($user->permission_overrides ?? []);
            $grant = (array) ($overrides['grant'] ?? []);
            $revoke = (array) ($overrides['revoke'] ?? []);
            $effective = array_diff(
                array_unique(array_merge($defaults, $grant)),
                $revoke
            );
        } else {
            $effective = $defaults;
        }

        // Defence in depth: only ever materialise known module permissions.
        return array_values(array_filter(
            $effective,
            fn ($p) => is_string($p) && ModulePermission::isValid($p)
        ));
    }

    /**
     * Write the effective set (+ coarse login-gate perms) as the user's DIRECT
     * permissions and flush the Spatie cache. Idempotent.
     */
    public function materialize(User $user, bool $flushCache = true): User
    {
        $full = array_values(array_unique(array_merge(
            $this->resolveEffective($user),
            self::COARSE
        )));

        $user->syncPermissions($full);
        // Callers materialising many users in a loop pass false and flush ONCE
        // afterwards (avoids an O(n) cache flush).
        if ($flushCache) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user;
    }
}
