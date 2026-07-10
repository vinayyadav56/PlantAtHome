<?php

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A configuration option — itself an independently-stocked, independently-priced
 * SKU. A pot is never a product; it's an option.
 *
 * @property int    $id
 * @property string $uuid
 * @property int    $group_id
 * @property string $sku
 * @property string $name
 * @property string $base_price
 * @property string $currency
 * @property string $status
 */
class ConfigOption extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'config_options';

    protected $fillable = [
        'uuid', 'group_id', 'sku', 'name', 'weight_grams', 'base_price',
        'currency', 'status', 'sort', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'base_price'   => 'decimal:2',
        'weight_grams' => 'integer',
        'sort'         => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConfigGroup::class, 'group_id');
    }

    public function compatibility(): HasMany
    {
        return $this->hasMany(OptionCompatibility::class, 'option_id');
    }
}
