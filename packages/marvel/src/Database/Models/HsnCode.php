<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HSN/SAC code the catalogue may use. Products still store the code as a
 * string (that is what gets snapshotted onto an order line and printed on the
 * invoice); this is the list they are validated against.
 */
class HsnCode extends Model
{
    protected $table = 'hsn_codes';

    public $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** The rate this code usually attracts — offered as a default, never forced. */
    public function defaultTaxRate(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'default_tax_rate_id');
    }
}
