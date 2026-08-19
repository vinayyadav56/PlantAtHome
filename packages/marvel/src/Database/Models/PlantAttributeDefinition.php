<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An admin-defined plant characteristic (Suitable Spaces, Special Characteristics, …).
 * Admins add definitions + terms at runtime; filters and collections read them, so a new
 * characteristic never needs a code change or a new category.
 *
 * NOT a replacement for: the legacy `attributes` tables (which are the VARIATION picker
 * axis — size), or the `plant_attributes` wide table (canonical for the six botanical
 * facets that already ship). One source of truth per fact.
 */
class PlantAttributeDefinition extends Model
{
    use SoftDeletes;

    protected $table = 'plant_attribute_definitions';

    public $guarded = [];

    protected $casts = [
        'is_facet'  => 'boolean',
        'is_active' => 'boolean',
        'sort'      => 'integer',
    ];

    public const TYPES = ['single', 'multi', 'boolean', 'number', 'text', 'range'];

    public function terms(): HasMany
    {
        return $this->hasMany(PlantAttributeTerm::class, 'definition_id')->orderBy('sort')->orderBy('value');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
