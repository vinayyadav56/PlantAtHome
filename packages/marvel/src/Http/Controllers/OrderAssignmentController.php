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
}
