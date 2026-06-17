<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Marvel\Database\Models\Shipment;
use Marvel\Enums\PaymentGatewayType;

/**
 * Shiprocket adapter — the intercity courier lane, behind the same ShippingPartnerAdapter seam
 * as Borzo. Book / track reuse the proven, idempotent, partial-failure-aware logic that already
 * lives in CourierService (we do NOT duplicate the create+AWB sequence); quote / cancel / webhook
 * normalization wrap ShiprocketClient. Resolved lazily to avoid any construction cycle with
 * CourierService (CourierService::book dispatches to its own bookShipment for this lane, never
 * back through this adapter).
 */
class ShiprocketAdapter implements ShippingPartnerAdapter
{
    public function code(): string
    {
        return 'shiprocket';
    }

    public function capabilities(): array
    {
        return ['modes' => ['courier'], 'cod' => true, 'multipoint' => false, 'label' => true];
    }

    public function quote(Shipment $shipment, bool $cod): array
    {
        $order = $shipment->order;
        $shop = $shipment->shop;
        $pickupPin = (string) ($shop->pickup_postcode ?? data_get($shop->address, 'zip', ''));
        $dropPin = (string) (data_get($order->shipping_address, 'address.zip', data_get($order->shipping_address, 'zip', '')));
        if ($pickupPin === '' || $dropPin === '') {
            return ['ok' => false, 'error' => 'Pickup or delivery pincode is missing.'];
        }
        $weight = 0;
        foreach ($shipment->items as $it) {
            $weight += (($it->product->weight ?? 500) ?: 500) * max(1, (int) ($it->order_quantity ?? 1));
        }
        $res = (new ShiprocketClient())->serviceability($pickupPin, $dropPin, max(1, (int) $weight), $cod);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shiprocket serviceability failed.'];
        }
        $rows = (array) data_get($res, 'data.data.available_courier_companies', []);
        if (!$rows) {
            return ['ok' => false, 'error' => 'No serviceable courier for this route.'];
        }
        usort($rows, fn ($a, $b) => ($a['rate'] ?? 0) <=> ($b['rate'] ?? 0));
        $best = $rows[0];
        return [
            'ok'            => true,
            'cost'          => round((float) ($best['rate'] ?? 0), 2),
            'eta_days'      => (int) ($best['estimated_delivery_days'] ?? 0),
            'cod_available' => (bool) ($best['cod'] ?? 0),
            'currency'      => 'INR',
            'raw'           => $best,
            'error'         => null,
        ];
    }

    public function book(Shipment $shipment): array
    {
        return (new CourierService())->bookShipment($shipment); // proven, idempotent
    }

    public function cancel(Shipment $shipment, ?string $reason = null): array
    {
        if (blank($shipment->provider_order_id)) {
            return ['ok' => false, 'error' => 'No Shiprocket order to cancel.'];
        }
        $res = (new ShiprocketClient())->cancelOrder([$shipment->provider_order_id]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shiprocket cancel failed.'];
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
        $svc = new CourierService();
        $res = $svc->track($shipment);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Shiprocket track failed.'];
        }
        $status = (string) (Arr::get($res, 'data.tracking_data.shipment_track.0.current_status')
            ?? Arr::get($res, 'data.current_status') ?? '');
        return [
            'ok'         => true,
            'normalized' => $status !== '' ? $svc->mapStatus($status) : null,
            'raw'        => $res['data'] ?? null,
            'error'      => null,
        ];
    }

    public function normalizeWebhook(Request $request): array
    {
        $expected = (string) config('services.shiprocket.webhook_token');
        $valid = $expected !== '' && hash_equals($expected, (string) $request->header('x-api-key'));
        $status = (string) ($request->input('current_status') ?? $request->input('status') ?? '');
        $shipmentId = (string) ($request->input('shipment_id') ?? '');
        $awb = (string) ($request->input('awb') ?? '');
        return [
            'ok'              => true,
            'signature_valid' => $valid,
            'events'          => ($shipmentId === '' && $awb === '') ? [] : [[
                'provider_shipment_id' => $shipmentId ?: null,
                'awb_number'           => $awb ?: null,
                'normalized'           => $status !== '' ? (new CourierService())->mapStatus($status) : null,
            ]],
            'error'           => null,
        ];
    }

    /** Whether COD applies to this shipment's order (parity with Borzo's COD detection). */
    public static function isCod($order): bool
    {
        return $order && $order->payment_gateway === PaymentGatewayType::CASH_ON_DELIVERY;
    }
}
