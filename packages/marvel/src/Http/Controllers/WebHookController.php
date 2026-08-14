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
    /**
     * Kill dead gateway surfaces: Payment::handleWebHooks resolves the ACTIVE
     * gateway no matter which /webhooks/{name} URL was hit, so every URL not on
     * the allowlist (config shop.enabled_webhook_gateways) is an unauthenticated
     * alias into live payment code for a gateway this business never enabled —
     * 404 them before any payment code runs. Razorpay additionally requires its
     * webhook secret so a half-configured deploy fails closed.
     */
    private function gateWebhook(string $gateway): void
    {
        $enabled = (array) config('shop.enabled_webhook_gateways', []);
        if (!in_array(strtolower($gateway), $enabled, true)) {
            abort(404);
        }
        if (strtolower($gateway) === 'razorpay' && empty(config('shop.razorpay.webhook_secret'))) {
            abort(404);
        }
    }

    public function stripe(Request $request)
    {
        $this->gateWebhook('stripe');
        return Payment::handleWebHooks($request);
    }

    public function paypal(Request $request)
    {
        $this->gateWebhook('paypal');
        return Payment::handleWebHooks($request);
    }

    public function razorpay(Request $request)
    {
        $this->gateWebhook('razorpay');
        return Payment::handleWebHooks($request);
    }
    public function mollie(Request $request)
    {
        $this->gateWebhook('mollie');
        return Payment::handleWebHooks($request);
    }
    public function sslcommerz(Request $request)
    {
        $this->gateWebhook('sslcommerz');
        return Payment::handleWebHooks($request);
    }
    public function paystack(Request $request)
    {
        $this->gateWebhook('paystack');
        return Payment::handleWebHooks($request);
    }
    public function paymongo(Request $request)
    {
        $this->gateWebhook('paymongo');
        return Payment::handleWebHooks($request);
    }
    public function xendit(Request $request)
    {
        $this->gateWebhook('xendit');
        return Payment::handleWebHooks($request);
    }
    public function iyzico(Request $request)
    {
        $this->gateWebhook('iyzico');
        return Payment::handleWebHooks($request);
    }
    public function bkash(Request $request)
    {
        $this->gateWebhook('bkash');
        return Payment::handleWebHooks($request);
    }
    public function flutterwave(Request $request)
    {
        $this->gateWebhook('flutterwave');
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
     * Token-verified (x-api-key == services.shipping_service.callback_key), idempotent.
     * Maps the service-normalized shipment status through CourierService::applyNormalizedStatus —
     * the SAME monotonic order-advance + vendor-settlement seam the in-process webhooks use — so
     * settlement fires identically whether the monolith or the service drove the delivery.
     *
     * DATA LOSS FIX 2026-08-14: this used to catch every Throwable and answer 200. The Go relay
     * (internal/outbox/relay.go) marks any 2xx as PUBLISHED, so a Laravel-side failure — DB down,
     * a deadlock on the order advance, a bug in the seam — permanently destroyed that status
     * event: the parcel moves and the customer's order never does. A genuine processing failure
     * now answers 503 so the relay reschedules it (MarkOutboxRetry, 2^attempts s capped at 1h).
     *
     * The split is deliberate — these are NOT failures and keep answering 200, because the relay
     * has no attempt cap and would retry them until the heat death of the universe:
     *   - shipping service disabled (SHIPPING_SERVICE_ENABLED / master switch off)
     *   - missing / non-integer shipment_ref, or an empty normalized_status
     *   - no shipment row for that ref (deleted, or an event for another environment)
     *   - a status the mapper doesn't recognise, a replay, a backward step, terminal-sticky —
     *     all of which applyNormalizedStatus absorbs as a successful no-op
     * ponytail: retry is unbounded on the relay side, so a DETERMINISTIC bug here retries every
     * hour forever; the 'Shipping callback error' log line is the alarm. Add a dead-letter cap in
     * the relay if that ever becomes noise.
     */
    public function shippingCallback(Request $request)
    {
        CourierService::applyAdminPartnerConfig(); // resolve admin-managed (DB) creds before reading config
        $expected = (string) config('services.shipping_service.callback_key');
        if (empty($expected) || !hash_equals($expected, (string) $request->header('x-api-key'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            // Resolved, not `new`: same object graph (nothing binds it), but the courier seam
            // becomes fakeable — which is how the 503-vs-200 split above is actually tested.
            $svc = app(CourierService::class);
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
            // GENUINE failure — everything that is merely a no-op returned 200 above.
            Log::error('Shipping callback error', [
                'error'        => $e->getMessage(),
                'shipment_ref' => (string) (((array) $request->input('data', []))['shipment_ref'] ?? ''),
                'event_id'     => $request->input('id'),
            ]);
            // 503 (not 500): the relay only checks status/100 != 2, but a retryable class is the
            // honest answer and keeps proxies/alerting from filing this as an app crash.
            return response()->json(['ok' => false, 'error' => 'processing failed'], 503);
        }
    }

    /**
     * SendGrid Event Webhook → advances email_logs rows. Correlates by the
     * email_log_id custom arg attached at send time (EmailService::tagMessage).
     * Out-of-order protection: a status only advances (rank map), and terminal
     * failure states always win. Never 5xxes — SendGrid retries on non-2xx and
     * we'd rather drop one event than build a retry storm.
     *
     * SECURITY 2026-08-07: this route had NO authentication of any kind. An anonymous POST
     * with a guessed email_log_id could mark any message bounced/failed/spam — the terminal
     * states below deliberately override everything, so it was a write primitive over the
     * whole delivery log, and the operational picture it feeds.
     *
     * Verified with a shared secret in the URL (SendGrid lets you set an arbitrary callback
     * URL, so the secret can ride in the query string) or a header, compared with hash_equals
     * exactly as shippingCallback does above.
     *
     * Rollout is deliberately tolerant: with no secret configured we accept and log a warning,
     * so turning this on cannot silently break delivery tracking for an integration that is
     * already pointed here. Set SENDGRID_WEBHOOK_TOKEN and update the URL in the SendGrid
     * console and it becomes strict — see the ops note in the security report.
     */
    public function sendgridEvents(Request $request)
    {
        $expected = (string) config('services.sendgrid.webhook_token');
        if ($expected === '') {
            Log::warning('sendgrid webhook accepted UNVERIFIED — set SENDGRID_WEBHOOK_TOKEN to enforce');
        } else {
            $presented = (string) ($request->header('x-webhook-token')
                ?: $request->header('x-api-key')
                ?: $request->query('token', ''));
            if (!hash_equals($expected, $presented)) {
                // 401, never 5xx: a rejected event must not trigger SendGrid's retry storm.
                return response()->json(['message' => 'Unauthorized.'], 401);
            }
        }

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

    /**
     * GET webhooks/whatsapp — Meta's subscription handshake.
     *
     * Meta calls this once when the webhook URL is saved and expects the raw
     * hub.challenge echoed back as text/plain when hub.verify_token matches the
     * token configured in the Meta app. Fails closed: no configured token ⇒ 404,
     * so an unconfigured deployment cannot be subscribed by a third party.
     */
    public function whatsappVerify(Request $request)
    {
        $expected = (string) config('services.whatsapp.webhook_verify_token');
        $presented = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($expected === '') {
            Log::warning('whatsapp webhook verify attempted with no WHATSAPP_WEBHOOK_VERIFY_TOKEN set');
            return response()->json(['message' => 'Not Found'], 404);
        }
        if (!hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST webhooks/whatsapp — Meta message/status events.
     *
     * Verified with the X-Hub-Signature-256 HMAC over the RAW body (Meta signs
     * bytes, so a re-encoded array would not match). Records delivery state for
     * OTP/notification messages by their WhatsApp message id. Idempotent (status
     * only ever advances) and NEVER 5xxes — a non-2xx makes Meta retry the whole
     * batch, which is how a transient bug becomes a webhook storm.
     *
     * Inbound message CONTENT is deliberately not stored: OTP replies would put
     * codes in our database.
     */
    public function whatsappEvents(Request $request)
    {
        $secret = (string) config('services.whatsapp.app_secret');
        if ($secret !== '') {
            $presented = (string) $request->header('X-Hub-Signature-256', '');
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($expected, $presented)) {
                return response()->json(['message' => 'Unauthorized.'], 401);
            }
        } else {
            Log::warning('whatsapp webhook accepted UNVERIFIED — set WHATSAPP_APP_SECRET to enforce');
        }

        try {
            foreach ((array) $request->input('entry', []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $value = (array) ($change['value'] ?? []);
                    foreach ((array) ($value['statuses'] ?? []) as $status) {
                        $this->recordWhatsappStatus($status);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('whatsapp webhook event skipped: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * One delivery status. Logged (not persisted): OTP sends have no durable row —
     * the code lives only in the cache — so the log line + message id is the
     * correlation point between our dispatch and Meta's delivery.
     */
    private function recordWhatsappStatus(array $status): void
    {
        $messageId = (string) ($status['id'] ?? '');
        $state = (string) ($status['status'] ?? '');
        if ($messageId === '' || $state === '') {
            return;
        }
        $context = [
            'message_id' => $messageId,
            'status' => $state, // sent | delivered | read | failed
            'recipient_suffix' => substr((string) ($status['recipient_id'] ?? ''), -4),
        ];
        if ($state === 'failed') {
            $err = (array) ($status['errors'][0] ?? []);
            $context['error_code'] = $err['code'] ?? null;
            $context['error_title'] = $err['title'] ?? null;
            Log::warning('whatsapp.delivery.failed', $context);
            return;
        }
        Log::info('whatsapp.delivery.' . $state, $context);
    }
}
