<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLogSetting extends Model
{
    protected $table = 'request_log_settings';
    protected $fillable = ['enabled', 'retention_days', 'log_get'];
    protected $casts = ['enabled' => 'bool', 'log_get' => 'bool', 'retention_days' => 'int'];
}
