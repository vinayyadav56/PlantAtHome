<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per real order transition — the admin Activity Timeline's data source.
 * Always write through record(): it resolves the actor and swallows every failure,
 * because an audit row must never break checkout, a webhook, or a console command
 * (including test runs against stub schemas that don't create this table).
 */
class OrderEvent extends Model
{
    protected $table = 'order_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function record(int|string $orderId, string $type, array $meta = [], ?string $label = null): void
    {
        try {
            $actor = auth()->user();
            static::create([
                'order_id'   => (int) $orderId,
                'type'       => $type,
                'label'      => $label,
                'actor_type' => $actor ? 'user' : null,
                'actor_id'   => $actor?->id,
                'meta'       => $meta ?: null,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Deliberately silent — see class docblock.
        }
    }
}
