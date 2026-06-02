<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Botanical detail extension for plant products.
 * One-to-one with products; all columns are nullable.
 */
class PlantAttribute extends Model
{
    protected $table = 'plant_attributes';

    protected $guarded = [];

    protected $casts = [
        'air_purifying' => 'boolean',
        'pet_friendly'  => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
