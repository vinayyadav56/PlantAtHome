<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use HasUuid;

    protected $table = 'notif_log';

    protected $fillable = ['uuid', 'event_id', 'event_name', 'channel', 'recipient', 'subject', 'body', 'status', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
