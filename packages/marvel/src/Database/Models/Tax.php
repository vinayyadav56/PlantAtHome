<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tax extends Model
{
    protected $table = 'tax_classes';

    public $guarded = [];

    protected $casts = [
        'rate' => 'float',
        'is_active' => 'boolean',
        'is_global' => 'boolean',
        'on_shipping' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    /** Scheduled rate changes, newest start first. */
    public function versions(): HasMany
    {
        return $this->hasMany(TaxRateVersion::class, 'tax_class_id')->orderByDesc('effective_from');
    }

    protected static function boot()
    {
        parent::boot();
        // Order by updated_at desc
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('updated_at', 'desc');
        });
    }
}
