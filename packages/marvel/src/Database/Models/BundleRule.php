<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F5 — a rule that composes a bundle offer from an eligible pool of plants.
 * Eligibility references existing products (never copied); the matching set +
 * price are evaluated live by BundleRuleEvaluator.
 */
class BundleRule extends Model
{
    use SoftDeletes;

    protected $table = 'bundle_rules';

    public $guarded = [];

    protected $casts = [
        'eligibility'    => 'array',
        'quantity_rule'  => 'array',
        'bundle_price'   => 'float',
        'discount_value' => 'float',
        'is_active'      => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
