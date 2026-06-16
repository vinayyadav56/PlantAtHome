<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a single customer order, with its own per-item vendor assignment +
 * shipment grouping. The customer-facing model (one order, one timeline); the legacy
 * per-vertical child orders are the compat shadow during the P4 rollout.
 */
class OrderItem extends Model
{
    protected $table = 'order_items';

    public $guarded = [];

    protected $casts = [
        'unit_price'           => 'float',
        'subtotal'             => 'float',
        'order_quantity'       => 'integer',
        'reserved_qty'         => 'integer',
        'eta_days'             => 'integer',
        'vendor_price_snapshot' => 'float',
        'vendor_cost_snapshot' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function assignedShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'assigned_shop_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
