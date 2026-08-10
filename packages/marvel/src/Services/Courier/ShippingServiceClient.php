<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $timeout = $timeoutMs !== null ? max(0.05, $timeoutMs / 1000) : (float) config('services.shipping_service.timeout', 25);

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
    public function book(Shipment $shipment, string $mode, bool $cod, float $codAmount, ?string $partnerCode = null): array
    {
        $res = $this->request('post', '/v1/shipments', $this->buildRequest($shipment, $mode, $cod, $codAmount, $partnerCode));
        if (empty($res['ok'])) {
            $shipment->forceFill(['last_status' => 'book_failed', 'failure_reason' => $res['error'] ?? 'shipping service'])->save();
            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service book failed.'];
        }
        $b = (array) ($res['data'] ?? []);
        $shipment->forceFill([
            'provider'             => $b['partner'] ?? null,
            'mode'                 => $b['mode'] ?? $mode,
            'provider_order_id'    => ($b['provider_order_id'] ?? '') ?: null,
            'provider_shipment_id' => ($b['provider_shipment_id'] ?? '') ?: null,
            'awb_number'           => ($b['awb_number'] ?? '') ?: null,
            'courier_name'         => ($b['courier_name'] ?? '') ?: null,
            'tracking_url'         => ($b['tracking_url'] ?? '') ?: null,
            'payment_method'       => $b['payment_method'] ?? ($cod ? 'cod' : 'prepaid'),
            'cod_amount'           => $cod ? $codAmount : ($shipment->cod_amount),
            'status'               => ($b['status'] ?? '') ?: 'assigned',
            'last_status'          => 'booked',
            'last_status_at'       => Carbon::now(),
        ])->save();

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

    public function cancel(Shipment $shipment, ?string $reason): array
    {
        $res = $this->request('post', '/v1/shipments/' . rawurlencode((string) $shipment->id) . '/cancel', ['reason' => $reason]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shipping service cancel failed.'];
        }
        $shipment->forceFill([
            'status'           => 'cancelled',
            'last_status'      => 'cancelled',
            'last_status_at'   => Carbon::now(),
            'cancelled_at'     => Carbon::now(),
            'cancelled_reason' => $reason,
        ])->save();

        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.cancelled', [
            'shipment_id' => $shipment->id,
            'reason'      => $reason,
        ]);

        return ['ok' => true];
    }

    /**
     * Mint (or fetch the stored) shipping label for a booked shipment, persisting label_url.
     * The service is idempotent — a second call returns the stored URL without a partner call —
     * so retry-after-timeout is safe.
     */
    public function generateLabel(Shipment $shipment): array
    {
        $res = $this->request('post', '/v1/shipments/' . rawurlencode((string) $shipment->id) . '/label');
        if (empty($res['ok']) || empty($res['data']['ok'])) {
            return ['ok' => false, 'error' => $res['data']['error'] ?? $res['error'] ?? 'Label generation failed.'];
        }
        $labelUrl = (string) ($res['data']['label_url'] ?? '');
        if ($labelUrl !== '' && $labelUrl !== $shipment->label_url) {
            // The column has existed since 2026_06_23 and was written by nothing until now.
            $shipment->forceFill(['label_url' => $labelUrl, 'last_status_at' => Carbon::now()])->save();
        }
        return ['ok' => true, 'label_url' => $labelUrl, 'shipment' => $shipment->fresh()];
    }

    /** Schedule the courier pickup for a booked shipment. */
    public function schedulePickup(Shipment $shipment): array
    {
        $res = $this->request('post', '/v1/shipments/' . rawurlencode((string) $shipment->id) . '/pickup');
        if (empty($res['ok']) || empty($res['data']['ok'])) {
            return ['ok' => false, 'error' => $res['data']['error'] ?? $res['error'] ?? 'Pickup scheduling failed.'];
        }
        return ['ok' => true, 'pickup' => $res['data']['pickup'] ?? null];
    }

    public function track(Shipment $shipment): array
    {
        return $this->request('get', '/v1/shipments/' . rawurlencode((string) $shipment->id) . '/track');
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

    private function buildRequest(Shipment $shipment, string $mode, bool $cod, float $codAmount, ?string $partnerCode = null): array
    {
        $order = $shipment->order;
        $shop = $shipment->shop;
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
            'pickup'          => $this->addressFromShop($shop),
            'drop'            => $this->addressFromOrder($order),
            'items'           => $this->items($shipment),
            'weight_g'        => $this->weightG($shipment),
            'pickup_location' => (string) ($shop->pickup_location_name ?? ''),
        ];
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
            'phone'   => $this->digits($a['phone'] ?? ($shop->settings['contact'] ?? '')),
            'address' => (string) ($a['street_address'] ?? $a['address'] ?? ''),
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
            'apartment' => $pick($a, ['apartment', 'apartment_address', 'flat', 'flat_no', 'building', 'house_no']),
            'line2'     => $pick($a, ['line2', 'street_address2', 'address_line_2', 'area', 'locality']),
            'landmark'  => $pick($a, ['landmark', 'nearby', 'near']),
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
        return [
            'name'    => (string) ($order->customer_name ?? $a['name'] ?? 'Customer'),
            'phone'   => $this->digits($order->customer_contact ?? ($a['phone'] ?? '')),
            'address' => (string) ($a['street_address'] ?? $a['address'] ?? ''),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? ''),
            'lat'     => (float) ($loc['lat'] ?? $a['lat'] ?? $a['latitude'] ?? 0),
            'lng'     => (float) ($loc['lng'] ?? $a['lng'] ?? $a['longitude'] ?? 0),
        ] + $this->addressDetail($a);
    }

    private function items(Shipment $shipment): array
    {
        $out = [];
        foreach ($shipment->items as $it) {
            $p = $it->product ?? null;
            $out[] = [
                'name'       => (string) ($p->name ?? ('Item #' . $it->product_id)),
                'sku'        => (string) ($p->sku ?? ('SKU-' . $it->product_id)),
                'qty'        => max(1, (int) ($it->order_quantity ?? 1)),
                'unit_price' => (float) ($it->unit_price ?? 0),
                'weight_g'   => (int) ($p->weight ?? 0),
            ];
        }
        return $out;
    }

    private function weightG(Shipment $shipment): int
    {
        $g = 0;
        foreach ($shipment->items as $it) {
            $p = $it->product ?? null;
            $w = $p && (int) ($p->weight ?? 0) > 0 ? (int) $p->weight : 500;
            $g += $w * max(1, (int) ($it->order_quantity ?? 1));
        }
        return max(1, $g);
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
                $http = Http::timeout((int) config('services.shipping_service.timeout', 25))
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
