<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class PlantDoctorConsultation extends Model
{
    protected $table = 'plant_doctor_consultations';
    protected $fillable = [
        'user_id', 'plant_name', 'thumb', 'diagnosis',
        'health_score', 'worst_severity',
    ];
    protected $casts = [
        'user_id' => 'int',
        'diagnosis' => 'array',
        'health_score' => 'int',
    ];
}
