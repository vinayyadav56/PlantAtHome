<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    // NOTE: the direct Shiprocket/Borzo partner webhooks were removed — partners now call the Go
    // shipping-service's /webhooks/{partner} endpoints; status reaches the monolith exclusively
    // through shippingCallback below.

    /**
     * Callback from the dedicated Go shipping microservice (status/COD events from its outbox).
     * Token-verified (x-api-key == services.shipping_service.callback_key), idempotent, never-5xx.
     * Maps the service-normalized shipment status through CourierService::applyNormalizedStatus —
     * the SAME monotonic order-advance + vendor-settlement seam the in-process webhooks use — so
     * settlement fires identically whether the monolith or the service drove the delivery.
     */
    public function shippingCallback(Request $request)
    {
        CourierService::applyAdminPartnerConfig(); // resolve admin-managed (DB) creds before reading config
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
            if (!in_array($shipment->status, ['delivered', 'cancelled', 'rto'], true)) {
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

    /**
     * SendGrid Event Webhook → advances email_logs rows. Correlates by the
     * email_log_id custom arg attached at send time (EmailService::tagMessage).
     * Out-of-order protection: a status only advances (rank map), and terminal
     * failure states always win. Never 5xxes — SendGrid retries on non-2xx and
     * we'd rather drop one event than build a retry storm.
     */
    public function sendgridEvents(Request $request)
    {
        // Progression rank; failures are terminal and always override.
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'opened' => 3, 'clicked' => 4];
        $map = [
            'processed' => 'sent', 'delivered' => 'delivered', 'open' => 'opened',
            'click' => 'clicked', 'bounce' => 'bounced', 'dropped' => 'failed',
            'deferred' => null, 'spamreport' => 'spam',
        ];

        $events = $request->json()->all();
        if (!is_array($events)) {
            return response()->json(['ok' => true]);
        }

        foreach ($events as $e) {
            try {
                $logId = (int) ($e['email_log_id'] ?? 0);
                if ($logId <= 0) {
                    continue; // not one of ours (marketing has its own pipeline)
                }
                $status = $map[(string) ($e['event'] ?? '')] ?? null;
                if ($status === null) {
                    continue;
                }
                $row = DB::table('email_logs')->where('id', $logId)->first();
                if ($row === null) {
                    continue;
                }
                $terminalFailure = in_array($status, ['bounced', 'failed', 'spam'], true);
                $advances = isset($rank[$status], $rank[$row->status]) && $rank[$status] > $rank[$row->status];
                if ($terminalFailure || $advances) {
                    DB::table('email_logs')->where('id', $logId)->update([
                        'status' => $status,
                        'error' => $terminalFailure ? mb_substr((string) ($e['reason'] ?? $e['type'] ?? $status), 0, 2000) : $row->error,
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $t) {
                Log::warning('sendgrid webhook event skipped: ' . $t->getMessage());
            }
        }

        return response()->json(['ok' => true]);
    }

}
