<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shop;
use Marvel\Services\MatchingService;

/**
 * Admin order → vendor + delivery-partner assignment (P3). `match` returns the
 * nearby vendors/DPs with distance + ETA (and auto-persists a suggestion);
 * `assign` records the admin's approved/reassigned choice (incl. courier modes).
 */
class OrderAssignmentController extends CoreController
{
    /** Admin: matching panel for an order (nearby vendors + DPs, ranked). */
    public function match($id, Request $request)
    {
        $order = Order::with('products:id,name')->findOrFail($id);
        $service = new MatchingService();
        $match = $service->persistSuggestion($order);

        return array_merge($match, [
            'order_id'    => $order->id,
            'current'     => [
                'vendor_shop_id'      => $order->vendor_shop_id,
                'delivery_partner_id' => $order->delivery_partner_id,
                'delivery_mode'       => $order->delivery_mode,
                'assignment_status'   => $order->assignment_status,
            ],
        ]);
    }

    /**
     * Admin: approve / reassign. Body: vendor_shop_id?, delivery_partner_id?,
     * delivery_mode? (vendor_dp|separate_dp|courier_admin|courier_dp).
     */
    public function assign($id, Request $request)
    {
        $order = Order::findOrFail($id);

        $mode = $request->input('delivery_mode');
        $isCourier = in_array($mode, ['courier_admin', 'courier_dp'], true);

        $vendorShopId = $request->input('vendor_shop_id', $order->vendor_shop_id);
        if ($vendorShopId && !Shop::whereKey($vendorShopId)->exists()) {
            return response()->json(['message' => 'Vendor shop not found.'], 422);
        }

        // Courier-admin needs no DP; otherwise a DP may be assigned.
        $dpId = $mode === 'courier_admin' ? null : $request->input('delivery_partner_id', $order->delivery_partner_id);
        if ($dpId && !DeliveryPartner::whereKey($dpId)->exists()) {
            return response()->json(['message' => 'Delivery partner not found.'], 422);
        }

        $order->forceFill([
            'vendor_shop_id'      => $vendorShopId,
            'delivery_partner_id' => $dpId,
            'delivery_mode'       => $mode ?: $order->delivery_mode,
            'assignment_status'   => 'approved',
        ])->save();

        return [
            'message' => 'Assignment saved.',
            'order'   => $order->only([
                'id', 'vendor_shop_id', 'delivery_partner_id', 'delivery_mode', 'assignment_status',
            ]),
        ];
    }

    /**
     * Public (by tracking number): the assigned courier's live position for an
     * order, but ONLY while it's out for delivery (privacy). Customer "where's my
     * plant" tracking.
     */
    public function courierLocation($tracking)
    {
        $order = Order::where('tracking_number', $tracking)->first();
        if (!$order || !$order->delivery_partner_id) {
            return ['available' => false, 'status' => $order->order_status ?? null];
        }
        if ($order->order_status !== 'order-out-for-delivery') {
            return ['available' => false, 'status' => $order->order_status];
        }
        $dp = DeliveryPartner::find($order->delivery_partner_id);
        if (!$dp || $dp->current_lat === null || $dp->current_lng === null) {
            return ['available' => false, 'status' => $order->order_status];
        }
        $updated = $dp->location_updated_at;
        $vendor = $order->vendor_shop_id ? Shop::find($order->vendor_shop_id) : null;
        $drop = is_array($order->shipping_address) ? ($order->shipping_address['location'] ?? null) : null;

        return [
            'available' => true,
            'status'    => $order->order_status,
            'courier'   => [
                'name'       => $dp->full_name,
                'mobile'     => $dp->mobile,
                'lat'        => $dp->current_lat,
                'lng'        => $dp->current_lng,
                'updated_at' => $updated,
                'stale'      => !$updated || \Carbon\Carbon::parse($updated)->lt(now()->subMinutes(2)),
            ],
            'pickup' => $vendor ? ['name' => $vendor->name, 'location' => is_array($vendor->settings) ? ($vendor->settings['location'] ?? null) : null] : null,
            'drop'   => $drop,
        ];
    }

    /**
     * Admin: true-profit report. Profit = selling (total) − vendor cost −
     * DP commission − delivery fee, over parent orders. Returns aggregate totals
     * + a recent-orders breakdown (paginated).
     */
    public function profit(Request $request)
    {
        $limit = (int) ($request->limit ?? 20);

        $base = Order::query()->whereNull('parent_id');
        if ($request->filled('from')) {
            $base->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $base->whereDate('created_at', '<=', $request->input('to'));
        }

        $totals = (clone $base)->selectRaw(
            'COUNT(*) as orders,
             COALESCE(SUM(total),0) as revenue,
             COALESCE(SUM(vendor_cost_total),0) as vendor_cost,
             COALESCE(SUM(dp_commission_amount),0) as dp_commission,
             COALESCE(SUM(delivery_fee),0) as delivery_fee'
        )->first();

        $revenue   = (float) $totals->revenue;
        $cost      = (float) $totals->vendor_cost;
        $dp        = (float) $totals->dp_commission;
        $delivery  = (float) $totals->delivery_fee;
        $profit    = round($revenue - $cost - $dp, 2);

        $orders = (clone $base)->orderByDesc('id')
            ->paginate($limit, ['id', 'tracking_number', 'total', 'vendor_cost_total', 'dp_commission_amount', 'delivery_fee', 'order_status', 'created_at']);

        $orders->getCollection()->transform(function ($o) {
            // Reveal the internal cost/commission columns for the profit table.
            $o->makeVisible(['vendor_cost_total', 'dp_commission_amount']);
            $o->profit = round((float) $o->total - (float) $o->vendor_cost_total - (float) $o->dp_commission_amount, 2);
            return $o;
        });

        return [
            'summary' => [
                'orders'        => (int) $totals->orders,
                'revenue'       => round($revenue, 2),
                'vendor_cost'   => round($cost, 2),
                'dp_commission' => round($dp, 2),
                'delivery_fee'  => round($delivery, 2),
                'profit'        => $profit,
            ],
            'orders' => $orders,
        ];
    }
}

