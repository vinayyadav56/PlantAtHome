<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Enums\PaymentGatewayType;

/**
 * Borzo adapter — the instant / same-city lane. Maps a PlantAtHome Shipment to Borzo's
 * two-point order (point[0]=vendor pickup, point[1]=customer drop), handles cash-on-delivery
 * (is_cod_cash_voucher_required + taking_amount + the mandatory package line), and normalizes
 * Borzo's order/delivery vocabulary back to our shipment/order statuses. EVERY Borzo field name
 * lives in this file only. Booking is idempotent on provider_order_id; no method throws.
 */
class BorzoAdapter implements ShippingPartnerAdapter
{
    private BorzoClient $client;

    public function __construct(?BorzoClient $client = null)
    {
        $this->client = $client ?? new BorzoClient();
    }

    public function code(): string
    {
        return 'borzo';
    }

    public function capabilities(): array
    {
        return ['modes' => ['instant', 'same_city'], 'cod' => true, 'multipoint' => true, 'label' => false];
    }

    public function quote(Shipment $shipment, bool $cod): array
    {
        $payload = $this->buildOrderPayload($shipment, $cod);
        if (!empty($payload['_error'])) {
            return ['ok' => false, 'error' => $payload['_error']];
        }
        unset($payload['_error']);
        $res = $this->client->calculateOrder($payload);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Borzo quote failed.', 'raw' => $res['data'] ?? null];
        }
        $order = (array) data_get($res, 'data.order', []);
        return [
            'ok'            => true,
            'cost'          => round((float) ($order['payment_amount'] ?? 0), 2),
            'eta_days'      => 0, // same-day / within hours
            'cod_available' => true,
            'currency'      => 'INR',
            'raw'           => $order,
            'error'         => null,
        ];
    }

    /**
     * Place the Borzo order. Idempotent: if provider_order_id is already set we DON'T re-create
     * (a retry never duplicates the delivery). Requires both vendor pickup + customer drop
     * addresses; COD requires a cod_amount (set by the caller / order total).
     */
    public function book(Shipment $shipment): array
    {
        if (!blank($shipment->provider_order_id)) {
            return ['ok' => true, 'shipment' => $shipment, 'note' => 'already booked'];
        }

        $order = $shipment->order;
        $isCod = $order && $order->payment_gateway === PaymentGatewayType::CASH_ON_DELIVERY;
        $payload = $this->buildOrderPayload($shipment, (bool) $isCod);
        if (!empty($payload['_error'])) {
            $shipment->forceFill(['last_status' => 'book_failed', 'failure_reason' => $payload['_error']])->save();
            return ['ok' => false, 'error' => $payload['_error']];
        }
        unset($payload['_error']);

        // Atomic claim: flip a still-unbooked, non-cancelled row to 'booking' in ONE UPDATE. Only
        // the request whose UPDATE affected a row may call Borzo, so a concurrent dispatch / double
        // click (or dispatch racing the reconcile sweep) can never place two real deliveries.
        $claimed = Shipment::whereKey($shipment->id)
            ->whereNull('provider_order_id')
            ->whereNotIn('status', ['cancelled'])
            ->update(['last_status' => 'booking']);
        if ($claimed === 0) {
            return ['ok' => false, 'error' => 'Shipment is already being booked or has been cancelled.'];
        }

        $res = $this->client->createOrder($payload);
        $orderId = (string) data_get($res, 'data.order.order_id', '');
        if (empty($res['ok']) || $orderId === '') {
            $err = $res['error'] ?? 'Borzo order was not created.';
            $shipment->forceFill(['last_status' => 'book_failed', 'failure_reason' => $err])->save();
            return ['ok' => false, 'error' => $err, 'raw' => $res['data'] ?? null];
        }

        // The Borzo delivery now EXISTS. Persist its id immediately + minimally (only columns
        // guaranteed to exist) so a failure in the rich update below can't lose the id and let a
        // retry double-book. (Borzo also rejects identical re-submits with order_is_duplicate.)
        Shipment::whereKey($shipment->id)->update([
            'provider'          => 'borzo',
            'provider_order_id' => $orderId,
            'tracking_ref'      => $orderId,
        ]);

        // If the shipment was cancelled DURING the create window, roll the Borzo order back so we
        // never have a live delivery the operator believes was cancelled.
        $fresh = Shipment::find($shipment->id);
        if ($fresh && $fresh->status === 'cancelled') {
            $this->client->cancelOrder($orderId);
            return ['ok' => false, 'error' => 'Shipment was cancelled during booking; the Borzo order was rolled back.'];
        }

        $points = (array) data_get($res, 'data.order.points', []);
        $drop = end($points) ?: [];
        $cost = round((float) data_get($res, 'data.order.payment_amount', 0), 2);

        $shipment->forceFill([
            'provider'          => 'borzo',
            'mode'              => $shipment->mode ?: 'same_city',
            'provider_order_id' => $orderId,
            'tracking_ref'      => $orderId,
            'tracking_url'      => (string) ($drop['tracking_url'] ?? ''),
            'courier_name'      => 'Borzo',
            'payment_method'    => $isCod ? 'cod' : 'prepaid',
            'cod_amount'        => $isCod ? $this->codAmount($shipment, $order) : $shipment->cod_amount,
            'booked_cost'       => $cost,
            'booked_revenue'    => $shipment->shipping_revenue,
            'shipping_cost'     => $cost,
            'status'            => 'assigned',
            'last_status'       => 'booked',
            'last_status_at'    => Carbon::now(),
        ])->save();

        return ['ok' => true, 'shipment' => $shipment->fresh()];
    }

    public function cancel(Shipment $shipment, ?string $reason = null): array
    {
        if (blank($shipment->provider_order_id)) {
            return ['ok' => false, 'error' => 'No Borzo order to cancel.'];
        }
        $res = $this->client->cancelOrder($shipment->provider_order_id);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Borzo cancel failed (courier may have already visited).'];
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
        if (blank($shipment->provider_order_id)) {
            return ['ok' => false, 'error' => 'No Borzo order to track.'];
        }
        $res = $this->client->getOrder($shipment->provider_order_id);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Borzo track failed.'];
        }
        // GET /orders returns a list (or a single 'order'); find OURS by EXACT order_id. If it's
        // absent (filter ignored / pagination / API lag), do NOT guess from orders[0] — return a
        // no-op so an unrelated order's status can never be applied; reconcile retries later.
        $single = data_get($res, 'data.order');
        $orders = (array) data_get($res, 'data.orders', $single ? [$single] : []);
        $match = collect($orders)->firstWhere('order_id', (int) $shipment->provider_order_id);
        if ($match === null) {
            return ['ok' => false, 'normalized' => null, 'raw' => null, 'error' => 'Order not found in Borzo response.'];
        }
        return [
            'ok'         => true,
            'normalized' => $this->normalizedFromOrder((array) $match),
            'raw'        => $match,
            'error'      => null,
        ];
    }

    public function normalizeWebhook(Request $request): array
    {
        $valid = $this->verifyWebhook($request);
        // Borzo posts order-callbacks and delivery-callbacks; both carry the order id.
        $orderId = (string) ($request->input('order_id')
            ?? data_get($request->all(), 'order.order_id')
            ?? data_get($request->all(), 'delivery.order_id') ?? '');
        $orderStatus = (string) (data_get($request->all(), 'order.status') ?? $request->input('status') ?? '');
        $deliveryStatus = (string) (data_get($request->all(), 'delivery.status') ?? '');

        return [
            'ok'              => true,
            'signature_valid' => $valid,
            'events'          => $orderId === '' ? [] : [[
                'provider_order_id' => $orderId,
                'normalized'        => ($orderStatus || $deliveryStatus) ? $this->mapBorzoStatus($orderStatus, $deliveryStatus) : null,
            ]],
            'error'           => null,
        ];
    }

    /**
     * Verify Borzo's HMAC signature (X-DV-Signature header over the raw body, keyed by the
     * callback token). Borzo has used SHA-1 historically; we accept SHA-256 too (constant-time)
     * so an algo change can't silently break us. When no callback token is configured we return
     * false — the webhook handler then relies on an AUTHENTICATED re-fetch (track) for truth,
     * so a missing/unverifiable signature can never be spoofed into a status change.
     */
    public function verifyWebhook(Request $request): bool
    {
        $token = (string) config('services.borzo.callback_token', '');
        $sig = (string) ($request->header('X-DV-Signature') ?? $request->header('x-dv-signature') ?? '');
        if ($token === '' || $sig === '') {
            return false;
        }
        $body = $request->getContent();
        foreach (['sha1', 'sha256'] as $algo) {
            if (hash_equals(hash_hmac($algo, $body, $token), $sig)) {
                return true;
            }
        }
        return false;
    }

    // ── mapping ───────────────────────────────────────────────────

    /**
     * Normalized status from a full Borzo order (order.status + the drop point's delivery.status).
     * Returns null when there's no usable status, so callers can distinguish "no info" (fall back
     * to a verified callback / retry) from a real status event — never a null-valued map.
     */
    public function normalizedFromOrder(array $order): ?array
    {
        if (empty($order)) {
            return null;
        }
        $orderStatus = (string) ($order['status'] ?? '');
        $points = (array) ($order['points'] ?? []);
        $drop = end($points) ?: [];
        $deliveryStatus = (string) data_get($drop, 'delivery.status', '');
        if ($orderStatus === '' && $deliveryStatus === '') {
            return null;
        }
        $map = $this->mapBorzoStatus($orderStatus, $deliveryStatus);
        return ($map['shipment_status'] === null && $map['order_status'] === null) ? null : $map;
    }

    /**
     * Borzo vocabulary → our {shipment_status, order_status}.
     *   order:    new | available | active | completed | canceled | delayed
     *   delivery: planned | active | finished | canceled
     * `order_status` is null when the event should not advance the customer order.
     */
    public function mapBorzoStatus(string $orderStatus, string $deliveryStatus = ''): array
    {
        $o = strtolower(trim($orderStatus));
        $d = strtolower(trim($deliveryStatus));

        if ($o === 'completed' || $d === 'finished') {
            return ['shipment_status' => 'delivered', 'order_status' => 'order-completed'];
        }
        if ($o === 'canceled' || $d === 'canceled') {
            return ['shipment_status' => 'cancelled', 'order_status' => null];
        }
        if ($o === 'active' || $d === 'active') {
            // Instant single-hop: courier accepted + en route ≈ out for delivery.
            return ['shipment_status' => 'out_for_delivery', 'order_status' => 'order-out-for-delivery'];
        }
        if (in_array($o, ['new', 'available', 'delayed'], true) || $d === 'planned') {
            return ['shipment_status' => 'assigned', 'order_status' => null];
        }
        return ['shipment_status' => null, 'order_status' => null];
    }

    // ── payload building ──────────────────────────────────────────

    /**
     * Build the Borzo calculate/create payload (shared so a quote matches its booking exactly).
     * Returns ['_error' => '...'] when the shipment can't be expressed as a Borzo order.
     */
    private function buildOrderPayload(Shipment $shipment, bool $cod): array
    {
        $order = $shipment->order;
        $shop = $shipment->shop;
        if (!$order || !$shop) {
            return ['_error' => 'Shipment is missing its order or vendor.'];
        }

        $pickup = $this->shopPoint($shop, $order, $shipment);
        $drop = $this->customerPoint($order, $shipment);
        if (!empty($pickup['_error'])) {
            return ['_error' => $pickup['_error']];
        }
        if (!empty($drop['_error'])) {
            return ['_error' => $drop['_error']];
        }

        $weightKg = $this->totalWeightKg($shipment);

        if ($cod) {
            $codAmount = $this->codAmount($shipment, $order);
            if ($codAmount <= 0) {
                return ['_error' => 'COD shipment has no collectable amount.'];
            }
            // Drop point collects the cash; package line is mandatory for COD. Both money fields
            // are 2dp strings (Borzo's money contract; a raw float drops trailing zeros / leaks
            // float noise). ware_code is a string vendor/article code, not a boolean.
            $codStr = number_format($codAmount, 2, '.', '');
            $drop['taking_amount'] = $codStr;
            $drop['is_cod_cash_voucher_required'] = true;
            $pickup['packages'] = [[
                'ware_code'          => (string) ($order->tracking_number ?? $order->id) . '-S' . $shipment->id,
                'items_count'        => max(1, $shipment->items->sum(fn ($i) => (int) ($i->order_quantity ?? 1))),
                'item_payment_amount' => $codStr,
                'description'        => 'PlantAtHome order ' . ($order->tracking_number ?? $order->id),
            ]];
        }

        return [
            'type'                                  => 'standard',
            'matter'                                => (string) config('services.borzo.matter', 'Plants & garden supplies'),
            'vehicle_type_id'                       => (int) config('services.borzo.vehicle_type_id', 8),
            'total_weight_kg'                       => $weightKg,
            'is_client_notification_enabled'        => true,
            'is_contact_person_notification_enabled' => true,
            'points'                                => [$pickup, $drop],
        ];
    }

    private function shopPoint($shop, $order, Shipment $shipment): array
    {
        $addr = (array) ($shop->address ?? []);
        $address = $this->composeAddress($addr);
        if ($address === '') {
            return ['_error' => 'Vendor has no address for Borzo pickup.'];
        }
        $point = [
            'address'        => $address,
            'contact_person' => [
                'name'  => (string) ($shop->name ?: 'Vendor'),
                'phone' => $this->phone($addr['phone'] ?? ($shop->settings['contact'] ?? '')),
            ],
            'client_order_id' => (string) ($order->tracking_number ?? $order->id) . '-S' . $shipment->id,
        ];
        $this->attachLatLng($point, $addr, $shop);
        return $point;
    }

    private function customerPoint($order, Shipment $shipment): array
    {
        $ship = (array) ($order->shipping_address ?? []);
        $a = (array) ($ship['address'] ?? $ship);
        $address = $this->composeAddress($a);
        if ($address === '') {
            return ['_error' => 'Order has no shipping address for Borzo drop.'];
        }
        $point = [
            'address'        => $address,
            'contact_person' => [
                'name'  => (string) ($order->customer_name ?? $a['name'] ?? 'Customer'),
                'phone' => $this->phone($order->customer_contact ?? ($a['phone'] ?? '')),
            ],
            'client_order_id' => (string) ($order->tracking_number ?? $order->id) . '-S' . $shipment->id,
        ];
        $this->attachLatLng($point, $a, null);
        return $point;
    }

    /** "street, city, state pincode" from a loose address array; '' when nothing usable. */
    private function composeAddress(array $a): string
    {
        $parts = array_filter([
            $a['street_address'] ?? $a['address'] ?? null,
            $a['city'] ?? null,
            $a['state'] ?? null,
            $a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? null,
        ], fn ($v) => is_string($v) ? trim($v) !== '' : !empty($v));
        return trim(implode(', ', array_map('strval', $parts)));
    }

    /** Borzo strongly prefers coordinates; include them when the address (or shop) carries them. */
    private function attachLatLng(array &$point, array $addr, $shop): void
    {
        $lat = $addr['lat'] ?? $addr['latitude'] ?? ($shop->lat ?? null);
        $lng = $addr['lng'] ?? $addr['longitude'] ?? ($shop->lng ?? null);
        if (is_numeric($lat) && is_numeric($lng)) {
            $point['latitude'] = (float) $lat;
            $point['longitude'] = (float) $lng;
        }
    }

    private function phone($raw): string
    {
        return preg_replace('/\D+/', '', (string) $raw) ?: '';
    }

    private function totalWeightKg(Shipment $shipment): float
    {
        $grams = 0;
        foreach ($shipment->items as $it) {
            $product = $it->product ?? null;
            $qty = max(1, (int) ($it->order_quantity ?? 1));
            $w = $product && (int) ($product->weight ?? 0) > 0 ? (int) $product->weight : 500;
            $grams += $w * $qty;
        }
        return max(1.0, round($grams / 1000, 1));
    }

    /**
     * Cash to collect at THIS shipment's drop. A shipment is one vendor's slice of a possibly
     * multi-vendor order, so we must NEVER fall back to the whole-order total (that would collect
     * the full amount at EVERY drop). Use an explicit per-shipment cod_amount when set, else sum
     * this shipment's OWN line subtotals + its delivery share — which can never exceed the order.
     */
    private function codAmount(Shipment $shipment, $order): float
    {
        $explicit = (float) ($shipment->cod_amount ?? 0);
        if ($explicit > 0) {
            return round($explicit, 2);
        }
        $goods = (float) $shipment->items->sum(function ($i) {
            $sub = $i->subtotal ?? null;
            return $sub !== null ? (float) $sub : (float) ($i->unit_price ?? 0) * max(1, (int) ($i->order_quantity ?? 1));
        });
        return round($goods + (float) ($shipment->shipping_cost ?? 0), 2);
    }
}
