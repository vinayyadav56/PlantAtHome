<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B — a designation is a named permission TEMPLATE (job function). Its
 * `default_permissions` is the set of module-permission strings every employee
 * with this designation inherits; per-user overrides refine it. See
 * PermissionResolver for how the effective set is materialised onto users.
 */
class Designation extends Model
{
    protected $table = 'designations';

    protected $fillable = [
        'name',
        'slug',
        'department',
        'description',
        'default_permissions',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'default_permissions' => 'array',
        'is_active'           => 'boolean',
        'display_order'       => 'integer',
    ];

    protected $appends = ['users_count'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'designation_id');
    }

    public function getUsersCountAttribute(): int
    {
        return $this->users()->count();
    }
}
