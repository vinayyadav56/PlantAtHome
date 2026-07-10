<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property string $label
 */
class IdentityPermission extends Model
{
    protected $table = 'identity_permissions';

    protected $fillable = ['name', 'label'];
}
