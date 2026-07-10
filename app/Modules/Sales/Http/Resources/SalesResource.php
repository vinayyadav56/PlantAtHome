<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Infrastructure\Models\Cart;
use App\Modules\Sales\Infrastructure\Models\Order;
use App\Modules\Sales\Infrastructure\Models\SubOrder;

/** Presenters for the Sales aggregates (uuids only over the wire). */
final class SalesResource
{
    public static function cart(Cart $cart): array
    {
        return [
            'uuid'   => $cart->uuid,
            'status' => $cart->status,
            'items'  => $cart->items->map(fn ($i) => [
                'uuid'         => $i->uuid,
                'variant_uuid' => $i->variant_uuid,
                'nursery_id'   => $i->nursery_id,
                'qty'          => $i->qty,
                'options'      => $i->config_snapshot['options'] ?? [],
                'price'        => $i->price_snapshot['total'] ?? null,
            ])->all(),
            'grand_total_minor' => (int) $cart->items->sum(fn ($i) => (int) ($i->price_snapshot['total']['amount_minor'] ?? 0)),
        ];
    }

    /** Full parent-order view for the customer (includes the vendor split). */
    public static function order(Order $order): array
    {
        return [
            'uuid'       => $order->uuid,
            'status'     => $order->status,
            'totals'     => $order->totals,
            'sub_orders' => $order->subOrders->map(fn (SubOrder $s) => self::subOrder($s))->all(),
        ];
    }

    public static function subOrder(SubOrder $s): array
    {
        return [
            'uuid'       => $s->uuid,
            'nursery_id' => $s->nursery_id,
            'status'     => $s->status,
            'totals'     => $s->totals,
            'items'      => $s->relationLoaded('items') ? $s->items->map(fn ($i) => [
                'uuid'         => $i->uuid,
                'variant_uuid' => $i->variant_uuid,
                'product'      => $i->product_snapshot,
                'qty'          => $i->qty,
                'price'        => $i->price_snapshot['total'] ?? null,
            ])->all() : null,
        ];
    }
}
