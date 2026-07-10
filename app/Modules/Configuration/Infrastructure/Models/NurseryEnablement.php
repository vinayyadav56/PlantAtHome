<?php

namespace App\Modules\Configuration\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which configuration options a specific nursery offers. Default is enabled;
 * an is_enabled=false row opts a nursery OUT of a particular option.
 *
 * @property int    $id
 * @property string $nursery_id
 * @property int    $option_id
 * @property bool   $is_enabled
 */
class NurseryEnablement extends Model
{
    protected $table = 'config_nursery_enablement';

    protected $fillable = ['nursery_id', 'option_id', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];
}
