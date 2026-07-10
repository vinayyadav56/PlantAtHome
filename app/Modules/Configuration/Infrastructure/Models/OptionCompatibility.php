<?php

namespace App\Modules\Configuration\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Encodes which variant/size a configuration option fits ("large plant → large
 * pot"). Matches by specific variant_id OR by size_code.
 *
 * @property int         $id
 * @property int         $option_id
 * @property int|null    $variant_id
 * @property string|null $size_code
 * @property bool        $is_compatible
 */
class OptionCompatibility extends Model
{
    protected $table = 'config_option_compatibility';

    protected $fillable = ['option_id', 'variant_id', 'size_code', 'is_compatible'];

    protected $casts = ['is_compatible' => 'boolean'];
}
