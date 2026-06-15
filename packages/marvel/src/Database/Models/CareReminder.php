<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareReminder extends Model
{
    protected $table = 'care_reminders';
    protected $fillable = [
        'care_plan_id', 'user_id', 'type', 'title', 'note',
        'interval_days', 'next_due_at', 'last_done_at', 'active',
    ];
    protected $casts = [
        'active' => 'bool',
        'interval_days' => 'int',
        'next_due_at' => 'datetime',
        'last_done_at' => 'datetime',
    ];

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class, 'care_plan_id');
    }
}
