<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class GardenPackageTemplate extends Model
{
    protected $table = 'garden_package_templates';
    protected $fillable = [
        'name', 'tagline', 'description', 'items',
        'suggested_visits', 'suggested_price', 'duration_days', 'is_active', 'sort',
    ];
    protected $casts = [
        'items' => 'array',
        'is_active' => 'bool',
        'suggested_visits' => 'int',
        'suggested_price' => 'float',
        'duration_days' => 'int',
        'sort' => 'int',
    ];
}
