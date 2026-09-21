<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled rate for a tax class: "this class is R% from D".
 *
 * The engine picks the version whose effective_from is the latest one on or
 * before the order's date, so an announced change lands on the day by itself.
 */
class TaxRateVersion extends Model
{
    protected $table = 'tax_rate_versions';

    public $guarded = [];

    protected $casts = [
        'rate'           => 'float',
        'effective_from' => 'date:Y-m-d',
        'effective_to'   => 'date:Y-m-d',
    ];

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_class_id');
    }
}
