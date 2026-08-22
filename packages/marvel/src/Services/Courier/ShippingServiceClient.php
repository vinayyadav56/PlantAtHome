<?php

namespace Marvel\Services\Courier;

use App\Models\PartnerConsoleOrder;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shipment;

/**
 * Thin HTTP client to the dedicated Go shipping microservice. CourierService delegates to this
 * (the ONLY shipping path); the partner integration + COD accounting live
 * in the service, and status flows back via the POST /api/shipping/callback receiver. Mirrors the
 * AI-service client pattern: X-Api-Key header, structured ['ok','status','data','error'] returns,
 * never throws for an API-level failure. The shared secret travels only in the header (never logged).
 */
class ShippingServiceClient
{
    private string $baseUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.shipping_service.url'), '/');
        $this->apiKey = config('services.shipping_service.api_key');
    }

    public function configured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey);
    }

    /**
     * Seconds to wait on the shipping service, floored above the service's OWN upstream budget.
     *
     * This is not a preference, it is an ordering constraint: the Go service allows a partner 25s
     * before it gives up and returns a considered error ("KYC verification is mandated…"). If our
     * timeout is shorter we abandon the connection first and can never receive that answer — every
     * slow partner call becomes "Network error contacting shipping service." instead.
     *
     * The Integrations UI exposes this field, and it was set to 15 in the database, i.e. ten
     * seconds before the service could possibly reply. A floor makes that unconfigurable rather
     * than relying on nobody typing a small number.
     *
     * An explicit per-call timeout (the Delivery Optimizer's tight budget) is deliberately NOT
     * floored — that path degrades to an estimate on purpose.
     */
    private function defaultTimeout(): int
    {
        return max(35, (int) config('services.shipping_service.timeout', 35));
    }

    /** Ranked multi-partner quotes from the service. */
    public function quoteShipment(Shipment $shipment, string $mode, bool $cod, float $codAmount): array
    {
        $res = $this->request('post', '/v1/quotes', $this->buildRequest($shipment, $mode, $cod, $codAmount));
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service quote failed.'];
        }
        $d = (array) ($res['data'] ?? []);
        return [
            'ok'       => !empty($d['quotes']),
            'mode'     => $d['mode'] ?? $mode,
            'cod'      => $cod,
            // The AMOUNT, not just the boolean. This is the figure the courier is actually told to
            // collect for THIS leg — shipmentCodAmount() splits the order's payable proportionally
            // by each leg's goods value, so on a split order it is not the order total. The booking
            // modal previously had no way to know it and defaulted the field to a literal 0, and
            // the client could not recompute it without re-deriving that split.
            'cod_amount' => round($codAmount, 2),
            'quotes'   => $d['quotes'] ?? [],
            'cheapest' => $d['cheapest'] ?? null,
            // Our own malformed request, refused before any partner was asked (see quoteRaw).
            'error'    => $d['error'] ?? null,
            // Why partners are missing from `quotes` (mode/COD/credentials/switched off) and which
            // eligible ones errored. This whitelist used to STRIP both, so the service could
            // explain itself and the admin still showed a bare list.
            'ineligible' => $d['ineligible'] ?? [],
            'failed'     => $d['failed'] ?? [],
        ];
    }

    /**
     * Quote a PROSPECTIVE leg from a raw, already-built body (no persisted Shipment) — the
     * Delivery Optimizer's firm-quote entry point. Takes a TIGHT per-call timeout (ms) so a
     * slow service can't stall the hot path; the structured never-throws contract is preserved.
     */
    public function quoteRaw(array $body, ?int $timeoutMs = null): array
    {
        $timeout = $timeoutMs !== null ? max(0.05, $timeoutMs / 1000) : null;
        $res = $this->request('post', '/v1/quotes', $body, $timeout);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service quote failed.', 'quotes' => [], 'cheapest' => null];
        }
        $d = (array) ($res['data'] ?? []);
        return [
            'ok'       => !empty($d['quotes']),
            'mode'     => $d['mode'] ?? null,
            'quotes'   => $d['quotes'] ?? [],
            'cheapest' => $d['cheapest'] ?? null,
            // The service's own account of an empty list when the fault is OURS — a
            // malformed request refused before any partner was asked. It answers 200
            // with quotes:[] and this field set, so the transport-level check above
            // never sees it; dropping it here is what made a validation refusal look
            // like "no partner serves this route" and sent the last diagnosis chasing
            // vendor onboarding data.
            'error'    => $d['error'] ?? null,
            // Why partners are missing (mode/COD/credentials/switched off) and
            // which eligible ones errored. quoteShipment already passes these
            // through; stripping them here left every caller staring at an
            // empty list with no way to tell "no partner covers this route"
            // from "the request was wrong".
            'ineligible' => $d['ineligible'] ?? [],
            'failed'     => $d['failed'] ?? [],
        ];
    }

    /**
     * Quote a prospective courier leg for a shop → destination, with no order
     * and no Shipment behind it — the PDP "check delivery" entry point.
     *
     * Lives here rather than in the caller so leg-building stays in ONE place:
     * /v1/quotes validates full pickup/drop objects (name, phone, address,
     * city, state, pincode, lat, lng) plus the refs, and a hand-rolled minimal
     * body is silently rejected — which is exactly how the PDP ended up showing
     * a static estimate while the partner was answering fine.
     *
     * @param  array  $drop  at minimum ['pincode' => '110001']; lat/lng/city/state when known
     * @param  array  $item  optional single line {name, sku, qty, unit_price} — a
     *                       partner sizing the package from `items` returns nothing
     *                       for an empty one, so a representative line is sent.
     *
     * ⚠️ An answer of `quotes:[] ineligible:[] failed:[]` means the service refused
     * the request itself and asked NOBODY — read the `error` field, which now comes
     * through. The cause here was never the missing pickup location it looked like:
     * the service validated a quote as if it were a booking and demanded a contact
     * phone at each end, while a prospective quote has no customer yet and sends
     * `phone => ''` below by definition. Fixed in shipping-service
     * (ValidateQuoteRequest — coordinates only); pickup_location_name still matters
     * for BOOKING, just not for this.
     */
    public function quoteProspective($shop, array $drop, int $weightG, ?int $timeoutMs = 4000, array $item = []): array
    {
        if (!$shop || !$this->configured()) {
            return ['ok' => false, 'quotes' => [], 'cheapest' => null];
        }

        return $this->quoteRaw([
            'partner_code'    => '',
            // Prospective: no shipment/order exists yet. The refs are required
            // fields, so they carry a stable, obviously-synthetic token rather
            // than an id that would collide with a real shipment.
            'shipment_ref'    => 'quote-' . $shop->id . '-' . ($drop['pincode'] ?? ''),
            'order_ref'       => 'prospective',
            'shop_ref'        => (string) $shop->id,
            'mode'            => 'courier',
            'cod'             => false,
            'cod_amount'      => 0,
            'pickup'          => $this->addressFromShop($shop),
            'drop'            => [
                'name'    => 'Customer',
                'phone'   => '',
                'address' => (string) ($drop['address'] ?? ''),
                'city'    => (string) ($drop['city'] ?? ''),
                'state'   => (string) ($drop['state'] ?? ''),
                'pincode' => (string) ($drop['pincode'] ?? ''),
                'lat'     => (float) ($drop['lat'] ?? 0),
                'lng'     => (float) ($drop['lng'] ?? 0),
            ],
            'items'           => [[
                'name'       => (string) ($item['name'] ?? 'Plant'),
                'sku'        => (string) ($item['sku'] ?? 'QUOTE'),
                'qty'        => max(1, (int) ($item['qty'] ?? 1)),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'weight_g'   => max(1, $weightG),
            ]],
            'weight_g'        => max(1, $weightG),
            'pickup_location' => (string) ($shop->pickup_location_name ?? ''),
        ], $timeoutMs);
    }

    /**
     * Quote MANY prospective legs at once. Each $body must carry a 'ref' (idempotency token).
     * Prefers POST /v1/quotes/batch; until the service ships that route, falls back to an
     * Http::pool of parallel singles. Returns ['ok'=>bool, 'results'=>[{ref,ok,quotes,cheapest}]].
     *
     * Contract for the shipping team (PROVIDED BY shipping-service):
     *   POST /v1/quotes/batch  { quotes:[ { ref, mode, cod, cod_amount, pickup, drop, weight_g, items } ] }
     *     -> { results:[ { ref, ok, quotes:[...], cheapest } ] }
     */
    public function quoteBatch(array $bodies, ?int $timeoutMs = null): array
    {
        if (empty($bodies) || !$this->configured()) {
            return ['ok' => false, 'results' => []];
        }
        $timeout = $timeoutMs !== null ? max(0.05, $timeoutMs / 1000) : (float) $this->defaultTimeout();

        // Prefer the bulk endpoint when the service exposes it.
        $batch = $this->request('post', '/v1/quotes/batch', ['quotes' => array_values($bodies)], $timeout);
        if (!empty($batch['ok'])) {
            $d = (array) ($batch['data'] ?? []);
            if (isset($d['results']) && is_array($d['results'])) {
                return ['ok' => true, 'results' => $this->normalizeResults($d['results'])];
            }
        }

        // Fallback: parallel singles.
        return $this->poolQuotes(array_values($bodies), $timeout);
    }

    private function normalizeResults(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $quotes = $row['quotes'] ?? [];
            $out[] = [
                'ref'      => (string) ($row['ref'] ?? ''),
                'ok'       => array_key_exists('ok', $row) ? (bool) $row['ok'] : !empty($quotes),
                'mode'     => $row['mode'] ?? null,
                'quotes'   => $quotes,
                'cheapest' => $row['cheapest'] ?? null,
            ];
        }
        return $out;
    }

    private function poolQuotes(array $bodies, float $timeout): array
    {
        try {
            $responses = Http::pool(function (Pool $pool) use ($bodies, $timeout) {
                $reqs = [];
                foreach ($bodies as $i => $body) {
                    $payload = $body;
                    unset($payload['ref']);
                    $reqs[] = $pool->as((string) $i)
                        ->withOptions(['timeout' => $timeout, 'connect_timeout' => $timeout]) // sub-second float; avoid timeout(int) truncation
                        ->withHeaders(['X-Api-Key' => $this->apiKey])
                        ->acceptJson()
                        ->post($this->baseUrl . '/v1/quotes', $payload);
                }
                return $reqs;
            });
        } catch (\Throwable $e) {
            Log::warning('Shipping service batch pool failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'results' => []];
        }

        $results = [];
        foreach ($bodies as $i => $body) {
            $ref = (string) ($body['ref'] ?? $i);
            $resp = $responses[(string) $i] ?? null;
            if (!is_object($resp) || $resp instanceof \Throwable) {
                $results[] = ['ref' => $ref, 'ok' => false, 'quotes' => [], 'cheapest' => null];
                continue;
            }
            try {
                $ok = $resp->successful();
                $d = (array) $resp->json();
            } catch (\Throwable $e) {
                $ok = false;
                $d = [];
            }
            $quotes = $d['quotes'] ?? [];
            $results[] = [
                'ref'      => $ref,
                'ok'       => $ok && !empty($quotes),
                'mode'     => $d['mode'] ?? null,
                'quotes'   => $quotes,
                'cheapest' => $d['cheapest'] ?? null,
            ];
        }
        return ['ok' => true, 'results' => $results];
    }

    /**
     * Book via the service (idempotent on shipment_ref) + persist the returned provider fields.
     *
     * $partnerCode books a SPECIFIC partner instead of letting the service route by
     * mode/price. The service still validates it against the same candidacy path, so an
     * override cannot bypass the runtime master switch or mode/COD eligibility — an
     * ineligible code comes back as "partner not available: X" rather than booking.
     */
    /** The door buildRequest resolved for the request currently being sent. See book(). */
    private ?int $resolvedPickupLocationId = null;

    /**
     * Append this booking ATTEMPT to the partner-order ledger — success or failure.
     *
     * The shipments row holds only the CURRENT attempt (one row, reused, provider_order_id
     * overwritten), so without this a Porter refusal followed by a Borzo success left no
     * trace of Porter at all: the failure path never reached this table, and the reconcile
     * sweep that mirrors bookings into it filters on a non-null provider_order_id, which a
     * failed attempt does not have.
     *
     * Never allowed to break a booking — a missing audit row is not worth a 500 on a parcel
     * that is otherwise fine, and this table lags behind on freshly-migrated boxes.
     */
    private function recordAttempt(
        Shipment $shipment,
        ?string $partnerCode,
        string $mode,
        array $request,
        array $res,
        ?array $data
    ): void {
        try {
            if (!Schema::hasTable('partner_console_orders')) {
                return;
            }

            $partner = (string) ($data['partner'] ?? $partnerCode ?? $shipment->provider ?? 'unknown');
            $providerOrderId = $data ? (($data['provider_order_id'] ?? '') ?: null) : null;

            $attemptNo = 1 + (int) PartnerConsoleOrder::where('shipment_id', $shipment->id)->count();

            $payload = [
                'origin'      => 'shipment',
                'shipment_id' => $shipment->id,
                'order_id'    => $shipment->order_id,
                'mode'        => $mode,
                'attempt_no'  => $attemptNo,
                'request'     => $request,
                'response'    => $res['data'] ?? $res,
            ];

            if ($providerOrderId === null) {
                // create(), NOT updateOrCreate(): the unique index is
                // (partner_code, provider_order_id) and matching on a NULL id turns into
                // whereNull — every failed attempt for a partner would collapse onto one
                // row and the history this table exists for would be a single overwrite.
                PartnerConsoleOrder::create($payload + [
                    'partner_code'    => $partner,
                    'last_status'     => 'book_failed',
                    // NO partner_status: that column carries the PARTNER's own vocabulary
                    // (PartnerOrderLifecycle::RANK) and 'failed' is not in it. A refused
                    // attempt never reached a partner lifecycle at all, and a NULL
                    // partner_status alongside a NULL provider_order_id is already how a
                    // failed attempt is identified.
                    'partner_status'  => null,
                    'last_error'      => $res['error'] ?? 'shipping service',
                    'last_error_at'   => Carbon::now(),
                ]);

                return;
            }

            // updateOrCreate on success: the per-minute reconcile sweep may already have
            // minted this row from the shipment.
            PartnerConsoleOrder::updateOrCreate(
                ['partner_code' => $partner, 'provider_order_id' => $providerOrderId],
                $payload + [
                    'provider_reference' => ($data['provider_reference'] ?? '') ?: null,
                    'last_status'        => ($data['last_status'] ?? '') ?: 'booked',
                    'tracking_url'       => ($data['tracking_url'] ?? '') ?: null,
                    'last_error'         => null,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('courier.attempt.record_failed', [
                'shipment_id' => $shipment->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function book(Shipment $shipment, string $mode, bool $cod, float $codAmount, ?string $partnerCode = null, ?int $courierId = null, array $options = []): array
    {
        $body = $this->buildRequest($shipment, $mode, $cod, $codAmount, $partnerCode, $courierId, $options);

        // CLAIM the row before dialling out. The seal (isLiveBooked) reads provider_order_id
        // and awb_number, neither of which exists until this call returns — so for the whole
        // partner round-trip the parcel reads as free and a concurrent merge or club would
        // happily delete it, leaving a real, billed pickup with no shipments row and a status
        // webhook that lands on nothing. Both branches below overwrite last_status, so the
        // claim never outlives the call; isLiveBooked also time-boxes it to 5 minutes in case
        // this process dies mid-flight.
        //
        // Guarded: these columns post-date some deployments (and some test schemas), and a
        // missing column must degrade to the old behaviour, not fail the booking.
        try {
            if (Schema::hasColumn('shipments', 'last_status') && Schema::hasColumn('shipments', 'last_status_at')) {
                Shipment::whereKey($shipment->id)->update([
                    'last_status'    => Shipment::BOOKING_IN_FLIGHT,
                    'last_status_at' => Carbon::now(),
                ]);
            }
        } catch (\Throwable $e) {
            // never block a booking on its own audit stamp
        }

        $res = $this->request('post', '/v1/shipments', $body);
        if (empty($res['ok'])) {
            $shipment->forceFill(['last_status' => 'book_failed', 'failure_reason' => $res['error'] ?? 'shipping service'])->save();
            // Structured, correlatable failure record: shipment id doubles as
            // shipment_ref on the Go-service/partner side. Never logs payloads
            // or credentials — the provider's error string only.
            Log::warning('courier.book.failed', [
                'order_id'    => $shipment->order_id,
                'shipment_id' => $shipment->id,
                'shop_id'     => $shipment->shop_id,
                'mode'        => $mode,
                'partner'     => $partnerCode,
                'http_status' => $res['status'] ?? null,
                'error'       => $res['error'] ?? null,
            ]);
            $this->recordAttempt($shipment, $partnerCode, $mode, $body, $res, null);

            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service book failed.'];
        }
        $b = (array) ($res['data'] ?? []);
        // Captured before the overwrite: the attempt this booking replaces. Without it a rebook
        // is indistinguishable from a first booking in the logs.
        $previousBookingId = $shipment->provider_order_id;
        $previousFlow      = $shipment->simulation_flow_type;
        $previousStatus    = $shipment->last_status;
        $shipment->forceFill([
            'provider'             => $b['partner'] ?? null,
            'mode'                 => $b['mode'] ?? $mode,
            'provider_order_id'    => ($b['provider_order_id'] ?? '') ?: null,
            // The partner's OWN order number when it differs from the API key — Borzo's cabinet
            // prints order_name (30321) while every call takes order_id (330321). Showing only
            // the latter left the operator with a number that appears nowhere in Borzo's UI.
            'provider_reference'   => ($b['provider_reference'] ?? '') ?: null,
            // Freeze the door this leg actually left from. Taken from the body we JUST SENT
            // rather than re-resolving, so the record cannot drift from the request even if the
            // vendor edits their address a second later. `pickup_location_id` gets its first
            // production writer here — it has been a read-only column since it was added.
            'pickup_location_id'   => $this->resolvedPickupLocationId,
            'pickup_snapshot'      => ($body['pickup'] ?? null) ? array_filter([
                'location_name' => $body['pickup_location'] ?? null,
                'address'       => $body['pickup'],
                'partner'       => $b['partner'] ?? $partnerCode,
                'booked_at'     => Carbon::now()->toIso8601String(),
            ], static fn ($v) => $v !== null && $v !== '') : null,
            'provider_shipment_id' => ($b['provider_shipment_id'] ?? '') ?: null,
            'awb_number'           => ($b['awb_number'] ?? '') ?: null,
            'courier_name'         => ($b['courier_name'] ?? '') ?: null,
            'tracking_url'         => ($b['tracking_url'] ?? '') ?: null,
            'payment_method'       => $b['payment_method'] ?? ($cod ? 'cod' : 'prepaid'),
            'cod_amount'           => $cod ? $codAmount : ($shipment->cod_amount),
            // What the booking actually cost (the service returns booked_cost since ec810b2;
            // 'cost' is the raw partner Booking shape) — feeds the admin display + margin report.
            'booked_cost'          => $b['booked_cost'] ?? $b['cost'] ?? null,
            'status'               => ($b['status'] ?? '') ?: 'assigned',
            // Take the service's verdict, not an assumption of success. A Shiprocket order can be
            // CREATED while the waybill is refused — the service reports last_status='awb_pending'
            // with the partner's own reason ("KYC verification is mandated for your account…") —
            // and hard-coding 'booked' here erased both. That is why every paperwork button failed
            // with nothing to explain it: the reason was computed, sent, and then overwritten.
            'last_status'          => ($b['last_status'] ?? '') ?: 'booked',
            'last_status_at'       => Carbon::now(),
            // A rebook-after-cancel must not leave a zombie row that reads both booked AND cancelled.
            'cancelled_at'         => null,
            'cancelled_reason'     => null,
            // One shipments row is REUSED across every booking attempt — only provider_order_id
            // changes. So anything describing "the current booking" has to be cleared here or it
            // silently outlives the booking it belonged to. The simulation stamp did exactly that:
            // a rebook landed a new CRN on a row still stamped with the previous attempt's flow,
            // and the admin then rendered that dead flow as running against the new booking
            // (dropdown locked, tracking frozen). Per-attempt history lives in
            // partner_console_orders; these two columns are only a cache of the CURRENT attempt.
            'simulation_flow_type'   => null,
            'simulation_started_at'  => null,
            // Still cleared on a genuinely clean book — stale text outlived the problem and lied
            // (a corrected pin booked fine while the row still read "restricted_location" from the
            // attempt before). But when the service DOES report a reason, that reason is current
            // and is the only thing telling the operator why the parcel is stuck.
            'failure_reason'       => ($b['failure_reason'] ?? '') ?: null,
        ])->save();

        $this->recordAttempt($shipment, $partnerCode, $mode, $body, $res, $b);

        Log::info('courier.booking.opened', [
            'order_id'            => $shipment->order_id,
            'shipment_id'         => $shipment->id,
            'provider'            => $shipment->provider,
            'provider_order_id'   => $shipment->provider_order_id,
            'previous_booking_id' => $previousBookingId,
            'previous_flow_id'    => $previousFlow,
            'previous_status'     => $previousStatus,
            'provider_status'     => $shipment->status,
            'normalized_status'   => $shipment->last_status,
            'mode'                => $shipment->mode,
            'event_type'          => $previousBookingId ? 'rebooked' : 'booked',
        ]);

        // Activity log — one seam covers manual dispatch AND the auto-book listener.
        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.booked', [
            'shipment_id'       => $shipment->id,
            'partner'           => $shipment->provider,
            'provider_order_id' => $shipment->provider_order_id,
            'awb_number'        => $shipment->awb_number,
            'mode'              => $shipment->mode,
        ]);

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    /** Cancel the partner ORDER (Shiprocket orders/cancel). See cancelAwb() for the AWB-only cancel. */
    public function cancel(Shipment $shipment, ?string $reason): array
    {
        $res = $this->request('post', $this->shipmentPath($shipment, '/cancel'), ['reason' => $reason]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service cancel failed.'];
        }
        $this->stampCancelled($shipment, $reason, 'order');

        return ['ok' => true];
    }

    /**
     * Cancel the SHIPMENT/AWB only (Shiprocket orders/cancel/shipment/awbs) — the parcel stops
     * moving while the partner order survives, which is what a reassignment or a re-pack needs.
     * Cancelling the order instead would strand the manifest and force a fresh create.
     */
    /**
     * Create an exchange: a replacement shipped out while the original is collected back.
     *
     * ONE call at the partner producing TWO orders, so this returns both legs. Anything that
     * records only one has lost half the exchange — the buyer is expecting a collection AND a
     * delivery, and they are tracked separately from here.
     *
     * The seller location is Shiprocket's numeric id, taken from the registered pickup row. A
     * door that was registered before that id was captured cannot serve an exchange; the caller
     * is told which vendor to re-register rather than being handed the partner's field error.
     */
    public function createExchange(Shipment $shipment, array $payload): array
    {
        $d = $this->unwrap($this->request('post', $this->shipmentPath($shipment, '/exchange'), $payload), 'Exchange creation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        $ex = (array) ($d['exchange'] ?? []);
        $forward = (array) ($ex['forward'] ?? []);
        $return = (array) ($ex['return'] ?? []);

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.exchange_created', [
            'shipment_id'         => $shipment->id,
            'forward_order_id'    => $forward['provider_order_id'] ?? null,
            'forward_shipment_id' => $forward['provider_shipment_id'] ?? null,
            'return_order_id'     => $return['provider_order_id'] ?? null,
            'return_shipment_id'  => $return['provider_shipment_id'] ?? null,
        ]);

        return ['ok' => true, 'exchange' => $ex];
    }

    /**
     * Allocate the waybill for a booking that has an order but none.
     *
     * The 10-minute reconcile already retries this, but only it could: a shipment sitting on
     * awb_pending — the state that most often means an account condition rather than a delay —
     * gave the operator nothing to press. Shiprocket answers a refusal with HTTP 200, an empty
     * awb_code and the reason in awb_assign_error, so the error text IS the actionable content
     * and is surfaced verbatim.
     */
    public function assignAwb(Shipment $shipment): array
    {
        $d = $this->unwrap($this->request('post', $this->shipmentPath($shipment, '/assign-awb'), []), 'Waybill allocation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        $b = (array) ($d['booking'] ?? []);
        $shipment->forceFill(array_filter([
            'awb_number'     => ($b['awb_number'] ?? '') ?: null,
            'courier_name'   => ($b['courier_name'] ?? '') ?: null,
            'tracking_url'   => ($b['tracking_url'] ?? '') ?: null,
            'last_status'    => 'booked',
            'last_status_at' => Carbon::now(),
        ], static fn ($v) => $v !== null))->save();

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.awb_assigned', [
            'shipment_id' => $shipment->id,
            'awb_number'  => $shipment->fresh()->awb_number,
        ]);

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    public function cancelAwb(Shipment $shipment, ?string $reason = null): array
    {
        // Was posting to /cancel — the SAME path as cancel() — so everything the docblock above
        // promises was unreachable: the booking ended either way and a courier change meant
        // recreating the order. /cancel-awb voids only the waybill and returns the shipment to
        // awb_pending with the partner order intact.
        $res = $this->request('post', $this->shipmentPath($shipment, '/cancel-awb'), ['reason' => $reason]);
        $d = $this->unwrap($res, 'AWB cancellation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        // Deliberately NOT stampCancelled(): the shipment is not cancelled, it is awaiting a new
        // waybill. Stamping it cancelled is what made this indistinguishable from cancel().
        $shipment->forceFill([
            'awb_number'     => null,
            'last_status'    => 'awb_pending',
            'last_status_at' => Carbon::now(),
        ])->save();

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.awb_voided', [
            'shipment_id' => $shipment->id,
            'reason'      => $reason,
        ]);

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    /**
     * Mint (or fetch the stored) shipping label for a booked shipment, persisting label_url.
     * The service is idempotent — a second call returns the stored URL without a partner call —
     * so retry-after-timeout is safe.
     */
    public function generateLabel(Shipment $shipment): array
    {
        $d = $this->unwrap($this->request('post', $this->shipmentPath($shipment, '/label')), 'Label generation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        // The column has existed since 2026_06_23 and was written by nothing until now.
        $url = $this->persistDoc($shipment, 'label_url', $d['label_url'] ?? null);
        return ['ok' => true, 'label_url' => $url, 'shipment' => $shipment->fresh()];
    }

    /**
     * Mint the partner INVOICE (Shiprocket orders/print/invoice — which takes ORDER ids, not
     * shipment ids; the service owns that translation). invoice_url had a column and no writer.
     */
    public function generateInvoice(Shipment $shipment): array
    {
        $d = $this->unwrap($this->request('post', $this->shipmentPath($shipment, '/invoice')), 'Invoice generation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        $url = $this->persistDoc($shipment, 'invoice_url', $d['invoice_url'] ?? null);
        return ['ok' => true, 'invoice_url' => $url, 'shipment' => $shipment->fresh()];
    }

    /**
     * Generate + print the manifest for ONE shipment (manifests/generate then manifests/print).
     * manifest_url has had a column since June and no writer at all.
     */
    public function generateManifest(Shipment $shipment): array
    {
        $d = $this->unwrap($this->request('post', '/v1/shipments/manifest', ['refs' => [(string) $shipment->id]]), 'Manifest generation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        $url = $this->persistDoc($shipment, 'manifest_url', $d['manifest_url'] ?? null);
        return ['ok' => true, 'manifest_url' => $url, 'shipment' => $shipment->fresh()];
    }

    /**
     * ONE manifest across many shipments — how a handover actually happens: the driver signs a
     * single sheet for the whole pickup, not one per parcel. The partner returns one URL, so every
     * row in the batch stores the same one.
     *
     * Contract (PROVIDED BY shipping-service):
     *   POST /v1/manifests { shipment_refs: ["12","13"] } -> { ok, manifest_url, error? }
     *
     * @param  \Illuminate\Support\Collection|Shipment[]  $shipments
     */
    public function generateManifestBulk($shipments): array
    {
        $refs = [];
        foreach ($shipments as $s) {
            $refs[] = (string) $s->id;
        }
        if (!$refs) {
            return ['ok' => false, 'error' => 'No shipments given.'];
        }

        $d = $this->unwrap($this->request('post', '/v1/shipments/manifest', ['refs' => $refs]), 'Manifest generation failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        $url = (string) ($d['manifest_url'] ?? '');
        foreach ($shipments as $s) {
            $this->persistDoc($s, 'manifest_url', $url);
        }
        return ['ok' => true, 'manifest_url' => $url, 'shipment_refs' => $refs];
    }

    /**
     * Schedule the courier pickup for a booked shipment AND record it. The result used to be
     * returned and dropped on the floor, so nothing could tell an operator when the courier is
     * actually coming, and a re-schedule was indistinguishable from a first one.
     */
    public function schedulePickup(Shipment $shipment): array
    {
        $d = $this->unwrap($this->request('post', $this->shipmentPath($shipment, '/pickup')), 'Pickup scheduling failed.');
        if (empty($d['ok'])) {
            return $d;
        }
        $p = (array) ($d['pickup'] ?? []);
        // The service normalizes Shiprocket's response.pickup_scheduled_date to `scheduled_date`
        // (PickupResult in internal/partner/types.go). That key was missing from this read, so we
        // matched neither spelling and pickup_scheduled_at was written NULL on every successful
        // pickup — which is why the button never flipped to "Re-schedule" and no date ever showed.
        // `scheduled_date` first; the other two stay as tolerated aliases.
        $fill = array_filter([
            'pickup_scheduled_at' => $this->time($p['scheduled_date'] ?? $p['scheduled_at'] ?? $p['pickup_scheduled_date'] ?? null),
            'pickup_token'        => trim((string) ($p['pickup_token_number'] ?? $p['token'] ?? '')) ?: null,
        ], static fn ($v) => $v !== null);
        if ($fill) {
            $shipment->forceFill($fill + ['last_status_at' => Carbon::now()])->save();
        }
        return ['ok' => true, 'pickup' => $d['pickup'] ?? null, 'shipment' => $shipment->fresh()];
    }

    /**
     * Move a booked shipment onto a DIFFERENT courier. Shiprocket has no separate reassign
     * endpoint: assign/awb with status:"reassign" IS the mechanism, and it mints a NEW AWB — so
     * the label and manifest that name the old one are cleared here rather than left pointing at
     * a waybill the parcel no longer carries.
     *
     * Contract (PROVIDED BY shipping-service):
     *   POST /v1/shipments/{ref}/reassign { courier_id } -> { ok, awb_number, courier_name, courier_id, error? }
     */
    public function reassignCourier(Shipment $shipment, int $courierId): array
    {
        $previousAwb = (string) $shipment->awb_number;
        $d = $this->unwrap(
            $this->request('post', $this->shipmentPath($shipment, '/reassign'), ['courier_id' => $courierId]),
            'Courier reassignment failed.',
        );
        if (empty($d['ok'])) {
            return $d;
        }

        // The service nests the new booking under `shipment`. Reading these flat resolved every
        // one to '' -> null -> filtered out, so the row KEPT the dead waybill — exactly what
        // reassignment exists to replace.
        $b = (array) ($d['shipment'] ?? $d);
        $shipment->forceFill(array_filter([
            'awb_number'           => ($b['awb_number'] ?? '') ?: null,
            'courier_name'         => ($b['courier_name'] ?? '') ?: null,
            'provider_shipment_id' => ($b['provider_shipment_id'] ?? '') ?: null,
        ], static fn ($v) => $v !== null) + [
            'courier_company_id' => ((int) ($b['courier_id'] ?? $courierId)) ?: null,
            // The old waybill's paperwork is void.
            'label_url'          => null,
            'manifest_url'       => null,
            'last_status'        => 'reassigned',
            'last_status_at'     => Carbon::now(),
        ])->save();

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.reassigned', [
            'shipment_id'  => $shipment->id,
            'courier_id'   => (int) ($d['courier_id'] ?? $courierId),
            'courier_name' => $shipment->courier_name,
            'from_awb'     => $previousAwb ?: null,
            'to_awb'       => $shipment->awb_number,
        ]);

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    // ── NDR (non-delivery report) ─────────────────────────────────
    // A failed delivery attempt. Shiprocket auto-RTOs after 3, so this is a countdown, not a log:
    // until now a bare "Undelivered" mapped to nothing and the parcel bounced with nobody told.

    /** Open NDRs at the partner, newest first. $query is forwarded as-is (paging is the service's job). */
    public function ndrList(array $query = []): array
    {
        return $this->request('get', '/v1/partners/shiprocket/ndr', [], null, $query);
    }

    /** One NDR by waybill. $shipment (when we hold the row for that AWB) gets the counters synced. */
    public function ndrDetail(string $awb, ?Shipment $shipment = null): array
    {
        $res = $this->request('get', '/v1/partners/shiprocket/ndr/' . rawurlencode($awb));
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'NDR lookup failed.'];
        }
        $d = (array) ($res['data'] ?? []);
        if ($shipment) {
            $this->syncNdr($shipment, $d);
        }
        return ['ok' => true, 'ndr' => $d['ndr'] ?? $d, 'shipment' => $shipment?->fresh()];
    }

    /**
     * Submit an NDR action (reattempt / return / fake-attempt …). The action vocabulary belongs to
     * the partner, so it is forwarded verbatim rather than mirrored in a PHP allowlist that drifts.
     */
    public function ndrAction(Shipment $shipment, string $action, ?string $comments = null): array
    {
        $awb = (string) $shipment->awb_number;
        $d = $this->unwrap(
            $this->request('post', '/v1/partners/shiprocket/ndr/' . rawurlencode($awb) . '/action', ['action' => $action, 'comments' => $comments]),
            'NDR action failed.',
        );
        if (empty($d['ok'])) {
            return $d;
        }
        $this->syncNdr($shipment, $d, Carbon::now());

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.ndr_action', [
            'shipment_id' => $shipment->id,
            'awb'         => $awb,
            'action'      => $action,
            'comments'    => $comments,
        ]);

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    /**
     * Create a customer RETURN — a REVERSE shipment (pickup = the customer, drop = the warehouse).
     * Not an RTO: an RTO is the forward parcel bouncing on its own. It mints its own AWB and order
     * id, which land in return_* so the forward identifiers stay the audit trail of what shipped.
     *
     * Contract (PROVIDED BY shipping-service):
     *   POST /v1/partners/{code}/returns  <full ShipRequest> -> { ok, return: {...}, error? }
     */
    public function createReturn(Shipment $shipment, ?string $reason = null): array
    {
        $req = $this->buildRequest($shipment, 'courier', false, 0);
        // REVERSE the leg. buildRequest describes the FORWARD journey — pickup is the vendor door
        // and drop is the customer — and every other caller wants exactly that, so the swap lives
        // here rather than in the builder. Sending it unswapped told the courier to collect from
        // the vendor and deliver to the customer: a second forward shipment wearing the word
        // "return", which is what shipped before this line existed.
        [$req['pickup'], $req['drop']] = [$req['drop'], $req['pickup']];
        // A return leaves the customer's door, so the vendor's registered pickup nickname is not
        // the origin any more. Left in, Shiprocket would price the reverse leg from the warehouse.
        $req['pickup_location'] = '';

        // Ask the partner that CARRIED the parcel. This was hardcoded to shiprocket, so clicking
        // "Create return" on a Porter delivery posted a Shiprocket order for a shipment Shiprocket
        // had never heard of — the "shiprocket" heading on an error about a Porter job.
        $code = trim((string) $shipment->provider) ?: 'shiprocket';

        $d = $this->unwrap(
            $this->request('post', '/v1/partners/' . rawurlencode($code) . '/returns', $req + ['reason' => $reason]),
            'Return creation failed.',
        );
        if (empty($d['ok'])) {
            return $d;
        }
        // The service nests the new reverse booking under `return`, and it is a plain partner
        // Booking — so the reverse leg's identifiers are `awb_number` / `provider_order_id`, NOT
        // `return_*`. Reading them flat left the return AWB unpersisted while reporting success,
        // and reading `return_provider_order_id` off a key nobody sends left the id blank forever.
        $r = (array) ($d['return'] ?? $d);
        $shipment->forceFill([
            'return_awb'               => ($r['awb_number'] ?? $r['return_awb'] ?? '') ?: $shipment->return_awb,
            'return_provider_order_id' => ($r['provider_order_id'] ?? $r['return_provider_order_id'] ?? '') ?: $shipment->return_provider_order_id,
            'last_status_at'           => Carbon::now(),
        ])->save();

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.return_created', [
            'shipment_id'              => $shipment->id,
            'return_awb'               => $shipment->return_awb,
            'return_provider_order_id' => $shipment->return_provider_order_id,
            'reason'                   => $reason,
        ]);

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    public function track(Shipment $shipment): array
    {
        return $this->request('get', $this->shipmentPath($shipment, '/track'));
    }

    // ── persistence helpers ───────────────────────────────────────

    /** The service addresses a shipment by OUR id — that is its shipment_ref. */
    private function shipmentPath(Shipment $shipment, string $suffix = ''): string
    {
        return '/v1/shipments/' . rawurlencode((string) $shipment->id) . $suffix;
    }

    /**
     * Unwrap the service's {ok, …} envelope. A transport failure and an ok:false body are the same
     * thing to a caller — one failure carrying the partner's own reason — and collapsing them here
     * is what stops a partner refusal from being read as a success with empty fields.
     */
    private function unwrap(array $res, string $fallback): array
    {
        if (empty($res['ok']) || empty($res['data']['ok'])) {
            return ['ok' => false, 'error' => $res['data']['error'] ?? $res['error'] ?? $fallback];
        }
        return ['ok' => true] + (array) $res['data'];
    }

    /** Store a provider document URL; a repeat of the same URL writes nothing. Returns the URL. */
    private function persistDoc(Shipment $shipment, string $column, $url): string
    {
        $url = trim((string) $url);
        if ($url !== '' && $url !== (string) $shipment->{$column}) {
            $shipment->forceFill([$column => $url, 'last_status_at' => Carbon::now()])->save();
        }
        return $url;
    }

    /** NDR counters off a partner payload; only fields the partner actually reported are written. */
    private function syncNdr(Shipment $shipment, array $d, ?Carbon $actionedAt = null): void
    {
        $n = (array) ($d['ndr'] ?? $d);
        $fill = [];
        $reason = trim((string) ($n['reason'] ?? $n['ndr_reason'] ?? ''));
        if ($reason !== '') {
            $fill['ndr_reason'] = mb_substr($reason, 0, 255);
        }
        $attempts = $n['attempts'] ?? $n['ndr_attempts'] ?? null;
        if (is_numeric($attempts)) {
            $fill['ndr_attempts'] = max(0, min(255, (int) $attempts));
        }
        if ($actionedAt) {
            $fill['ndr_action_at'] = $actionedAt;
        }
        if ($fill) {
            $shipment->forceFill($fill)->save();
        }
    }

    /** Shared cancel bookkeeping. $scope records WHICH cancel it was: the order, or just the AWB. */
    private function stampCancelled(Shipment $shipment, ?string $reason, string $scope): void
    {
        $shipment->forceFill([
            'status'           => 'cancelled',
            'last_status'      => 'cancelled',
            'last_status_at'   => Carbon::now(),
            'cancelled_at'     => Carbon::now(),
            'cancelled_reason' => $reason,
            // Closing the booking closes its flow. Leaving the stamp behind kept a dead flow
            // reading as "running" — the operator saw a locked dropdown and a frozen tracker on
            // a booking that no longer exists. The attempt's own record survives in
            // partner_console_orders; nothing historical is destroyed here.
            'simulation_flow_type'  => null,
            'simulation_started_at' => null,
        ])->save();

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.cancelled', [
            'shipment_id' => $shipment->id,
            'reason'      => $reason,
            'scope'       => $scope,
        ]);

        Log::info('courier.booking.closed', [
            'order_id'          => $shipment->order_id,
            'shipment_id'       => $shipment->id,
            'provider'          => $shipment->provider,
            'provider_order_id' => $shipment->provider_order_id,
            'scope'             => $scope,
            'event_type'        => 'booking_cancelled',
        ]);
    }

    /** Partner timestamps arrive in whatever format the partner likes; an unparseable one is null. */
    private function time($value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read a partner's RUNTIME toggle config (master switch + per-API cost switches) — the admin
     * Courier UI reads this to render Porter's on/off controls. Credentials are env-only and never
     * returned by the service.
     */
    public function getPartnerConfig(string $code): array
    {
        return $this->request('get', '/v1/partners/' . rawurlencode($code) . '/config');
    }

    /**
     * Write a partner's RUNTIME toggle config. $payload carries only the booleans the admin changed
     * (partial merge on the service side): enabled / quote_enabled / track_enabled / cancel_enabled.
     */
    public function putPartnerConfig(string $code, array $payload): array
    {
        return $this->request('put', '/v1/partners/' . rawurlencode($code) . '/config', $payload);
    }

    /**
     * Force a partner holding an expiring session token (Shiprocket) to authenticate again.
     *
     * Doubles as the credential check: the service performs a real login, so a failure here means
     * the stored email/password do not work — answerable without placing an order to find out.
     * Partners that authenticate per-request with a static key report "nothing to refresh" rather
     * than an error.
     */
    public function refreshPartnerToken(string $code): array
    {
        return $this->request('post', '/v1/partners/' . rawurlencode($code) . '/token/refresh');
    }

    // ── partner debug console (super-admin) ───────────────────────
    // These back the admin's per-partner debug console. The service answers each test/* call with a
    // { ok, ..., exchange } envelope, where `exchange` is the EXACT upstream request/response with
    // auth headers already masked by the service — that is the whole feature: debug a partner
    // integration without server logs. Bodies are forwarded/returned as-is and never logged here.

    /**
     * Recent INBOUND partner webhooks recorded by the service, newest first. $query carries only the
     * paging keys the admin actually sent (limit, before); defaulting/clamping is the service's job.
     */
    public function getPartnerWebhooks(string $code, array $query = []): array
    {
        return $this->request('get', '/v1/partners/' . rawurlencode($code) . '/webhooks', [], null, $query);
    }

    /** Live rate-card call against the partner (paid API on some partners). Read-only. */
    public function partnerTestQuote(string $code, array $body): array
    {
        return $this->request('post', '/v1/partners/' . rawurlencode($code) . '/test/quote', $body);
    }

    /** Live status lookup for one provider order id (paid API on some partners). Read-only. */
    /**
     * Start Porter's UAT order-flow simulator for an existing CRN.
     *
     * The service refuses this outside a sandbox environment — deliberately NOT re-checked here, so
     * the rule has exactly one owner (same reason the destructive confirm phrase lives only there).
     */
    public function partnerSimulateFlow(string $code, string $providerOrderId, int $flowType): array
    {
        return $this->request(
            'post',
            '/v1/partners/' . rawurlencode($code) . '/test/simulate-flow',
            ['provider_order_id' => $providerOrderId, 'flow_type' => $flowType]
        );
    }

    public function partnerTestTrack(string $code, string $providerOrderId): array
    {
        return $this->request(
            'get',
            '/v1/partners/' . rawurlencode($code) . '/test/track',
            [],
            null,
            ['provider_order_id' => $providerOrderId]
        );
    }

    /**
     * CREATES A REAL DELIVERY. $body must carry the caller's own `confirm` string verbatim — the
     * guard is enforced by the SERVICE, deliberately not here, so no monolith-side bug can satisfy
     * it by accident.
     */
    public function partnerTestBook(string $code, array $body): array
    {
        return $this->request('post', '/v1/partners/' . rawurlencode($code) . '/test/book', $body);
    }

    /** AFFECTS A LIVE JOB. Same caller-supplied `confirm` contract as partnerTestBook(). */
    public function partnerTestCancel(string $code, array $body): array
    {
        return $this->request('post', '/v1/partners/' . rawurlencode($code) . '/test/cancel', $body);
    }

    // ── request building ──────────────────────────────────────────

    private function buildRequest(Shipment $shipment, string $mode, bool $cod, float $codAmount, ?string $partnerCode = null, ?int $courierId = null, array $options = []): array
    {
        $order = $shipment->order;
        $shop = $shipment->shop;
        $dims = $this->packageDims($shipment);
        $courier = new CourierService();
        // Which of the vendor's doors THIS leg leaves from: its own pickup_location_id, else the
        // vendor's default, else the legacy shops.pickup_location_name. Every leg used to be sent
        // from the shop-level nickname regardless of where the stock actually sits.
        $pickup = $courier->pickupLocationFor($shipment);
        // Remembered for the snapshot in book(). Resolving a second time there would be a second
        // read of a table an operator can edit between the two calls — the record must describe
        // the request that actually went out, not a re-derivation of it.
        $this->resolvedPickupLocationId = optional($pickup)->id;
        return [
            // Empty string, not null: the service treats "" as "no override" and
            // routes normally, whereas a null would have to be special-cased there.
            'partner_code'    => (string) ($partnerCode ?? ''),
            'shipment_ref'    => (string) $shipment->id,
            'order_ref'       => (string) ($order->tracking_number ?? $order->id ?? $shipment->id),
            'shop_ref'        => (string) $shipment->shop_id,
            'mode'            => $mode,
            'cod'             => $cod,
            'cod_amount'      => $cod ? round($codAmount, 2) : 0,
            'pickup'          => $this->pickupAddressOf($shop, $pickup),
            'drop'            => $this->addressFromOrder($order),
            'items'           => $this->items($shipment),
            'weight_g'        => $this->weightG($shipment),
            // Parcel dimensions (cm). Couriers price on volumetric weight, so
            // these are real inputs, not decoration — see packageDims().
            'length_cm'       => $dims['length'],
            'breadth_cm'      => $dims['breadth'],
            'height_cm'       => $dims['height'],
            // Goods value on the leg. Shiprocket's serviceability call prices some couriers off it
            // (and refuses others above their value cap), so a quote sent without it is not the
            // quote the booking gets billed at.
            'declared_value'  => $this->declaredValue($shipment),
            // The courier the operator picked off a quote, passed in BY THE DISPATCH THAT JUST
            // VALIDATED IT against a fresh quote (CourierService::chooseCourier). 0 = let the
            // partner route on today's rate card.
            //
            // It is deliberately NOT read from shipments.courier_company_id any more: that column
            // is persisted for display and never cleared, so every later call replayed it — an
            // auto-book or a rebook hours after the quote expired named a courier off a dead rate
            // card, and the partner either refused the AWB or allocated a differently-priced one.
            // The column is now an OUTPUT (what was chosen/allocated), never an input.
            'courier_id'      => max(0, (int) $courierId),
            'pickup_location' => $courier->pickupNameFor($shipment, $pickup),
            // The operator's choices from the booking wizard. Sent as an object even when empty:
            // the service always decodes it, and an absent key would make "no options" and "an
            // older admin that cannot send options" indistinguishable at the adapter.
            'options'         => (object) $options,
        ];
    }

    /**
     * Register a pickup location at Shiprocket (idempotent service-side). Coordinates ride along —
     * they're required for the hyperlocal lane (Shiprocket Quick) and harmless for standard
     * courier. $name defaults to the legacy shop-{id} nickname; $location is the vendor door whose
     * address should be registered. Returns {ok, outcome?, pickup_code?, error?}.
     */
    public function registerPickup($shop, ?string $name = null, $location = null): array
    {
        $a = $this->pickupAddressOf($shop, $location);
        $res = $this->request('post', '/v1/partners/shiprocket/register-pickup', [
            'name'    => $name ?: ('shop-' . $shop->id),
            'contact' => (string) ($a['name'] ?? 'PlantAtHome'),
            'phone'   => (string) ($a['phone'] ?? ''),
            'address' => (string) ($a['address'] ?? ''),
            'line2'   => (string) ($a['line2'] ?? ''),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($a['pincode'] ?? ''),
            'lat'     => (float) ($a['lat'] ?? 0),
            'lng'     => (float) ($a['lng'] ?? 0),
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service unreachable.'];
        }
        $d = (array) ($res['data'] ?? []);
        return [
            'ok'          => (bool) ($d['ok'] ?? false),
            'outcome'     => $d['outcome'] ?? null,
            // Shiprocket's own id for the location (addpickup → address.pickup_code); the service
            // only returns it on a fresh registration.
            'pickup_code' => ($d['pickup_code'] ?? null) ?: null,
            'error'       => $d['error'] ?? null,
        ];
    }

    /**
     * The exact pickup address a booking would send — exposed so callers can check it is complete
     * BEFORE registering or booking, rather than discovering it from a partner 422.
     *
     * A VendorPickupLocation overrides the shop record field by field; a blank field on the row
     * keeps the shop's value, so a partially-filled door never registers an emptier address than
     * the vendor already has.
     */
    /**
     * Pickup address line 1, with the house/flat/road number on the FRONT.
     *
     * Shiprocket refuses `addpickup` with
     *   {"address":["Address line 1 should have House no / Flat no / Road no."]}
     * and the vendor form collects `address.house_no` precisely for this — but the courier path
     * never used it, so a Google-autocomplete line ("Greater Kailash, New Delhi, Delhi, India")
     * went verbatim and every registration 422'd. Verified live against the real account.
     */
    private function pickupLine1(array $a): string
    {
        $street = trim((string) ($a['street_address'] ?? $a['address'] ?? ''));
        $house  = trim((string) ($a['house_no'] ?? $a['flat_no'] ?? $a['building'] ?? ''));

        if ($house === '' || $street === '') {
            return $street !== '' ? $street : $house;
        }
        // The form often already writes "E-512, Greater Kailash" — don't double the number.
        return stripos($street, $house) === 0 ? $street : $house . ', ' . $street;
    }

    public function pickupAddressOf($shop, $location = null): array
    {
        $a = $this->addressFromShop($shop);
        if (!$location) {
            return $a;
        }
        $over = array_filter([
            'name'    => (string) ($location->contact_name ?? ''),
            'phone'   => $this->digits($location->phone ?? ''),
            'address' => (string) ($location->address ?? ''),
            'line2'   => (string) ($location->address_2 ?? ''),
            'city'    => (string) ($location->city ?? ''),
            'state'   => (string) ($location->state ?? ''),
            'pincode' => (string) ($location->pincode ?? ''),
        ], static fn ($v) => trim((string) $v) !== '');
        // Coordinates only as a pair: half a fix is worse than the shop's own pin.
        if ((float) ($location->lat ?? 0) && (float) ($location->lng ?? 0)) {
            $over['lat'] = (float) $location->lat;
            $over['lng'] = (float) $location->lng;
        }
        return $over + $a;
    }

    private function addressFromShop($shop): array
    {
        $a = (array) ($shop->address ?? []);
        // The nursery onboarding LocationPicker saves coordinates to settings.location
        // {lat,lng}; the shops.lat/lng columns are the matching engine's copy. Read all
        // three shapes so a pickup never degrades to 0,0 for the same-city partners.
        $loc = (array) (is_array($shop->settings ?? null) ? ($shop->settings['location'] ?? []) : []);
        return [
            'name'    => (string) ($shop->name ?: 'Vendor'),
            // `shops.mobile` sits between the two originals ON PURPOSE. The hyperlocal lane rejects
            // a pickup with no phone outright (Go validate.go), and a vendor onboarded through the
            // admin form reliably HAS a phone — in `mobile`, which this chain never read. The form
            // has no `address.phone` field at all and `settings.contact` is optional, so a new
            // vendor booked or failed purely on whether someone happened to fill that optional box.
            // VendorResource treats `mobile ?? settings.contact` as the vendor phone of record
            // everywhere else; this path was reading only the fallback half of that expression.
            // `?:` not `??` at each step: a present-but-EMPTY value must fall through too. The
            // original `??` chain stopped at an empty-string address phone and never reached the
            // fallback at all. `?? ''` first so a missing key doesn't warn.
            //
            // `mobile` goes LAST, after settings.contact, even though it is the better-populated
            // column. Putting it first changed the payload of a vendor that already books: shop 34
            // sends 919996469046 from settings.contact today, and `mobile` holds the bare 10-digit
            // 9996469046 — the backfill stored substr(-10). Reordering would have silently swapped
            // a country-coded number for a bare one on a working booking, to fix a different
            // vendor. Appending instead is strictly additive: everyone who books today sends
            // exactly what they sent before, and only a vendor who would otherwise be REFUSED
            // gains a phone.
            'phone'   => $this->digits(($a['phone'] ?? '') ?: (($shop->settings['contact'] ?? '') ?: ($shop->mobile ?? ''))),
            'address' => $this->pickupLine1($a),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($shop->pickup_postcode ?? $a['zip'] ?? $a['pincode'] ?? ''),
            'lat'     => (float) ($a['lat'] ?? $a['latitude'] ?? $shop->lat ?? $loc['lat'] ?? 0),
            'lng'     => (float) ($a['lng'] ?? $a['longitude'] ?? $shop->lng ?? $loc['lng'] ?? 0),
        ] + $this->addressDetail($a);
    }

    /**
     * The optional address detail a rider needs to find the door: apartment/flat, a second street
     * line, and a landmark.
     *
     * Porter documents all three (apartment_address / street_address2 / landmark) and the shipping
     * service forwards them, but NOTHING here ever populated them — so on every real order the
     * rider got a street and a pin and nothing else, while the debug console could send them and
     * made it look as though the integration handled them. Checkout and vendor onboarding store
     * these under several historical key names, so all are read.
     *
     * Empty values are omitted entirely rather than sent blank: a partner that validates an empty
     * string differently from an absent key should see an absent key.
     */
    private function addressDetail(array $a): array
    {
        $pick = static function (array $src, array $keys): string {
            foreach ($keys as $k) {
                $v = trim((string) ($src[$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
            return '';
        };

        return array_filter([
            'apartment' => $pick($a, ['apartment', 'apartment_address', 'flat', 'flat_no', 'house_no']),
            'line2'     => $pick($a, ['line2', 'street_address2', 'address_line_2', 'area', 'locality']),
            'landmark'  => $pick($a, ['landmark', 'nearby', 'near']),
            // Access detail the courier is shown as its own labelled instruction rather than
            // buried in the address line. Borzo carries all of these as real point fields;
            // partners that do not simply ignore them. `building` no longer feeds `apartment` —
            // they are different facts and Borzo has a slot for each.
            'building'  => $pick($a, ['building', 'building_number', 'block']),
            'entrance'  => $pick($a, ['entrance', 'entrance_number', 'gate']),
            'floor'     => $pick($a, ['floor', 'floor_number']),
            'intercom'  => $pick($a, ['intercom', 'intercom_code', 'door_code']),
            'note'      => $pick($a, ['courier_note', 'delivery_note', 'note', 'instructions']),
        ], static fn ($v) => $v !== '');
    }

    private function addressFromOrder($order): array
    {
        $ship = (array) ($order->shipping_address ?? []);
        $a = (array) ($ship['address'] ?? $ship);
        // Checkout stores the customer's coordinates NESTED as shipping_address.location
        // {lat,lng} (GPS share > address map-pick > stored prompt) — read that first, then
        // any legacy flat keys. Same-city partners (Porter/Borzo) are lat/lng-driven, so a
        // missed read here would quote/book against 0,0.
        $loc = (array) ($a['location'] ?? $ship['location'] ?? []);
        $lat = (float) ($loc['lat'] ?? $a['lat'] ?? $a['latitude'] ?? 0);
        $lng = (float) ($loc['lng'] ?? $a['lng'] ?? $a['longitude'] ?? 0);

        // Fall back to geocoding the written address. Roughly 40% of real orders arrive with no
        // coordinates (the customer neither shared GPS nor map-picked), and the hyperlocal lane is
        // refused outright when either end is 0,0 — so Porter, Borzo AND Shiprocket Quick all
        // vanish from the options list and the operator is told the route is uncovered. The
        // address is on the order; we just were not reading it.
        if (!$lat || !$lng) {
            $geo = $this->geocodeDrop($a);
            if ($geo) {
                [$lat, $lng] = $geo;
            }
        }

        return [
            'name'    => (string) ($order->customer_name ?? $a['name'] ?? 'Customer'),
            'phone'   => $this->digits($order->customer_contact ?? ($a['phone'] ?? '')),
            'address' => (string) ($a['street_address'] ?? $a['address'] ?? ''),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? ''),
            'lat'     => $lat,
            'lng'     => $lng,
        ] + $this->addressDetail($a);
    }

    /**
     * Geocode a drop address that arrived without coordinates, cached by address so a quote fan-out
     * across four partners costs one Google call rather than four. Returns [lat, lng] or null.
     *
     * Deliberately fail-soft: geocoding is a best-effort improvement on a missing value, so a
     * missing API key, a timeout, or a no-result answer must leave the address exactly as it was
     * rather than break quoting.
     */
    private function geocodeDrop(array $a): ?array
    {
        $key = 'ship:geo:' . sha1(json_encode([
            $a['street_address'] ?? $a['address'] ?? '',
            $a['city'] ?? '',
            $a['state'] ?? '',
            $a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? '',
        ]));

        try {
            $hit = Cache::remember($key, now()->addDays(30), function () use ($a) {
                $geo = app(\Marvel\Services\GeoMatchService::class)->geocode([
                    'street_address' => $a['street_address'] ?? $a['address'] ?? null,
                    'city'           => $a['city'] ?? null,
                    'state'          => $a['state'] ?? null,
                    'zip'            => $a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? null,
                    'country'        => $a['country'] ?? 'India',
                ]);
                // Cache the miss too, as a sentinel — an unmappable address should not be retried
                // on every single quote.
                return $geo ?: ['miss' => true];
            });
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($hit) || !empty($hit['miss']) || empty($hit['lat']) || empty($hit['lng'])) {
            return null;
        }
        return [(float) $hit['lat'], (float) $hit['lng']];
    }

    private function items(Shipment $shipment): array
    {
        $out = [];
        foreach ($shipment->items as $it) {
            $p = $it->product ?? null;
            $line = [
                'name'       => (string) ($p->name ?? ('Item #' . $it->product_id)),
                'sku'        => (string) ($p->sku ?? ('SKU-' . $it->product_id)),
                // shipped_qty, NOT order_quantity: a line split 3 + 2 rides two parcels, and
                // the ordered figure describes the whole line on both of them.
                'qty'        => $it->shipped_qty,
                'unit_price' => (float) ($it->unit_price ?? 0),
                'weight_g'   => (int) ($p->weight ?? 0),
            ];
            // HSN and per-line tax reach the courier's tax invoice when the catalogue knows them.
            // It does not yet — there is no hsn/tax column on products — so these are absent on
            // every order today and the courier applies its own defaults. Read defensively rather
            // than left out entirely: the moment the columns exist this starts working with no
            // further change, and until then it costs one isset() per line.
            // getAttributes(), NOT $p->hsn: Product is kodeine Metable, so reading an attribute
            // that is not a column falls through to a products_meta lookup — a query per line per
            // booking, and an outright failure anywhere that table is absent. The raw column bag
            // answers the only question being asked: does the catalogue carry this yet?
            $cols = $p ? $p->getAttributes() : [];
            foreach (['hsn', 'tax_rate'] as $key) {
                $v = $cols[$key] ?? null;
                if ($v !== null && $v !== '') {
                    $line[$key] = $key === 'hsn' ? (string) $v : (float) $v;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Parcel weight in grams. An operator override on the shipment wins — they
     * can see the actual parcel; the per-product sum (with a 500 g/unit
     * fallback) is only an estimate.
     */
    private function weightG(Shipment $shipment): int
    {
        $override = (int) ($shipment->weight_g ?? 0);
        if ($override > 0) {
            return $override;
        }
        $g = 0;
        foreach ($shipment->items as $it) {
            $p = $it->product ?? null;
            $w = $p && (int) ($p->weight ?? 0) > 0 ? (int) $p->weight : 500;
            $g += $w * $it->shipped_qty;
        }
        return max(1, $g);
    }

    /**
     * Goods value on this leg — THIS parcel's share of each line, not the whole line.
     * shipped_subtotal prorates the stored subtotal by the allocated units (and falls
     * back to unit price × allocated units when no subtotal was stored), so a split
     * line does not declare, and insure, its full value on both parcels.
     */
    private function declaredValue(Shipment $shipment): float
    {
        $v = 0.0;
        foreach ($shipment->items as $it) {
            $v += $it->shipped_subtotal;
        }
        return round($v, 2);
    }

    /**
     * Parcel dimensions in cm, most-specific source first:
     *   shipment override → largest product dims on the leg → the admin's
     *   courier default_package → 20x15x15.
     *
     * Every layer already existed but none was wired: products.length/breadth/
     * height had no reader, CourierService::defaultPackage() had no caller, and
     * the partner adapter carried hardcoded literals. Volumetric weight can cost
     * more than actual weight, so this is a money path, not cosmetics.
     */
    private function packageDims(Shipment $shipment): array
    {
        $override = [
            'length'  => (float) ($shipment->length_cm ?? 0),
            'breadth' => (float) ($shipment->breadth_cm ?? 0),
            'height'  => (float) ($shipment->height_cm ?? 0),
        ];
        if ($override['length'] > 0 && $override['breadth'] > 0 && $override['height'] > 0) {
            return $override;
        }

        // Largest per-dimension across the leg's products — a box that fits the
        // biggest item in each axis. (Summing would model a stack we don't know
        // how the packer builds.)
        $fromProducts = ['length' => 0.0, 'breadth' => 0.0, 'height' => 0.0];
        foreach ($shipment->items as $it) {
            $p = $it->product ?? null;
            if (!$p) {
                continue;
            }
            $fromProducts['length']  = max($fromProducts['length'], (float) ($p->length ?? 0));
            $fromProducts['breadth'] = max($fromProducts['breadth'], (float) ($p->breadth ?? 0));
            $fromProducts['height']  = max($fromProducts['height'], (float) ($p->height ?? 0));
        }
        if ($fromProducts['length'] > 0 && $fromProducts['breadth'] > 0 && $fromProducts['height'] > 0) {
            return $fromProducts;
        }

        $default = (new CourierService())->defaultPackage();
        return [
            'length'  => (float) ($default['length'] ?? 20),
            'breadth' => (float) ($default['breadth'] ?? 15),
            'height'  => (float) ($default['height'] ?? 15),
        ];
    }

    private function digits($s): string
    {
        return preg_replace('/\D+/', '', (string) $s) ?: '';
    }

    /** $query is GET-only (POST/PUT carry $body); an empty $query keeps the original bare get(). */
    private function request(string $method, string $path, array $body = [], ?float $timeoutSeconds = null, array $query = []): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Shipping service is not configured.'];
        }
        try {
            // Booking (no timeout passed) keeps the existing int timeout() path byte-for-byte.
            // The optimizer passes a tight sub-second float; Laravel 10's timeout(int) would
            // TRUNCATE 0.12 -> 0 (curl reads 0 as "no timeout / wait forever"), defeating the
            // whole degrade-to-estimate design — so set the raw Guzzle options directly.
            if ($timeoutSeconds !== null) {
                $http = Http::withOptions(['timeout' => $timeoutSeconds, 'connect_timeout' => $timeoutSeconds])
                    ->withHeaders(['X-Api-Key' => $this->apiKey]) // shared secret — header only, never logged
                    ->acceptJson();
            } else {
                $http = Http::timeout($this->defaultTimeout())
                    ->withHeaders(['X-Api-Key' => $this->apiKey])
                    ->acceptJson();
            }
            $resp = match ($method) {
                'get'   => empty($query)
                    ? $http->get($this->baseUrl . $path)
                    : $http->get($this->baseUrl . $path, $query),
                'put'   => $http->put($this->baseUrl . $path, $body),
                default => $http->post($this->baseUrl . $path, $body),
            };
        } catch (\Throwable $e) {
            Log::warning('Shipping service request failed', ['path' => $path, 'error' => $e->getMessage()]); // no secret
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Network error contacting shipping service.'];
        }
        return [
            'ok'     => $resp->successful(),
            'status' => $resp->status(),
            'data'   => $resp->json(),
            'error'  => $resp->successful() ? null : ($resp->json('error') ?? 'Shipping service error (' . $resp->status() . ').'),
        ];
    }
}
