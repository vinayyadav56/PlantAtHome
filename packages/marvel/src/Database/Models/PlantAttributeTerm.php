<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One selectable value of a definition ("Bedroom", "Air Purifying"). Reusable across plants. */
class PlantAttributeTerm extends Model
{
    protected $table = 'plant_attribute_terms';

    public $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort'      => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PlantAttributeDefinition::class, 'definition_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'plant_attribute_product', 'term_id', 'product_id');
    }
}
