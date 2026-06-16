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
    ];

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
