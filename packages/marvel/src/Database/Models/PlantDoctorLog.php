<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class PlantDoctorLog extends Model
{
    protected $table = 'plant_doctor_logs';
    protected $fillable = [
        'user_id', 'session_id', 'plant_name', 'condition_summary',
        'top_severity', 'overall_health_score', 'image_url',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'cost_usd', 'cost_inr', 'created_at',
        'is_plant', 'identified_species', 'id_confidence',
    ];
    protected $casts = [
        'user_id' => 'int',
        'overall_health_score' => 'float',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'cost_usd' => 'float',
        'cost_inr' => 'float',
        'created_at' => 'datetime',
        'is_plant' => 'bool',
        'id_confidence' => 'float',
    ];
    public $timestamps = false;
}
