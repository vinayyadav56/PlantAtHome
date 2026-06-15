<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A city a vendor (shop) serves, and how (local delivery / courier / both).
 * Powers city-first availability + local-vs-courier fulfillment.
 */
class VendorServiceArea extends Model
{
    protected $table = 'vendor_service_areas';

    public $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'eta_days'  => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
