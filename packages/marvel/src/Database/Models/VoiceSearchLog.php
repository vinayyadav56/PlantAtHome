<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceSearchLog extends Model
{
    protected $table = 'voice_search_logs';
    protected $fillable = [
        'session_id', 'transcript', 'search_text', 'category',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'cost_usd', 'cost_inr', 'created_at'
    ];
    protected $casts = [
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'cost_usd' => 'float',
        'cost_inr' => 'float',
        'created_at' => 'datetime',
    ];
    public $timestamps = false;
}
