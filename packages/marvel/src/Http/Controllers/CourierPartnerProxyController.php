<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Services\Courier\ShippingServiceClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Super-admin proxy to the Go shipping-service's per-partner RUNTIME config endpoints
 * (GET/PUT /v1/partners/{code}/config). Lets an operator flip a partner's master switch + per-API
 * COST toggles — e.g. Porter's paid get_quote / track — WITHOUT a redeploy.
 *
 * The toggle state lives in the shipping-service (it drives that service's autonomous reconcile /
 * track loops, which the monolith can't gate per-call), so this controller only FORWARDS. Partner
 * credentials are env-only and never handled here. It degrades gracefully: when the service is not
 * configured or unreachable it returns connected=false so the admin card shows a clear
 * "shipping service not connected" state instead of erroring.
 *
 * It ALSO proxies the service's partner DEBUG CONSOLE (webhooks + test/*). Those forward 1:1 and
 * hand the service's JSON body back untouched, because the payload's `exchange` object — the exact
 * upstream request/response, credential-masked by the service — is the entire point: a developer
 * debugs a partner integration from the admin UI instead of server logs. Two rules hold there:
 *   - test/book and test/cancel touch a REAL delivery. The confirmation guard lives in the SERVICE;
 *     this controller copies the caller's `confirm` across verbatim and never defaults or invents
 *     it, so no bug on this side can satisfy the guard by accident.
 *   - request/response bodies are never logged here (they carry partner payloads).
 */
class CourierPartnerProxyController extends CoreController
{
    /** Partners whose runtime config may be proxied (defence-in-depth on the {code} path param). */
    private array $allowedPartners = ['porter', 'borzo', 'shiprocket', 'shiprocket_quick'];

    public function show(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            return $this->notConnected($code);
        }
        $res = $client->getPartnerConfig($code);
        if (empty($res['ok'])) {
            return $this->notConnected($code, $res['error'] ?? null);
        }
        return ['connected' => true, 'config' => $res['data']];
    }

    public function update(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $validated = $request->validate([
            'enabled'        => 'sometimes|boolean',
            'quote_enabled'  => 'sometimes|boolean',
            'track_enabled'  => 'sometimes|boolean',
            'cancel_enabled' => 'sometimes|boolean',
            // Per-mode preference: {"same_city": 10}. Lower wins. A rank must be POSITIVE — the
            // service reads 0 as "no preference", so accepting it here would report a save that
            // changes no routing. Clearing a mode means omitting its key, not sending 0.
            'mode_priority'   => 'sometimes|array',
            'mode_priority.*' => 'integer|min:1',
        ]);
        // Forward only the toggles actually sent — the service does a partial merge over its current
        // effective state, so an untouched switch is never clobbered.
        $payload = [];
        foreach (['enabled', 'quote_enabled', 'track_enabled', 'cancel_enabled'] as $f) {
            if (array_key_exists($f, $validated)) {
                $payload[$f] = (bool) $validated[$f];
            }
        }
        if (array_key_exists('mode_priority', $validated)) {
            // Sent WHOLE, not merged per key: it is one setting, and a per-key merge would make a
            // mode impossible to un-rank. Cast to int so "10" from a form field is not stored as a
            // string the service would reject.
            $ranks = array_map('intval', (array) $validated['mode_priority']);
            // ⚠️ An empty PHP array serializes as JSON `[]`, and the service decodes this field into
            // an object — so clearing every rank, the one case that MUST get through, would 400.
            // stdClass forces `{}`.
            $payload['mode_priority'] = $ranks === [] ? new \stdClass() : $ranks;
        }
        // No toggles supplied → a no-op: return current state rather than forwarding an empty body
        // (Laravel serializes [] as JSON "[]", which the service's object decoder rejects with 400).
        if (empty($payload)) {
            return $this->show($request, $code);
        }

        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            // Self-rendering HttpException so the status + reason survive (these routes are under
            // /api, where a plain MarvelException would surface as a bare 500).
            throw new HttpException(503, 'The shipping service is not configured; cannot save partner toggles.');
        }
        $res = $client->putPartnerConfig($code, $payload);
        if (empty($res['ok'])) {
            throw new HttpException(502, $res['error'] ?? 'Failed to update the partner configuration.');
        }
        return ['connected' => true, 'config' => $res['data']];
    }

    // ── partner debug console ─────────────────────────────────────

    /** Recent inbound webhooks the service recorded for this partner (newest first, cursor-paged). */
    public function webhooks(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        // Bounds mirror the service contract (default 50, max 200) so a bad page size fails here
        // with a 422 instead of burning an upstream call.
        $validated = $request->validate([
            'limit'    => 'sometimes|integer|min:1|max:200',
            'before'   => 'sometimes|integer|min:1',
            'after'    => 'sometimes|integer|min:1',
            'order_id' => 'sometimes|string|max:64',
        ]);
        $query = [];
        foreach (['limit', 'before', 'after'] as $key) {
            if (array_key_exists($key, $validated)) {
                $query[$key] = (int) $validated[$key];
            }
        }
        if (!empty($validated['order_id'])) {
            $query['order_id'] = (string) $validated['order_id'];
        }

        return $this->passthrough(fn (ShippingServiceClient $c) => $c->getPartnerWebhooks($code, $query));
    }

    /** Live quote against the partner API — read-only, but a PAID call on some partners. */
    /**
     * Force a fresh partner login (Shiprocket's session token) and report the new expiry.
     *
     * Not needed to keep the token healthy — the service caches for 9 days against Shiprocket's
     * 10-day expiry, re-logins on the next call after that, and retries once on a 401/403. This is
     * for what automation cannot do: validate the stored credentials on demand, and recover
     * immediately after a password reset instead of waiting out the cache.
     */
    public function refreshToken(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        return $this->passthrough(fn (ShippingServiceClient $c) => $c->refreshPartnerToken($code));
    }

    public function testQuote(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);
        $body = $this->legPayload($request);

        return $this->passthrough(fn (ShippingServiceClient $c) => $c->partnerTestQuote($code, $body));
    }

    /** Live status lookup for one provider order id — read-only, paid on some partners. */
    public function testTrack(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $validated = $request->validate(['provider_order_id' => 'required|string|max:191']);
        $pid = trim((string) $validated['provider_order_id']);

        return $this->passthrough(
            fn (ShippingServiceClient $c) => $c->partnerTestTrack($code, $pid),
            // Keep a RECORDED order's status fresh — but never CREATE one here (tracking an id we never
            // recorded shouldn't manufacture a row).
            function (array $data) use ($code, $pid): void {
                $row = \App\Models\PartnerConsoleOrder::where('partner_code', $code)
                    ->where('provider_order_id', $pid)
                    ->first();
                if (!$row) {
                    return; // deliberately never creates a row
                }
                // A refused or empty track used to write last_status = NULL and bump
                // last_tracked_at — wiping a real status and making the row look freshly
                // polled. A failure is recorded as a failure; the status only moves on data,
                // and only forward (the lifecycle guard owns the rules).
                if (($data['ok'] ?? null) === false) {
                    $row->forceFill([
                        'last_error'         => (string) ($data['error'] ?? 'track failed'),
                        'last_error_payload' => $data,
                        'last_error_at'      => now(),
                    ])->save();
                    return;
                }
                $status = trim((string) ($data['status'] ?? ''));
                if ($status === '') {
                    return; // Porter's swallowed-429 shape — nothing learned, nothing written
                }
                $raw = null;
                if (is_string($data['exchange']['response']['body'] ?? null)) {
                    $raw = json_decode($data['exchange']['response']['body'], true);
                }
                $mapped = \App\Services\PartnerOrderLifecycle::fromNormalized(
                    is_array($raw) ? (string) ($raw['status'] ?? $status) : $status,
                );
                if ($mapped !== null) {
                    \App\Services\PartnerOrderLifecycle::apply($row, $mapped, 'track');
                }
                $row->forceFill([
                    'last_status'             => $status,
                    'latest_tracking_payload' => is_array($raw) ? $raw : null,
                    'last_tracked_at'         => now(),
                    'track_failures'          => 0,
                ])->save();
            }
        );
    }

    /**
     * Start Porter's UAT order-flow simulator against an existing CRN.
     *
     * NO environment check here on purpose. The service refuses this outside a sandbox and is the
     * single owner of that rule — exactly like the destructive confirm phrase, which deliberately
     * appears nowhere in this codebase. A second check here would be a second thing to keep in step,
     * and the one that drifts is the one that lets something through.
     */
    public function simulateFlow(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $validated = $request->validate([
            'provider_order_id' => 'required|string|max:191',
            // Porter documents flows 0..7. `integer` rejects "0" and 1.5; the service re-validates.
            'flow_type'         => 'required|integer|between:0,7',
        ]);
        $pid = trim((string) $validated['provider_order_id']);
        $flow = (int) $validated['flow_type'];

        return $this->passthrough(
            fn (ShippingServiceClient $c) => $c->partnerSimulateFlow($code, $pid, $flow),
            // Stamp the simulation wherever this CRN actually lives. Never CREATE a console row:
            // the CRN being simulated usually belongs to a real shipment, not a console probe, and
            // manufacturing a console order for it would put a shipment in the wrong ledger.
            //
            // That "usually" was the bug. Both stamps are `where(...)->update(...)`, and for the
            // simulator people actually use — mounted on an order's shipment panel — there is no
            // console row, so the console stamp wrote ZERO rows every time. The flow was recorded
            // nowhere durable, and the browser's in-memory record died on refresh.
            //
            // A refused simulation must not be stamped: passthrough runs this whenever the HTTP
            // call succeeded, which includes the ok:false partner-refusal envelope.
            function (array $data) use ($request, $code, $pid, $flow): void {
                $httpStatus = (int) ($data['exchange']['response']['status'] ?? 0);
                if (($data['ok'] ?? null) === false) {
                    // A refused flow is still evidence — flow_already_initiated in particular is
                    // a known condition, not a mystery. Recorded on the row, never stamped as
                    // running, and never a reason to touch the order's status.
                    \App\Models\PartnerConsoleOrder::where('partner_code', $code)
                        ->where('provider_order_id', $pid)
                        ->update([
                            'simulation_response'    => json_encode($data),
                            'simulation_http_status' => $httpStatus,
                            'last_error'             => (string) ($data['error'] ?? 'flow refused'),
                            'last_error_at'          => now(),
                        ]);
                    return;
                }
                $stamp = [
                    'simulation_flow_type'  => $flow,
                    'simulation_started_at' => now(),
                ];

                \Marvel\Database\Models\Shipment::where('provider_order_id', $pid)->update($stamp);

                \App\Models\PartnerConsoleOrder::where('partner_code', $code)
                    ->where('provider_order_id', $pid)
                    ->update($stamp + [
                        'simulation_started_by'  => optional($request->user())->id,
                        // Porter answers a successful initiate with a bare {} — recorded as the
                        // success it is, exactly as received.
                        'simulation_response'    => json_encode($data),
                        'simulation_http_status' => $httpStatus,
                    ]);
            }
        );
    }

    /** CREATES A REAL DELIVERY at the partner. Guarded by the service on the caller's `confirm`. */
    public function testBook(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);
        $body = $this->withCallerConfirmation($request, $this->legPayload($request));

        return $this->passthrough(
            fn (ShippingServiceClient $c) => $c->partnerTestBook($code, $body),
            // Record every REAL order the console creates so it can be listed + re-tracked later. Persist
            // whenever a provider_order_id came back — even alongside a partner error (order created,
            // AWB pending) — so a real order is never left unrecoverable.
            function (array $data) use ($request, $code, $body): void {
                $pid = trim((string) ($data['provider_order_id'] ?? ''));
                if ($pid === '') {
                    // The attempt FAILED before a CRN existed. That row is exactly what an
                    // operator needs when the partner refuses bookings — a ledger that only
                    // remembers successes cannot answer "why did nothing get created?".
                    // create(), never updateOrCreate with a null id: Eloquent turns null into
                    // whereNull and would collapse every failure into one endlessly-rewritten row.
                    \App\Models\PartnerConsoleOrder::create([
                        'partner_code'       => $code,
                        'provider_order_id'  => null,
                        'origin'             => 'console',
                        'mode'               => $body['mode'] ?? null,
                        'cod_amount_paise'   => (int) ($body['cod_amount_paise'] ?? 0),
                        'request'            => $body,
                        'response'           => $data,
                        'last_error'         => (string) ($data['error'] ?? 'booking failed'),
                        'last_error_payload' => $data,
                        'last_error_at'      => now(),
                        'created_by'         => optional($request->user())->id,
                    ]);
                    return;
                }
                $row = \App\Models\PartnerConsoleOrder::updateOrCreate(
                    ['partner_code' => $code, 'provider_order_id' => $pid],
                    [
                        'origin'           => 'console',
                        'mode'             => $body['mode'] ?? null,
                        'cod_amount_paise' => (int) ($body['cod_amount_paise'] ?? 0),
                        'request'          => $body,
                        'response'         => $data,
                        'last_status'      => $data['status'] ?? null,
                        // The contract's create response carries the partner's tracking page.
                        'tracking_url'     => $data['tracking_url'] ?? ($data['exchange']['response']['body'] ?? null ? (json_decode((string) $data['exchange']['response']['body'], true)['tracking_url'] ?? null) : null),
                        'last_tracked_at'  => now(),
                        'created_by'       => optional($request->user())->id,
                    ]
                );
                // A freshly created Porter order opens at `open` — recorded through the guard so
                // the ledger's very first status is real, not inferred.
                \App\Services\PartnerOrderLifecycle::apply($row, 'open', 'create');
            }
        );
    }

    /** CANCELS A LIVE JOB at the partner. Guarded by the service on the caller's `confirm`. */
    public function testCancel(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $validated = $request->validate(['provider_order_id' => 'required|string|max:191']);
        $body = $this->withCallerConfirmation($request, [
            'provider_order_id' => (string) $validated['provider_order_id'],
        ]);

        $pid = (string) $validated['provider_order_id'];

        return $this->passthrough(
            fn (ShippingServiceClient $c) => $c->partnerTestCancel($code, $body),
            // Cancellation is stamped ONLY on the partner's confirmed success — the mandate's
            // rule is that cancelled comes from the partner or from a confirmed cancel call,
            // never from absence, failure, or a guess. A refused cancel records the refusal.
            function (array $data) use ($code, $pid): void {
                $row = \App\Models\PartnerConsoleOrder::where('partner_code', $code)
                    ->where('provider_order_id', $pid)->first();
                if (!$row) {
                    return;
                }
                if (($data['ok'] ?? null) === false) {
                    $row->forceFill([
                        'last_error'         => (string) ($data['error'] ?? 'cancel failed'),
                        'last_error_payload' => $data,
                        'last_error_at'      => now(),
                    ])->save();
                    return;
                }
                \App\Services\PartnerOrderLifecycle::apply($row, 'cancelled', 'cancel');
            }
        );
    }

    /**
     * Copy the caller's `confirm` across UNCHANGED — never defaulted, never synthesised, and absent
     * when the caller omitted it. The destructive guard's expected phrase lives in the SERVICE and
     * deliberately appears NOWHERE in this codebase: that is what makes it impossible for a bug
     * here to book or cancel for real without an operator explicitly typing it.
     */
    private function withCallerConfirmation(Request $request, array $body): array
    {
        if ($request->has('confirm')) {
            $body['confirm'] = $request->input('confirm');
        }
        return $body;
    }

    /** Shared pickup/drop shape for test/quote + test/book. */
    private function legPayload(Request $request): array
    {
        $validated = $request->validate([
            'pickup'           => 'required|array',
            'pickup.lat'       => 'required|numeric',
            'pickup.lng'       => 'required|numeric',
            'pickup.address'   => 'sometimes|nullable|string|max:500',
            'pickup.pincode'   => 'sometimes|nullable|string|max:16',
            'pickup.name'      => 'sometimes|nullable|string|max:120',
            'pickup.phone'     => 'sometimes|nullable|string|max:20',
            'pickup.city'      => 'sometimes|nullable|string|max:120',
            'pickup.state'     => 'sometimes|nullable|string|max:120',
            'pickup.landmark'  => 'sometimes|nullable|string|max:255',
            'drop'             => 'required|array',
            'drop.lat'         => 'required|numeric',
            'drop.lng'         => 'required|numeric',
            'drop.address'     => 'sometimes|nullable|string|max:500',
            'drop.pincode'     => 'sometimes|nullable|string|max:16',
            'drop.name'        => 'sometimes|nullable|string|max:120',
            'drop.phone'       => 'sometimes|nullable|string|max:20',
            'drop.city'        => 'sometimes|nullable|string|max:120',
            'drop.state'       => 'sometimes|nullable|string|max:120',
            'drop.landmark'    => 'sometimes|nullable|string|max:255',
            'weight_grams'     => 'sometimes|integer|min:1',
            'cod_amount_paise' => 'sometimes|integer|min:0',
            'mode'             => 'sometimes|nullable|string|in:instant,same_city,courier',
        ]);

        $payload = [
            'pickup' => $this->leg((array) $validated['pickup']),
            'drop'   => $this->leg((array) $validated['drop']),
        ];
        foreach (['weight_grams', 'cod_amount_paise'] as $key) {
            if (array_key_exists($key, $validated)) {
                $payload[$key] = (int) $validated[$key];
            }
        }
        if (!empty($validated['mode'])) {
            $payload['mode'] = (string) $validated['mode'];
        }
        return $payload;
    }

    private function leg(array $a): array
    {
        // Porter's create-order contract carries customer/pickup CONTACT detail, and riders use
        // it to find a gate or call the customer. This used to rebuild the leg as lat/lng/
        // address/pincode only, silently dropping every name and phone the console form sent —
        // contradicting the form's own promise that those fields reach the partner.
        $leg = [
            'lat'     => (float) ($a['lat'] ?? 0),
            'lng'     => (float) ($a['lng'] ?? 0),
            'address' => (string) ($a['address'] ?? ''),
            'pincode' => (string) ($a['pincode'] ?? ''),
        ];
        foreach (['name', 'phone', 'city', 'state', 'landmark'] as $key) {
            if (!empty($a[$key])) {
                $leg[$key] = (string) $a[$key];
            }
        }
        return $leg;
    }

    /**
     * Run one console call and hand the service's JSON body straight back.
     *
     * The service answers a partner-level failure with HTTP 200 + {ok:false, exchange:{...}} on
     * purpose, so the console can always SHOW what happened — that sails through untouched. Its own
     * 4xx (notably the book/cancel "confirmation required" 400) is the service talking to the
     * operator, so that body + status pass through too. Everything else collapses to the existing
     * 503/502 mapping.
     */
    private function passthrough(callable $call, ?callable $onData = null)
    {
        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            throw new HttpException(503, 'The shipping service is not configured; the partner console is unavailable.');
        }

        $res = $call($client);
        if (!empty($res['ok'])) {
            // ok here is the HTTP-level success; the service answers a partner-level failure with 200 +
            // {ok:false, exchange, provider_order_id?}, so this branch also carries a book that created a
            // real order but errored later (Shiprocket "order created, AWB pending"). onData sees that.
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            if ($onData) {
                $onData($data);
            }
            return response()->json($data);
        }

        $status = (int) ($res['status'] ?? 0);
        // 401/403 mean the MONOLITH's own X-Api-Key was rejected — an infra fault the operator can
        // do nothing about, and a 401 reaching the admin app reads as "your session died" and logs
        // them out. Those degrade to 502 like any other transport failure.
        if ($status >= 400 && $status < 500 && !in_array($status, [401, 403], true) && is_array($res['data'] ?? null)) {
            return response()->json($res['data'], $status);
        }
        throw new HttpException(502, $res['error'] ?? 'The shipping service request failed.');
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    private function assertPartner(string $code): string
    {
        $code = strtolower(trim($code));
        if (!in_array($code, $this->allowedPartners, true)) {
            throw new HttpException(404, 'Unknown courier partner.');
        }
        return $code;
    }

    /** Graceful "service not reachable" shape so the admin card can render a disabled/offline state. */
    private function notConnected(string $code, ?string $error = null): array
    {
        return [
            'connected' => false,
            'error'     => $error,
            'config'    => [
                'code'       => $code,
                'configured' => false,
                'source'     => 'unavailable',
            ],
        ];
    }
}
