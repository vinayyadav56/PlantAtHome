<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Enums\PaymentGatewayType;

/**
 * Provider-agnostic courier domain layer. Resolves the active provider from config and
 * translates between PlantAtHome models and the provider's payloads. Everything is gated by
 * enabled(): when off (no COURIER_PROVIDER / settings flag), every method is a safe no-op
 * and the system falls back to the manual courier behavior. Booking is idempotent (keyed on
 * the shipment's provider_order_id) and partial-failure aware.
 */
class CourierService
{
    private ?CourierProviderInterface $provider = null;
    private array $opts;

    public function __construct()
    {
        $options = (array) (Settings::getData()->options ?? []);
        $this->opts = (array) ($options['courier'] ?? []);
        if (config('services.shiprocket.enabled')) {
            $this->provider = new ShiprocketClient();
        }
    }

    /** Master gate: a provider is configured AND the admin courier flag is on. */
    public function enabled(): bool
    {
        return $this->provider !== null && (bool) ($this->opts['enabled'] ?? false);
    }

    /** Normalized serviceability {couriers:[{courier_id,name,rate,eta_days,cod_available}], cheapest, recommended}. */
    public function serviceability(string $pickupPin, string $dropPin, int $weightGrams, bool $cod): ?array
    {
        if (!$this->enabled() || !$pickupPin || !$dropPin) {
            return null;
        }
        $res = $this->provider->serviceability($pickupPin, $dropPin, max(1, $weightGrams), $cod);
        if (empty($res['ok'])) {
            return null;
        }
        $rows = (array) data_get($res, 'data.data.available_courier_companies', []);
        if (empty($rows)) {
            return null;
        }
        $couriers = array_map(fn ($c) => [
            'courier_id'    => $c['courier_company_id'] ?? null,
            'name'          => $c['courier_name'] ?? '',
            'rate'          => round((float) ($c['rate'] ?? 0), 2),
            'eta_days'      => (int) ($c['estimated_delivery_days'] ?? ($c['etd_hours'] ?? 0) / 24 ?: 0),
            'cod_available' => (bool) ($c['cod'] ?? 0),
        ], $rows);
        usort($couriers, fn ($a, $b) => $a['rate'] <=> $b['rate']);

        return [
            'couriers'    => $couriers,
            'cheapest'    => $couriers[0] ?? null,
            'recommended' => $couriers[0] ?? null,
        ];
    }

    /** Default physical package when a product carries no weight/dims yet. */
    public function defaultPackage(): array
    {
        $d = (array) ($this->opts['default_package'] ?? []);
        return [
            'weight' => (int) ($d['weight'] ?? 500),   // grams
            'length' => (float) ($d['length'] ?? 20),  // cm
            'breadth' => (float) ($d['breadth'] ?? 15),
            'height' => (float) ($d['height'] ?? 15),
        ];
    }

    /**
     * Create the provider order + allocate an AWB for a courier shipment. Idempotent:
     * if provider_order_id is already set we skip creation and (re)run only AWB allocation,
     * so a retry after a partial failure never creates a duplicate provider order.
     */
    public function bookShipment(Shipment $shipment): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'Courier integration is not enabled.'];
        }
        $order = $shipment->order;
        $shop = $shipment->shop;
        if (!$order || !$shop) {
            return ['ok' => false, 'error' => 'Shipment is missing its order or vendor.'];
        }
        if (!$shop->pickup_location_name) {
            return ['ok' => false, 'error' => 'Vendor has no registered pickup location — run sync-pickup first.'];
        }

        // Create the provider order once.
        if (!$shipment->provider_order_id) {
            $payload = $this->buildOrderPayload($shipment, $order, $shop);
            $created = $this->provider->createOrder($payload);
            if (empty($created['ok'])) {
                $shipment->forceFill(['last_status' => 'book_failed'])->save();
                return ['ok' => false, 'error' => $created['error'] ?? 'Could not create courier order.'];
            }
            $shipment->forceFill([
                'provider'            => 'shiprocket',
                'provider_order_id'   => (string) data_get($created, 'data.order_id'),
                'provider_shipment_id' => (string) data_get($created, 'data.shipment_id'),
                'last_status'         => 'booked',
                'last_status_at'      => Carbon::now(),
            ])->save();
        }

        // Allocate the AWB (idempotent — safe to re-run).
        $awb = $this->provider->assignAwb($shipment->provider_shipment_id);
        if (empty($awb['ok'])) {
            $shipment->forceFill(['last_status' => 'awb_failed'])->save();
            return ['ok' => false, 'error' => $awb['error'] ?? 'Courier order created but AWB allocation failed. Retry to allocate.', 'shipment' => $shipment->fresh()];
        }
        $d = (array) data_get($awb, 'data.response.data', data_get($awb, 'data', []));
        $shipment->forceFill([
            'awb_number'        => (string) ($d['awb_code'] ?? $shipment->awb_number),
            'courier_name'      => (string) ($d['courier_name'] ?? $shipment->courier_name),
            'courier_company_id' => $d['courier_company_id'] ?? $shipment->courier_company_id,
            'status'            => 'shipped',
            'last_status'       => 'awb_assigned',
            'last_status_at'    => Carbon::now(),
            'tracking_url'      => $this->trackingUrl((string) ($d['awb_code'] ?? $shipment->awb_number)),
        ])->save();

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    public function generateLabel(Shipment $shipment): array
    {
        if (!$this->enabled() || !$shipment->provider_shipment_id) {
            return ['ok' => false, 'error' => 'Nothing to label.'];
        }
        $res = $this->provider->generateLabel([$shipment->provider_shipment_id]);
        if (!empty($res['ok'])) {
            $shipment->forceFill(['label_url' => (string) data_get($res, 'data.label_url')])->save();
        }
        return ['ok' => !empty($res['ok']), 'label_url' => $shipment->label_url, 'error' => $res['error'] ?? null];
    }

    public function schedulePickup(Shipment $shipment): array
    {
        if (!$this->enabled() || !$shipment->provider_shipment_id) {
            return ['ok' => false, 'error' => 'Nothing to pick up.'];
        }
        $res = $this->provider->schedulePickup([$shipment->provider_shipment_id]);
        return ['ok' => !empty($res['ok']), 'error' => $res['error'] ?? null, 'data' => $res['data'] ?? null];
    }

    public function track(Shipment $shipment): array
    {
        if (!$this->enabled() || !$shipment->awb_number) {
            return ['ok' => false, 'error' => 'No AWB to track.'];
        }
        return $this->provider->track($shipment->awb_number);
    }

    /** Register the vendor's address as a provider pickup location (idempotent). */
    public function syncPickupLocation(Shop $shop): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'Courier integration is not enabled.'];
        }
        $addr = (array) ($shop->address ?? []);
        $nickname = 'shop-' . $shop->id;
        $payload = [
            'pickup_location' => $nickname,
            'name'            => (string) $shop->name,
            'email'           => (string) ($shop->settings['contact'] ?? config('mail.from.address') ?? 'ops@plantathome.in'),
            'phone'           => (string) ($addr['phone'] ?? $shop->settings['contact'] ?? ''),
            'address'         => (string) ($addr['street_address'] ?? $addr['address'] ?? ''),
            'city'            => (string) ($addr['city'] ?? ''),
            'state'           => (string) ($addr['state'] ?? ''),
            'country'         => (string) ($addr['country'] ?? 'India'),
            'pin_code'        => (string) ($shop->pickup_postcode ?? $addr['zip'] ?? ''),
        ];
        $res = $this->provider->addPickupLocation($payload);
        if (!empty($res['ok'])) {
            $shop->forceFill([
                'pickup_location_name' => $nickname,
                'pickup_postcode'      => $shop->pickup_postcode ?: ($addr['zip'] ?? null),
            ])->save();
        }
        return ['ok' => !empty($res['ok']), 'pickup_location_name' => $shop->pickup_location_name, 'error' => $res['error'] ?? null];
    }

    /**
     * Map a provider status string to our internal {shipment_status, order_status?}.
     * order_status is null when the provider event shouldn't advance the customer order.
     */
    public function mapStatus(string $providerStatus): array
    {
        $s = strtolower(trim($providerStatus));
        $has = fn (string $needle) => str_contains($s, $needle);

        if ($has('delivered')) {
            return ['shipment_status' => 'delivered', 'order_status' => 'order-completed'];
        }
        if ($has('out for delivery') || $has('out_for_delivery')) {
            return ['shipment_status' => 'out_for_delivery', 'order_status' => 'order-out-for-delivery'];
        }
        if ($has('in transit') || $has('shipped') || $has('picked')) {
            return ['shipment_status' => 'shipped', 'order_status' => 'order-at-local-facility'];
        }
        if ($has('pickup') || $has('manifest') || $has('awb')) {
            return ['shipment_status' => 'assigned', 'order_status' => null];
        }
        if ($has('rto') || $has('return')) {
            return ['shipment_status' => 'cancelled', 'order_status' => null];
        }
        if ($has('cancel')) {
            return ['shipment_status' => 'cancelled', 'order_status' => null];
        }
        return ['shipment_status' => null, 'order_status' => null];
    }

    // ── payload helpers ───────────────────────────────────────────

    private function buildOrderPayload(Shipment $shipment, $order, Shop $shop): array
    {
        $def = $this->defaultPackage();
        $items = [];
        $weight = 0;
        $maxL = $def['length'];
        $maxB = $def['breadth'];
        $maxH = $def['height'];
        foreach ($shipment->items as $it) {
            $product = $it->product ?? null;
            $qty = max(1, (int) ($it->order_quantity ?? 1));
            $w = $product && (int) ($product->weight ?? 0) > 0 ? (int) $product->weight : $def['weight'];
            $weight += $w * $qty;
            if ($product) {
                $maxL = max($maxL, (float) ($product->length ?? 0));
                $maxB = max($maxB, (float) ($product->breadth ?? 0));
                $maxH = max($maxH, (float) ($product->height ?? 0));
            }
            $items[] = [
                'name'          => $product->name ?? ('Item #' . $it->product_id),
                'sku'           => $product->sku ?? ('SKU-' . $it->product_id),
                'units'         => $qty,
                'selling_price' => (float) ($it->unit_price ?? 0),
            ];
        }

        $ship = (array) ($order->shipping_address ?? []);
        $a = (array) ($ship['address'] ?? $ship);
        $isCod = $order->payment_gateway === PaymentGatewayType::CASH_ON_DELIVERY;

        return [
            'order_id'              => (string) ($order->tracking_number ?? $order->id) . '-S' . $shipment->id,
            'order_date'            => Carbon::parse($order->created_at ?? now())->format('Y-m-d H:i'),
            'pickup_location'       => $shop->pickup_location_name,
            'channel_id'            => '',
            'billing_customer_name' => (string) ($order->customer_name ?? $a['name'] ?? 'Customer'),
            'billing_last_name'     => '',
            'billing_address'       => (string) ($a['street_address'] ?? $a['address'] ?? ''),
            'billing_city'          => (string) ($a['city'] ?? ''),
            'billing_pincode'       => (string) ($a['zip'] ?? $a['pincode'] ?? ''),
            'billing_state'         => (string) ($a['state'] ?? ''),
            'billing_country'       => (string) ($a['country'] ?? 'India'),
            'billing_email'         => (string) ($order->customer->email ?? 'customer@plantathome.in'),
            'billing_phone'         => (string) ($order->customer_contact ?? $a['phone'] ?? ''),
            'shipping_is_billing'   => true,
            'order_items'           => $items,
            'payment_method'        => $isCod ? 'COD' : 'Prepaid',
            'sub_total'             => (float) ($order->amount ?? array_sum(array_map(fn ($i) => $i['selling_price'] * $i['units'], $items))),
            'length'                => $maxL,
            'breadth'               => $maxB,
            'height'                => $maxH,
            'weight'                => max(0.1, round($weight / 1000, 3)), // kg
        ];
    }

    private function trackingUrl(string $awb): string
    {
        return $awb ? ('https://shiprocket.co/tracking/' . rawurlencode($awb)) : '';
    }
}
