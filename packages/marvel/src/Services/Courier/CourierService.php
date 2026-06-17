<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Marvel\Database\Models\DeliveryQuote;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Repositories\OrderRepository;
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
    private ?CourierProviderInterface $provider = null; // Shiprocket (intercity courier lane)
    private ?BorzoAdapter $borzo = null;                // Borzo (instant / same-city lane)
    private array $opts;

    public function __construct()
    {
        $options = (array) (Settings::getData()->options ?? []);
        $this->opts = (array) ($options['courier'] ?? []);
        // A partner enters service only when wired AND credentialed (so quote and book share one
        // eligibility rule — a half-configured partner is uniformly inert, never quotes-empty-but-books).
        if (config('services.shiprocket.enabled')
            && (!empty(config('services.shiprocket.api_token'))
                || (!empty(config('services.shiprocket.email')) && !empty(config('services.shiprocket.password'))))) {
            $this->provider = new ShiprocketClient();
        }
        if (config('services.borzo.enabled') && !empty(config('services.borzo.token'))) {
            $this->borzo = new BorzoAdapter();
        }
    }

    /** The admin master switch (settings.options.courier.enabled) gates EVERY partner lane. */
    private function master(): bool
    {
        return (bool) ($this->opts['enabled'] ?? false);
    }

    /** Shiprocket lane: provider configured AND master on. (Existing callers rely on this.) */
    public function enabled(): bool
    {
        return $this->provider !== null && $this->master();
    }

    /** Borzo lane: Borzo configured AND master on. */
    public function borzoEnabled(): bool
    {
        return $this->borzo !== null && $this->master();
    }

    /** Any partner lane usable (gates quote fan-out / dispatch). */
    public function anyEnabled(): bool
    {
        return $this->master() && ($this->provider !== null || $this->borzo !== null);
    }

    /**
     * Which lane this shipment ships on: an explicit shipment.mode wins, else derive from
     * fulfillment_mode (courier → Shiprocket; local → Borzo same-city instant).
     */
    public function modeOf(Shipment $shipment): string
    {
        if (in_array($shipment->mode, ['instant', 'same_city', 'courier'], true)) {
            return (string) $shipment->mode;
        }
        return $shipment->fulfillment_mode === 'courier' ? 'courier' : 'same_city';
    }

    /**
     * Dispatch a shipment to the correct partner for its mode: courier → Shiprocket (the proven
     * create+AWB sequence), instant/same-city → Borzo. Each lane is idempotent on
     * provider_order_id, so a retry never double-books. Safe no-op when the lane is disabled.
     */
    public function book(Shipment $shipment): array
    {
        $mode = $this->modeOf($shipment);
        if ($mode === 'courier') {
            return $this->enabled()
                ? $this->bookShipment($shipment)
                : ['ok' => false, 'error' => 'Courier (Shiprocket) lane is not enabled.'];
        }
        if (!$this->borzoEnabled()) {
            return ['ok' => false, 'error' => 'Instant (Borzo) lane is not enabled.'];
        }
        return $this->borzo->book($shipment);
    }

    /** Cancel a booked shipment via whichever partner placed it. */
    public function cancel(Shipment $shipment, ?string $reason = null): array
    {
        if (($shipment->provider ?? '') === 'borzo') {
            return $this->borzoEnabled()
                ? $this->borzo->cancel($shipment, $reason)
                : ['ok' => false, 'error' => 'Borzo lane is not enabled.'];
        }
        return (new ShiprocketAdapter())->cancel($shipment, $reason);
    }

    /**
     * Ranked delivery quotes across every partner that can serve this shipment's mode (+ COD).
     * Each partner's quote() is isolated; a partner that errors/times out is simply dropped.
     */
    public function quoteShipment(Shipment $shipment, ?bool $cod = null): array
    {
        if (!$this->anyEnabled()) {
            return ['ok' => false, 'error' => 'Courier integration is not enabled.'];
        }
        $order = $shipment->order;
        $cod = $cod ?? ($order && $order->payment_gateway === PaymentGatewayType::CASH_ON_DELIVERY);
        $mode = $this->modeOf($shipment);

        $quotes = [];
        foreach (AdapterRegistry::candidatesForMode($mode, (bool) $cod) as $code => $adapter) {
            $q = $adapter->quote($shipment, (bool) $cod);
            if (!empty($q['ok'])) {
                $quotes[] = [
                    'partner'       => $code,
                    'cost'          => $q['cost'] ?? null,
                    'eta_days'      => $q['eta_days'] ?? null,
                    'cod_available' => $q['cod_available'] ?? null,
                ];
                // Audit row (book-against-quote + analytics). Never let a persistence hiccup break
                // the quote response.
                try {
                    DeliveryQuote::create([
                        'shipment_id'     => $shipment->id,
                        'order_id'        => $shipment->order_id,
                        'partner'         => $code,
                        'mode'            => $mode,
                        'cod'             => (bool) $cod,
                        'cod_amount'      => $shipment->cod_amount,
                        'quoted_cost'     => $q['cost'] ?? null,
                        'quoted_eta_days' => $q['eta_days'] ?? null,
                        'raw'             => $q['raw'] ?? null,
                        'expires_at'      => Carbon::now()->addMinutes(15),
                    ]);
                } catch (\Throwable $e) {
                    // audit-only; ignore
                }
            }
        }
        usort($quotes, fn ($a, $b) => ($a['cost'] ?? INF) <=> ($b['cost'] ?? INF));
        return ['ok' => !empty($quotes), 'mode' => $mode, 'cod' => (bool) $cod, 'quotes' => $quotes, 'cheapest' => $quotes[0] ?? null];
    }

    /** Apply a Borzo order/delivery status through the SHARED order-advance seam (idempotent). */
    public function applyBorzoStatus(Shipment $shipment, string $orderStatus, string $deliveryStatus = ''): array
    {
        $adapter = $this->borzo ?? new BorzoAdapter();
        return $this->applyNormalizedStatus($shipment, $adapter->mapBorzoStatus($orderStatus, $deliveryStatus));
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
        // This is the Shiprocket (courier) lane only. A Borzo shipment's normal booked state (order
        // id, no provider_shipment_id) would otherwise be mis-read as a broken Shiprocket row and
        // corrupted to book_failed — refuse it and point at the dispatch endpoint.
        if (($shipment->provider ?? '') === 'borzo') {
            return ['ok' => false, 'error' => 'This shipment is on the Borzo instant lane; use POST shipments/{id}/dispatch.'];
        }
        $order = $shipment->order;
        $shop = $shipment->shop;
        if (!$order || !$shop) {
            return ['ok' => false, 'error' => 'Shipment is missing its order or vendor.'];
        }
        if (!$shop->pickup_location_name) {
            return ['ok' => false, 'error' => 'Vendor has no registered pickup location — run sync-pickup first.'];
        }

        // Create the provider order ONCE. Guard on blank() (not falsy) and require BOTH ids
        // back before persisting, so a 2xx-without-ids response never (a) saves an empty
        // provider_order_id that a retry would treat as "not created" → DUPLICATE order, nor
        // (b) wedges the shipment with an empty provider_shipment_id the AWB step can't use.
        if (blank($shipment->provider_order_id)) {
            $payload = $this->buildOrderPayload($shipment, $order, $shop);
            $created = $this->provider->createOrder($payload);
            $newOrderId    = (string) data_get($created, 'data.order_id');
            $newShipmentId = (string) data_get($created, 'data.shipment_id');
            if (empty($created['ok']) || $newOrderId === '' || $newShipmentId === '') {
                $shipment->forceFill(['last_status' => 'book_failed'])->save();
                return ['ok' => false, 'error' => $created['error'] ?? 'Courier order was not created (provider returned no order/shipment id).'];
            }
            $shipment->forceFill([
                'provider'            => 'shiprocket',
                'mode'                => $shipment->mode ?: 'courier',
                'provider_order_id'   => $newOrderId,
                'provider_shipment_id' => $newShipmentId,
                'booked_revenue'      => $shipment->shipping_revenue,
                'last_status'         => 'booked',
                'last_status_at'      => Carbon::now(),
            ])->save();
        }

        // Belt-and-suspenders for any legacy row with an order id but no shipment id.
        if (blank($shipment->provider_shipment_id)) {
            $shipment->forceFill(['last_status' => 'book_failed'])->save();
            return ['ok' => false, 'error' => 'Courier order is missing its shipment id — clear provider_order_id and re-book.'];
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

    /**
     * Apply a provider status to a shipment + advance the customer order through the canonical
     * seam (OrderRepository::changeOrderStatus → ledger/settlement/DP fan-out). Shared by the
     * webhook AND the courier-reconcile command. Idempotent (no-op if already in that state).
     * The order completes ONLY when no shipment is still in-flight — delivered AND cancelled
     * (RTO/return) both count as terminal, so an RTO leg never freezes the order forever.
     */
    public function applyStatus(Shipment $shipment, string $providerStatus): array
    {
        return $this->applyNormalizedStatus($shipment, $this->mapStatus($providerStatus));
    }

    /**
     * Apply an ALREADY-normalized {shipment_status, order_status} to a shipment + advance the
     * customer order. Shared by every partner (Shiprocket via mapStatus, Borzo via mapBorzoStatus)
     * so the order-advance seam + in-flight completion guard live in exactly one place. Idempotent.
     */
    public function applyNormalizedStatus(Shipment $shipment, array $map): array
    {
        $now = Carbon::now();
        $target = $map['shipment_status'] ?? null;

        if ($target === null) {
            $shipment->forceFill(['last_status_at' => $now])->save();
            return $map;
        }

        // Monotonic + terminal-sticky. Once delivered/cancelled, ignore later events; otherwise only
        // move FORWARD (cancelled may interrupt any pre-terminal state). This makes out-of-order or
        // duplicate webhooks (common across multi-leg orders, esp. Borzo jumping straight to
        // out_for_delivery) a true no-op instead of regressing the shipment — and never reverses
        // delivered_at or fires settlement-reversing order downgrades.
        $rank = ['pending' => 0, 'assigned' => 1, 'packed' => 1, 'shipped' => 2, 'out_for_delivery' => 3, 'delivered' => 4];
        $cur = (string) $shipment->status;
        if (in_array($cur, ['delivered', 'cancelled'], true)) {
            $shipment->forceFill(['last_status_at' => $now])->save();
            return $map; // terminal → sticky
        }
        if ($target !== 'cancelled' && ($rank[$target] ?? 0) <= ($rank[$cur] ?? 0)) {
            $shipment->forceFill(['last_status_at' => $now])->save();
            return $map; // same or backward → no-op
        }

        $fill = ['status' => $target, 'last_status' => $target, 'last_status_at' => $now];
        if ($target === 'shipped' && !$shipment->shipped_at) {
            $fill['shipped_at'] = $now;
        }
        if ($target === 'delivered') {
            $fill['delivered_at'] = $now;
        }
        $shipment->forceFill($fill)->save();

        if (!empty($map['order_status'])) {
            $order = $shipment->order;
            if ($order) {
                $dest = $map['order_status'];
                if ($dest === 'order-completed') {
                    // Terminal = delivered OR cancelled; an in-flight leg keeps the order open.
                    $inFlight = Shipment::where('order_id', $order->id)
                        ->whereNotIn('status', ['delivered', 'cancelled'])->exists();
                    if ($inFlight) {
                        $dest = 'order-out-for-delivery';
                    }
                }
                // Don't regress the customer order timeline across multi-leg orders (a slower leg's
                // "shipped" must not pull an order already out-for-delivery back to at-local-facility).
                $orank = ['order-pending' => 0, 'order-processing' => 1, 'order-at-local-facility' => 2, 'order-out-for-delivery' => 3, 'order-completed' => 4];
                $advance = !isset($orank[$dest], $orank[$order->order_status]) || $orank[$dest] > $orank[$order->order_status];
                if ($order->order_status !== $dest && $advance) {
                    app(OrderRepository::class)->changeOrderStatus($order, $dest);
                }
            }
        }
        return $map;
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
