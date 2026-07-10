<?php

namespace App\Modules\Analytics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class Counter extends Model
{
    protected $table = 'analytics_counters';

    protected $fillable = ['metric', 'dimension', 'value_sum', 'value_count'];

    protected $casts = ['value_sum' => 'decimal:2', 'value_count' => 'integer'];
}
