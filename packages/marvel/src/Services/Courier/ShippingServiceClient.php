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
        ];
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

    /** Book via the service (idempotent on shipment_ref) + persist the returned provider fields. */
    public function book(Shipment $shipment, string $mode, bool $cod, float $codAmount): array
    {
        $res = $this->request('post', '/v1/shipments', $this->buildRequest($shipment, $mode, $cod, $codAmount));
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
        return ['ok' => true];
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

    // ── request building ──────────────────────────────────────────

    private function buildRequest(Shipment $shipment, string $mode, bool $cod, float $codAmount): array
    {
        $order = $shipment->order;
        $shop = $shipment->shop;
        return [
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
        ];
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
        ];
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

    private function request(string $method, string $path, array $body = [], ?float $timeoutSeconds = null): array
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
                'get'   => $http->get($this->baseUrl . $path),
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
