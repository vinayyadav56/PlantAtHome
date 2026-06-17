<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
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
     * Shiprocket shipment-status webhook (C4). Token-verified (x-api-key), idempotent. The
     * status application (shipment + order advance via OrderRepository::changeOrderStatus —
     * which fires the ledger/settlement/DP fan-out, completing the order only when no leg is
     * still in-flight) lives in CourierService::applyStatus and is SHARED with the scheduled
     * `marvel:courier-reconcile` command. On an internal error we log + return 200 {ok:false}
     * (no 5xx → no Shiprocket retry storm); courier-reconcile re-tracks any shipment whose
     * webhook was missed/failed, so a dropped event still recovers.
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

            if ($request->filled('tracking_url')) {
                $shipment->forceFill(['tracking_url' => (string) $request->input('tracking_url')])->save();
            }
            (new CourierService())->applyStatus($shipment, $providerStatus);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Shiprocket webhook error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false], 200); // never 5xx to the courier
        }
    }
}
