<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved discovery rule ("Air Purifying Plants") that resolves through the normal product
 * listing engine. `rules` is the storefront's own filter-param map, so a collection can
 * never drift from what the listing page would show for the same filters.
 */
class PlantCollection extends Model
{
    use SoftDeletes;

    protected $table = 'plant_collections';

    public $guarded = [];

    protected $casts = [
        'image'     => 'json',
        'rules'     => 'json',
        'is_active' => 'boolean',
        'sort'      => 'integer',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
