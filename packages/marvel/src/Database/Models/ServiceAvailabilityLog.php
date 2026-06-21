<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operations Control Center — immutable audit row for an availability change.
 */
class ServiceAvailabilityLog extends Model
{
    protected $table = 'service_availability_logs';
    public $timestamps = false;

    protected $fillable = [
        'entity_type', 'entity_id', 'old_value', 'new_value',
        'changed_by', 'reason', 'ip', 'created_at',
    ];

    protected $casts = [
        'old_value'  => 'array',
        'new_value'  => 'array',
        'created_at' => 'datetime',
    ];
}
