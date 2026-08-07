<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    protected $table = 'request_logs';
    public $timestamps = false;
    protected $fillable = [
        'request_id', 'method', 'path', 'module', 'status', 'user_id', 'ip',
        'ua', 'device', 'headers',
        'payload', 'response', 'error', 'duration_ms', 'created_at',
    ];
    protected $casts = ['created_at' => 'datetime'];
}
