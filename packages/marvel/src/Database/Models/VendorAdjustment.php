<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A manual credit/debit to a vendor's payable — approved, journaled, never a balance edit (spec §25). */
class VendorAdjustment extends Model
{
    protected $table = 'vendor_adjustments';
    public $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'effective_date' => 'date:Y-m-d', 'requires_approval' => 'boolean', 'approved_at' => 'datetime'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
