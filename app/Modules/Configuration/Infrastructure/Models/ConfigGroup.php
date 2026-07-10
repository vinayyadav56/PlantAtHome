<?php

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int    $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string $select_type
 * @property bool   $is_required
 */
class ConfigGroup extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'config_groups';

    protected $fillable = [
        'uuid', 'code', 'name', 'select_type', 'is_required', 'sort',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort'        => 'integer',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(ConfigOption::class, 'group_id')->orderBy('sort');
    }
}
