<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;

/**
 * Thin HTTP client to the dedicated Go shipping microservice. CourierService delegates to this
 * when services.shipping_service.enabled is on; the partner integration + COD accounting then live
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
        return [
            'name'    => (string) ($shop->name ?: 'Vendor'),
            'phone'   => $this->digits($a['phone'] ?? ($shop->settings['contact'] ?? '')),
            'address' => (string) ($a['street_address'] ?? $a['address'] ?? ''),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($shop->pickup_postcode ?? $a['zip'] ?? $a['pincode'] ?? ''),
            'lat'     => (float) ($a['lat'] ?? $a['latitude'] ?? $shop->lat ?? 0),
            'lng'     => (float) ($a['lng'] ?? $a['longitude'] ?? $shop->lng ?? 0),
        ];
    }

    private function addressFromOrder($order): array
    {
        $ship = (array) ($order->shipping_address ?? []);
        $a = (array) ($ship['address'] ?? $ship);
        return [
            'name'    => (string) ($order->customer_name ?? $a['name'] ?? 'Customer'),
            'phone'   => $this->digits($order->customer_contact ?? ($a['phone'] ?? '')),
            'address' => (string) ($a['street_address'] ?? $a['address'] ?? ''),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? ''),
            'lat'     => (float) ($a['lat'] ?? $a['latitude'] ?? 0),
            'lng'     => (float) ($a['lng'] ?? $a['longitude'] ?? 0),
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

    private function request(string $method, string $path, array $body = []): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Shipping service is not configured.'];
        }
        try {
            $http = Http::timeout((int) config('services.shipping_service.timeout', 25))
                ->withHeaders(['X-Api-Key' => $this->apiKey]) // shared secret — header only, never logged
                ->acceptJson();
            $resp = $method === 'get'
                ? $http->get($this->baseUrl . $path)
                : $http->post($this->baseUrl . $path, $body);
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
