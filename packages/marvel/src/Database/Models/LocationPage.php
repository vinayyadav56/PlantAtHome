<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationPage extends Model
{
    protected $table = 'location_pages';

    protected $guarded = ['id'];

    protected $casts = [
        'faqs' => 'array',
        'is_active' => 'boolean',
        'is_indexable' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
