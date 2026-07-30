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
    private array $allowedPartners = ['porter', 'borzo', 'shiprocket'];

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
            'limit'  => 'sometimes|integer|min:1|max:200',
            'before' => 'sometimes|integer|min:1',
        ]);
        $query = [];
        foreach (['limit', 'before'] as $key) {
            if (array_key_exists($key, $validated)) {
                $query[$key] = (int) $validated[$key];
            }
        }

        return $this->passthrough(fn (ShippingServiceClient $c) => $c->getPartnerWebhooks($code, $query));
    }

    /** Live quote against the partner API — read-only, but a PAID call on some partners. */
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

        return $this->passthrough(
            fn (ShippingServiceClient $c) => $c->partnerTestTrack($code, (string) $validated['provider_order_id'])
        );
    }

    /** CREATES A REAL DELIVERY at the partner. Guarded by the service on the caller's `confirm`. */
    public function testBook(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);
        $body = $this->withCallerConfirmation($request, $this->legPayload($request));

        return $this->passthrough(fn (ShippingServiceClient $c) => $c->partnerTestBook($code, $body));
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

        return $this->passthrough(fn (ShippingServiceClient $c) => $c->partnerTestCancel($code, $body));
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
            'drop'             => 'required|array',
            'drop.lat'         => 'required|numeric',
            'drop.lng'         => 'required|numeric',
            'drop.address'     => 'sometimes|nullable|string|max:500',
            'drop.pincode'     => 'sometimes|nullable|string|max:16',
            'weight_grams'     => 'sometimes|integer|min:1',
            'cod_amount_paise' => 'sometimes|integer|min:0',
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
        return $payload;
    }

    private function leg(array $a): array
    {
        return [
            'lat'     => (float) ($a['lat'] ?? 0),
            'lng'     => (float) ($a['lng'] ?? 0),
            'address' => (string) ($a['address'] ?? ''),
            'pincode' => (string) ($a['pincode'] ?? ''),
        ];
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
    private function passthrough(callable $call)
    {
        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            throw new HttpException(503, 'The shipping service is not configured; the partner console is unavailable.');
        }

        $res = $call($client);
        if (!empty($res['ok'])) {
            return response()->json($res['data'] ?? []);
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
