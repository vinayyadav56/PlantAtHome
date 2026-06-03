<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class GardenLead extends Model
{
    protected $table = 'garden_leads';
    protected $fillable = [
        'name', 'phone', 'email', 'city', 'garden_type',
        'space_size', 'budget_range', 'message', 'status',
    ];
}
