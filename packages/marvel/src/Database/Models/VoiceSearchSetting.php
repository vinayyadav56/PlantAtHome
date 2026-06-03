<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceSearchSetting extends Model
{
    protected $table = 'voice_search_settings';
    protected $fillable = ['enabled', 'monthly_budget_inr', 'openai_model'];
    protected $casts = ['enabled' => 'bool'];
}
