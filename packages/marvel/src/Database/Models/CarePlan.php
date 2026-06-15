<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarePlan extends Model
{
    use SoftDeletes;

    protected $table = 'care_plans';
    protected $fillable = [
        'user_id', 'order_id', 'product_id', 'plant_name', 'scientific_name',
        'language', 'image_url', 'plan_json', 'status', 'source', 'last_checkup_at',
        'total_tokens', 'cost_usd', 'cost_inr',
    ];
    protected $casts = [
        'plan_json' => 'array',
        'last_checkup_at' => 'datetime',
        'total_tokens' => 'int',
        'cost_usd' => 'float',
        'cost_inr' => 'float',
    ];

    public function reminders(): HasMany
    {
        return $this->hasMany(CareReminder::class, 'care_plan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
