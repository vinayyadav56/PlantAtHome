<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalized projection of "master product X is available in city Y" (local
 * and/or courier), maintained by VendorInventoryService for fast city-first reads.
 */
class ProductCityAvailability extends Model
{
    protected $table = 'product_city_availability';

    public $guarded = [];

    // Only updated_at is tracked (the row is upserted on each recompute).
    public $timestamps = false;

    protected $casts = [
        'has_local'    => 'boolean',
        'has_courier'  => 'boolean',
        'min_price'    => 'float',
        'vendor_count' => 'integer',
        'updated_at'   => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
