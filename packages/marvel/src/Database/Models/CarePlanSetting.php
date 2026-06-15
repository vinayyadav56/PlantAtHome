<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class CarePlanSetting extends Model
{
    protected $table = 'care_plan_settings';
    protected $fillable = [
        'enabled', 'auto_on_delivery', 'service_url', 'service_api_key',
        'model', 'monthly_budget_inr',
    ];
    protected $casts = [
        'enabled' => 'bool',
        'auto_on_delivery' => 'bool',
        'monthly_budget_inr' => 'float',
    ];
}
