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

    /**
     * The event taxonomy. Anything not listed is dropped at ingest — the
     * storefront is the only intended sender, but the endpoint is public.
     */
    public const TYPES = [
        'page_view',
        'product_view',
        'category_view',
        'search',
        'city_changed',
        'location_detected',
        'location_denied',
        'add_to_cart',
        'remove_from_cart',
        'view_cart',
        'begin_checkout',
        'add_address',
        'select_payment',
        'serviceable_order',
        'non_serviceable_order',
        'payment_initiated',
        'payment_success',
        'payment_failed',
        'checkout_failed',
        'order_created',
        'order_success',
    ];

    /** Names older storefront bundles still send → the canonical type. */
    public const ALIASES = [
        'checkout_start'   => 'begin_checkout',
        'payment_start'    => 'payment_initiated',
        'payment_complete' => 'order_success',
        'order_placed'     => 'order_created',
    ];

    /**
     * The only meta keys stored, values scalar and capped. The column is MySQL
     * `json`, so the encoded document must always be valid — never truncated.
     */
    public const META_KEYS = [
        'product_id', 'variation_id', 'category_id', 'category', 'quantity', 'price',
        'gateway', 'reason', 'source', 'term', 'results', 'tracking_number',
    ];

    /** High-signal event types surfaced in the live activity feed. */
    public const KEY_EVENTS = [
        'add_to_cart',
        'begin_checkout',
        'payment_initiated',
        'payment_success',
        'order_created',
    ];

    /** Purchase funnel, in order. Distinct HUMAN visitors per step. */
    public const FUNNEL_STEPS = [
        'product_view', 'add_to_cart', 'view_cart', 'begin_checkout', 'add_address',
        'payment_initiated', 'payment_success', 'order_created',
    ];

    public static function canonicalType(?string $type): ?string
    {
        $type = trim((string) $type);
        $type = self::ALIASES[$type] ?? $type;
        return in_array($type, self::TYPES, true) ? $type : null;
    }
}
