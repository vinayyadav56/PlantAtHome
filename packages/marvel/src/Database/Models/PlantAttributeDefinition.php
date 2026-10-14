<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An admin-defined product characteristic: plants' Suitable Spaces, a tool's blade length,
 * a sprayer's tank capacity (one table for every vertical; `type_id` + category scopes say
 * where it applies). Historic name: it started with plants.
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
        'is_facet'        => 'boolean',
        'is_active'       => 'boolean',
        'is_variant_axis' => 'boolean',
        'sort'            => 'integer',
        'type_id'         => 'integer',
        'min_value'       => 'float',
        'max_value'       => 'float',
        'step'            => 'float',
        'max_length'      => 'integer',
    ];

    public const TYPES = ['single', 'multi', 'boolean', 'number', 'text', 'range'];

    /** Types whose values are chosen from terms (the rest carry a typed value). */
    public const TERM_TYPES = ['single', 'multi'];

    public function terms(): HasMany
    {
        return $this->hasMany(PlantAttributeTerm::class, 'definition_id')->orderBy('sort')->orderBy('value');
    }

    /**
     * The categories this definition applies to (and everything beneath them). None = the
     * whole vertical (`type_id`), or every vertical when that is empty too.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'plant_attribute_scopes', 'definition_id', 'category_id')
            ->withPivot(['is_required', 'sort'])->withTimestamps();
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
