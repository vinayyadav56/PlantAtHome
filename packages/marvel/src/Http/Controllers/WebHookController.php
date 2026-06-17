<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Facades\Payment;
use Marvel\Payments\Flutterwave;
use Marvel\Services\Courier\CourierService;

class WebHookController extends CoreController
{

    public function stripe(Request $request)
    {
        return Payment::handleWebHooks($request);
    }

    public function paypal(Request $request)
    {
        return Payment::handleWebHooks($request);
    }

    public function razorpay(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function mollie(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function sslcommerz(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function paystack(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function paymongo(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function xendit(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function iyzico(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function bkash(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function flutterwave(Request $request)
    {
        return Payment::handleWebHooks($request);
    }
    public function callback(Request $request)
    {
        return Flutterwave::callback($request);
    }

    /**
     * Shiprocket shipment-status webhook (C4). Token-verified (x-api-key), idempotent, and
     * NEVER returns 5xx (a 500 triggers Shiprocket retry storms — we log + 200 {ok:false}
     * instead and recover via the courier:reconcile sweep). The customer order is advanced
     * ONLY through OrderRepository::changeOrderStatus so the ledger/settlement/DP fan-out
     * fires exactly as for any other delivery, and only flips to completed when ALL of the
     * order's shipments are delivered.
     */
    public function shiprocket(Request $request)
    {
        $expected = (string) config('services.shiprocket.webhook_token');
        if (empty($expected) || $request->header('x-api-key') !== $expected) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            $awb = (string) ($request->input('awb') ?? '');
            $providerShipmentId = (string) ($request->input('shipment_id') ?? '');
            $providerStatus = (string) ($request->input('current_status') ?? $request->input('status') ?? '');

            $shipment = Shipment::when($providerShipmentId, fn ($q) => $q->where('provider_shipment_id', $providerShipmentId))
                ->when(!$providerShipmentId && $awb, fn ($q) => $q->where('awb_number', $awb))
                ->first();
            if (!$shipment || $providerStatus === '') {
                return response()->json(['ok' => true, 'note' => 'no matching shipment']); // ack; don't retry-storm
            }

            $map = (new CourierService())->mapStatus($providerStatus);
            $now = Carbon::now();

            // Unknown provider status → just touch last_status_at, no state change.
            if ($map['shipment_status'] === null) {
                $shipment->forceFill(['last_status_at' => $now])->save();
                return response()->json(['ok' => true, 'note' => 'unmapped status']);
            }
            // Idempotency: already in this state → no-op (re-sent webhook).
            if ($shipment->status === $map['shipment_status']) {
                return response()->json(['ok' => true, 'note' => 'duplicate']);
            }
            $fill = [
                'last_status'    => $map['shipment_status'] ?? $shipment->last_status,
                'last_status_at' => $now,
            ];
            if ($map['shipment_status']) {
                $fill['status'] = $map['shipment_status'];
            }
            if ($map['shipment_status'] === 'shipped' && !$shipment->shipped_at) {
                $fill['shipped_at'] = $now;
            }
            if ($map['shipment_status'] === 'delivered') {
                $fill['delivered_at'] = $now;
            }
            if ($request->filled('tracking_url')) {
                $fill['tracking_url'] = (string) $request->input('tracking_url');
            }
            $shipment->forceFill($fill)->save();

            // Advance the customer order through the canonical seam (fires ledger/settlement).
            if ($map['order_status']) {
                $order = $shipment->order;
                if ($order) {
                    $target = $map['order_status'];
                    // Only complete the order once EVERY shipment on it is delivered.
                    if ($target === 'order-completed') {
                        $allDelivered = !Shipment::where('order_id', $order->id)->where('status', '!=', 'delivered')->exists();
                        if (!$allDelivered) {
                            $target = 'order-out-for-delivery';
                        }
                    }
                    if ($order->order_status !== $target) {
                        app(OrderRepository::class)->changeOrderStatus($order, $target);
                    }
                }
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Shiprocket webhook error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false], 200); // never 5xx to the courier
        }
    }
}
