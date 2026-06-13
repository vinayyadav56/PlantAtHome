<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor's (shop's) cost for a product / size, for a time window. cost_price is
 * NEVER exposed to customers — it drives PricingService (margin over nearest cost)
 * and the per-order profit snapshot. is_available=false (cost 0) ⇒ "available in 6h".
 */
class VendorProductPrice extends Model
{
    use SoftDeletes;

    protected $table = 'vendor_product_prices';

    public $guarded = [];

    protected $casts = [
        'cost_price'    => 'float',
        'is_available'  => 'boolean',
        'effective_from' => 'date',
        'effective_to'  => 'date',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PriceImportBatch::class, 'import_batch_id');
    }
}
