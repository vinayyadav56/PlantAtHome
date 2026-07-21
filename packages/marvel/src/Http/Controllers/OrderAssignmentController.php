<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Enums\Permission;
use Marvel\Services\FulfillmentService;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\MatchingService;
use Marvel\Services\OrderItemService;

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
     * Admin: per-line fulfillment plan for an order — for each product the best vendor
     * (local-in-city first, else courier), the fulfillment mode, and the ETA. Read-only.
     */
    public function fulfillmentPlan($id)
    {
        $order = Order::with('products')->findOrFail($id);
        return (new FulfillmentService())->planForOrder($order);
    }

    /**
     * Admin (P3): per-LINE scored vendor candidates for an order — inventory > pincode >
     * SLA > rating > priority > shipping cost. The admin picks/overrides per item; the
     * top candidate is flagged `recommended`. Read-only (assignment persists in P4).
     */
    public function itemAssignmentPlan($id)
    {
        $order = Order::with('products')->findOrFail($id);
        $addr = is_array($order->shipping_address) ? $order->shipping_address : (array) $order->shipping_address;
        $city = $addr['city'] ?? null;
        $pincode = $addr['zip'] ?? ($addr['pincode'] ?? null);

        $engine = new ItemAssignmentService();
        $lines = [];
        foreach ($order->products as $product) {
            $qty = (int) ($product->pivot->order_quantity ?? 1);
            $voId = $product->pivot->variation_option_id ? (int) $product->pivot->variation_option_id : null;
            $candidates = $engine->candidatesFor((int) $product->id, $voId, $qty, $city, $pincode);
            $lines[] = [
                'product_id'          => (int) $product->id,
                'name'                => $product->name,
                'variation_option_id' => $voId,
                'quantity'            => $qty,
                'candidates'          => $candidates,
                'unfilled'            => empty($candidates),
            ];
        }
        return [
            'order_id'   => $order->id,
            'order_city' => $city,
            'pincode'    => $pincode,
            'lines'      => $lines,
        ];
    }

    /** Admin (P4): the order's persisted per-item assignment + shipment groupings. */
    public function items($id)
    {
        $order = Order::with([
            'items.product:id,name,slug,sku',
            'items.assignedShop:id,name',
            'shipments.shop:id,name',
        ])->findOrFail($id);
        return [
            'order_id'  => $order->id,
            'items'     => $order->items,
            'shipments' => $order->shipments,
        ];
    }

    /** Admin (P4): auto-assign every line to its recommended vendor + group into shipments. */
    public function autoAssignItems($id)
    {
        $order = Order::findOrFail($id);
        return (new OrderItemService())->assignAndGroup($order);
    }

    /** Admin (P4): override specific lines. Body: { assignments: [{order_item_id, shop_id}] }. */
    public function assignItems($id, Request $request)
    {
        $request->validate([
            'assignments'                 => 'required|array|min:1',
            'assignments.*.order_item_id' => 'required|integer',
            'assignments.*.shop_id'       => 'required|integer',
        ]);
        $order = Order::findOrFail($id);
        $result = (new OrderItemService())->assignItems($order, $request->input('assignments'));
        return response()->json($result);
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
                // SECURITY: this endpoint is public + the tracking_number is enumerable, so do NOT
                // return the courier's full personal number (harvestable at scale). Mask to the
                // last 4 digits — enough for the storefront to show a "contact courier" hint.
                'mobile'     => $dp->mobile ? ('xxxxxx' . substr(preg_replace('/\D/', '', (string) $dp->mobile), -4)) : null,
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
     * Public (by tracking number): per-shipment courier status for the customer timeline
     * (C5). Courier shipments expose courier name + AWB + tracking link + status; local
     * shipments fall back to the live DP map (courierLocation). No vendor identity is leaked.
     */
    public function trackingShipments(Request $request, $tracking)
    {
        $order = Order::where('tracking_number', $tracking)->first();
        if (!$order || !$this->canViewTracking($request, $order)) {
            // Not found OR not permitted → identical empty payload, so an enumerator
            // (tracking numbers are date+6-digits, guessable at scale) learns nothing.
            return ['shipments' => []];
        }
        $rows = Shipment::with('items.product:id,name,slug,image')
            ->where('order_id', $order->id)->get();
        return [
            'shipments' => $rows->map(fn ($s) => [
                'fulfillment_mode'     => $s->fulfillment_mode,
                'courier_name'         => $s->courier_name,
                'awb_number'           => $s->awb_number,
                'tracking_url'         => $s->tracking_url,
                'status'               => $s->status,
                'last_status'          => $s->last_status,
                'payment_method'       => $s->payment_method,
                'shipped_at'           => optional($s->shipped_at)->toDateString(),
                'delivered_at'         => optional($s->delivered_at)->toDateString(),
                'eta_days'             => $s->eta_days,
                'expected_delivery_at' => optional($s->expected_delivery_at)->toDateString(),
                // Parcel contents — whitelist only (never vendor identity or platform cost).
                'items'                => $s->items->map(fn ($it) => [
                    'name'     => $it->product->name ?? null,
                    'slug'     => $it->product->slug ?? null,
                    'image'    => $it->product->image ?? null,
                    'quantity' => (int) $it->order_quantity,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Same access semantics as OrderController::fetchSingleOrder, minus the vendor branch
     * (vendors don't use the customer tracking endpoint): guest orders require the per-order
     * tracking_token (hash_equals); legacy tokenless guest orders stay public (non-PII
     * logistics only); registered orders are owner or super-admin only.
     */
    private function canViewTracking(Request $request, Order $order): bool
    {
        if (!$order->customer_id) {
            if (!empty($order->tracking_token)) {
                $provided = (string) ($request->query('token') ?? $request->input('token') ?? '');
                return $provided !== '' && hash_equals((string) $order->tracking_token, $provided);
            }
            return true; // legacy tokenless guest order
        }
        $user = $request->user();
        if (!$user) {
            return false;
        }
        try {
            return ((int) $user->id === (int) $order->customer_id)
                || $user->hasPermissionTo(Permission::SUPER_ADMIN);
        } catch (\Throwable $e) {
            return false;
        }
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

