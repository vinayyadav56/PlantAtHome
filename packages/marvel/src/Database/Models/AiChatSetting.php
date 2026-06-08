<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatSetting extends Model
{
    protected $table = 'ai_chat_settings';
    protected $fillable = [
        'enabled', 'service_url', 'service_api_key',
        'openai_model', 'monthly_budget_inr', 'max_prompts', 'daily_user_cap',
    ];
    protected $casts = [
        'enabled' => 'bool',
        'monthly_budget_inr' => 'float',
        'max_prompts' => 'int',
        'daily_user_cap' => 'int',
    ];
}
