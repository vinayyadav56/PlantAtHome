<?php

namespace App\Modules\Configuration\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attaches a configuration group to a product, with per-product overrides.
 *
 * @property int      $id
 * @property int      $product_id
 * @property int      $group_id
 * @property bool|null $is_required_override
 * @property int|null $default_option_id
 */
class ProductAssignment extends Model
{
    protected $table = 'config_product_assignments';

    protected $fillable = [
        'product_id', 'group_id', 'is_required_override', 'default_option_id', 'sort',
    ];

    protected $casts = [
        'is_required_override' => 'boolean',
        'sort'                 => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConfigGroup::class, 'group_id');
    }

    public function defaultOption(): BelongsTo
    {
        return $this->belongsTo(ConfigOption::class, 'default_option_id');
    }
}
