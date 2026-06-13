<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One admin vendor price-sheet upload (audit). Links to the rows it created.
 */
class PriceImportBatch extends Model
{
    protected $table = 'price_import_batches';

    public $guarded = [];

    protected $casts = [
        'errors'         => 'json',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'row_count'      => 'integer',
        'error_count'    => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(VendorProductPrice::class, 'import_batch_id');
    }
}
