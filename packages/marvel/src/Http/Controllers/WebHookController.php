<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Facades\Payment;
use Marvel\Payments\Flutterwave;
use Marvel\Services\Courier\BorzoAdapter;
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
            $awb = trim((string) ($request->input('awb') ?? ''));
            $providerShipmentId = trim((string) ($request->input('shipment_id') ?? ''));
            $providerStatus = (string) ($request->input('current_status') ?? $request->input('status') ?? '');

            // Require a real identifier — never let an empty payload degrade to a bare ->first()
            // that would match an arbitrary (possibly Borzo) row. Also scope to provider=shiprocket.
            if ($providerShipmentId === '' && $awb === '') {
                return response()->json(['ok' => true, 'note' => 'no shipment identifier']);
            }
            $shipment = Shipment::where('provider', 'shiprocket')
                ->when($providerShipmentId !== '', fn ($q) => $q->where('provider_shipment_id', $providerShipmentId))
                ->when($providerShipmentId === '' && $awb !== '', fn ($q) => $q->where('awb_number', $awb))
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

    /**
     * Borzo order/delivery callback. Idempotent + never-5xx (a 4xx/5xx makes Borzo retry for 24h).
     * ANTI-SPOOF: the callback body is treated as an untrusted *trigger* only — we re-fetch the
     * order from Borzo's authenticated API (track) and apply THAT status, so a forged callback can
     * never move money/state. We fall back to the callback's own status ONLY when its HMAC
     * signature verified (verifyWebhook) AND the re-fetch was unavailable. Shared apply path
     * (applyNormalizedStatus → OrderRepository::changeOrderStatus) + reconcile sweep as backstop.
     */
    public function borzo(Request $request)
    {
        try {
            $svc = new CourierService();
            if (!$svc->borzoEnabled()) {
                return response()->json(['ok' => true, 'note' => 'borzo lane disabled']); // ack; don't retry-storm
            }

            $adapter = new BorzoAdapter();
            $norm = $adapter->normalizeWebhook($request);
            $signatureValid = !empty($norm['signature_valid']);

            // Only a SIGNATURE-VERIFIED callback triggers the (authenticated, possibly slow) outbound
            // re-fetch — an unverified/forged POST can't be used to amplify calls into Borzo or move
            // any state. When the callback token isn't configured (so we can't verify), we ack and
            // let the hourly reconcile sweep advance status. Either way: never 5xx.
            if ($signatureValid) {
                foreach (($norm['events'] ?? []) as $ev) {
                    $orderId = (string) ($ev['provider_order_id'] ?? '');
                    if ($orderId === '') {
                        continue;
                    }
                    $shipment = Shipment::where('provider', 'borzo')->where('provider_order_id', $orderId)->first();
                    if (!$shipment) {
                        continue;
                    }
                    // Authoritative status from the authenticated API (not the callback body).
                    $track = $adapter->track($shipment);
                    if (!empty($track['ok']) && !empty($track['normalized'])) {
                        $svc->applyNormalizedStatus($shipment, $track['normalized']);
                    } elseif (!empty($ev['normalized'])) {
                        $svc->applyNormalizedStatus($shipment, $ev['normalized']); // verified callback fallback
                    }
                }
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Borzo webhook error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false], 200); // never 5xx to the partner
        }
    }

    /**
     * Callback from the dedicated Go shipping microservice (status/COD events from its outbox).
     * Token-verified (x-api-key == services.shipping_service.callback_key), idempotent, never-5xx.
     * Maps the service-normalized shipment status through CourierService::applyNormalizedStatus —
     * the SAME monotonic order-advance + vendor-settlement seam the in-process webhooks use — so
     * settlement fires identically whether the monolith or the service drove the delivery.
     */
    public function shippingCallback(Request $request)
    {
        $expected = (string) config('services.shipping_service.callback_key');
        if (empty($expected) || !hash_equals($expected, (string) $request->header('x-api-key'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            $svc = new CourierService();
            // Inbound is inert unless the service actually owns shipping — keeps behavior identical
            // to before whenever SHIPPING_SERVICE_ENABLED is off (even if the key happens to be set).
            if (!$svc->shippingServiceEnabled()) {
                return response()->json(['ok' => true, 'note' => 'shipping service disabled']);
            }

            $data = (array) $request->input('data', []);
            $shipmentRef = (string) ($data['shipment_ref'] ?? '');
            $status = (string) ($data['normalized_status'] ?? '');
            // shipment_ref is our shipment id — require a clean integer (avoid MySQL loose coercion
            // matching "12-x" → 12).
            if ($shipmentRef === '' || !ctype_digit($shipmentRef) || $status === '') {
                return response()->json(['ok' => true, 'note' => 'no/invalid shipment']);
            }

            $shipment = Shipment::find((int) $shipmentRef);
            if (!$shipment) {
                return response()->json(['ok' => true, 'note' => 'no matching shipment']); // ack; don't retry-storm
            }

            // Sync the provider identifiers/tracking the service learned — but NEVER clobber a row
            // that is already terminal (a stale/replayed event must not overwrite live tracking).
            if (!in_array($shipment->status, ['delivered', 'cancelled'], true)) {
                $fill = [];
                foreach (['provider_order_id', 'awb_number', 'tracking_url'] as $k) {
                    if (!empty($data[$k])) {
                        $fill[$k] = (string) $data[$k];
                    }
                }
                if (!empty($data['partner'])) {
                    $fill['provider'] = (string) $data['partner'];
                }
                if ($fill) {
                    $shipment->forceFill($fill)->save();
                }
            }

            $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus($status));

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Shipping callback error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false], 200); // never 5xx to the service relay
        }
    }
}
