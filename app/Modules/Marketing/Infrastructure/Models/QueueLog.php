<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Job-lifecycle audit: dispatched / processing / completed / failed / retrying. */
class QueueLog extends Model
{
    use HasUuid;

    protected $table = 'marketing_queue_logs';

    protected $fillable = [
        'uuid', 'run_id', 'campaign_id', 'batch_id', 'job_class', 'queue',
        'event', 'attempt', 'message', 'context', 'occurred_at',
    ];

    protected $casts = [
        'attempt'     => 'integer',
        'context'     => 'array',
        'occurred_at' => 'datetime',
    ];
}
