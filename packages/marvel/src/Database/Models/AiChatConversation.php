<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatConversation extends Model
{
    protected $table = 'ai_chat_conversations';
    protected $fillable = [
        'conversation_id', 'user_id', 'plant_id', 'plant_name', 'language',
        'prompt_count', 'transcript', 'prompt_tokens', 'completion_tokens',
        'total_tokens', 'cost_usd', 'cost_inr', 'started_at', 'ended_at', 'created_at',
    ];
    protected $casts = [
        'user_id' => 'int',
        'plant_id' => 'int',
        'prompt_count' => 'int',
        'transcript' => 'array',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'cost_usd' => 'float',
        'cost_inr' => 'float',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
    ];
    public $timestamps = false;

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
