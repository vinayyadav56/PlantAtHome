<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One continuous period of activity by one visitor (30-min inactivity rule —
 * config/tracking.php). Device/browser/geo are NOT repeated here; join
 * `visitors` on visitor_id when a report needs them.
 */
class AnalyticsSession extends Model
{
    protected $table = 'analytics_sessions';

    public $timestamps = false;

    public $guarded = [];

    protected $casts = [
        'page_views'   => 'integer',
        'engaged'      => 'boolean',
        'started_at'   => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
