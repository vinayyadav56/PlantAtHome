<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vendor's shipping cost + SLA for a zone/mode (feeds the assignment score + checkout). */
class VendorShippingRate extends Model
{
    protected $table = 'vendor_shipping_rates';

    public $guarded = [];

    protected $casts = [
        'base_cost'   => 'float',
        'per_kg_cost' => 'float',
        'sla_days'    => 'integer',
        'is_active'   => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
