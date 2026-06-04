<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class PlantDoctorSetting extends Model
{
    protected $table = 'plant_doctor_settings';
    protected $fillable = [
        'enabled', 'service_url', 'service_api_key',
        'openai_model', 'monthly_budget_inr', 'plant_id_enabled',
    ];
    protected $casts = [
        'enabled' => 'bool',
        'plant_id_enabled' => 'bool',
        'monthly_budget_inr' => 'float',
    ];
}
