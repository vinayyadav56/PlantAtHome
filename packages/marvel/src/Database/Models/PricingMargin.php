<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the PlantAtHome selling-margin matrix. city NULL = all cities;
 * type_id NULL = all verticals; both NULL = the global default. See MarginResolver
 * for the precedence used at price time.
 */
class PricingMargin extends Model
{
    protected $table = 'pricing_margins';

    public $guarded = [];

    protected $casts = [
        'margin_percent' => 'float',
        'margin_flat'    => 'float',
        'is_active'      => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'type_id');
    }
}
