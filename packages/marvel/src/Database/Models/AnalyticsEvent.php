<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    protected $table = 'analytics_events';

    /** Append-only: the row carries its own created_at, no updated_at. */
    public $timestamps = false;

    public $guarded = [];

    protected $casts = [
        'meta'       => 'array',
        'value'      => 'float',
        'created_at' => 'datetime',
    ];

    /** High-signal event types surfaced in the live activity feed. */
    public const KEY_EVENTS = [
        'add_to_cart',
        'checkout_start',
        'payment_start',
        'payment_complete',
        'order_placed',
    ];
}
