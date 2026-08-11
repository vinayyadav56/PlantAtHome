<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fulfilment unit grouping order_items by vendor + mode (internal; never customer-facing). */
class Shipment extends Model
{
    protected $table = 'shipments';

    public $guarded = [];

    protected $casts = [
        'shipping_cost'        => 'float',
        'shipping_revenue'     => 'float',
        'eta_days'             => 'integer',
        'expected_delivery_at' => 'date',
        'cod_amount'           => 'float',
        'shipped_at'           => 'datetime',
        'delivered_at'         => 'datetime',
        'last_status_at'       => 'datetime',
    ];

    /**
     * Legs the courier stack may touch. shipments.delivery_mode = 'self' means
     * the VENDOR fulfils this leg (shops.delivery_mode = 'self' at grouping
     * time) — auto-booking, the undispatched sweep and CourierService::book
     * must all skip it. Closure keeps the OR properly parenthesised.
     */
    public function scopeCourierEligible($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('delivery_mode')->orWhere('delivery_mode', '!=', 'self');
        });
    }

    public function isSelfDelivery(): bool
    {
        return $this->delivery_mode === 'self';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'shipment_id');
    }
}
