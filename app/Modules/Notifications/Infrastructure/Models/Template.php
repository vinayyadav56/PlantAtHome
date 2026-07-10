<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasUuid;

    protected $table = 'notif_templates';

    protected $fillable = ['uuid', 'event_name', 'channel', 'subject', 'body', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
