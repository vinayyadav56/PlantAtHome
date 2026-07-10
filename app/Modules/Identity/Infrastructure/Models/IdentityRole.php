<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int    $id
 * @property string $name
 * @property string $label
 * @property int    $level
 */
class IdentityRole extends Model
{
    protected $table = 'identity_roles';

    protected $fillable = ['name', 'label', 'level'];

    protected $casts = ['level' => 'integer'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            IdentityPermission::class,
            'identity_permission_role',
            'role_id',
            'permission_id',
        );
    }
}
