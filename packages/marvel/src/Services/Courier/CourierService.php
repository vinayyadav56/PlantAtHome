<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Marvel\Database\Models\CourierPartnerConfig;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\PaymentGatewayType;

/**
 * Courier DOMAIN layer. Every partner API call (Borzo / Shiprocket / Porter) lives exclusively
 * in the dedicated Go shipping-service — the monolith only delegates (ShippingServiceClient),
 * receives status callbacks (applyNormalizedStatus seam), and holds the admin master switch.
 * When the service link isn't configured, every operation is a safe structured failure — there
 * is deliberately NO in-process fallback anymore.
 */
class CourierService
{
    private ?ShippingServiceClient $shippingClient = null; // the ONLY shipping path
    private array $opts;

    public function __construct()
    {
        $options = (array) (Settings::getData()->options ?? []);
        $this->opts = (array) ($options['courier'] ?? []);
        // Admin-managed shipping-service link config (encrypted in DB) overrides env.
        self::applyAdminPartnerConfig();
    }

    /**
     * Merge admin-managed partner config (courier_partner_configs) over the env-backed
     * config('services.*'). For each partner that has a DB row we set its `enabled` flag and any
     * NON-EMPTY credential/setting field from the DB; empty DB fields fall back to env. Secrets are
     * decrypted by the model's `encrypted:array` cast. Never throws into the courier flow (the
     * table may not exist yet on a fresh deploy before migrate runs).
     */
    public static function applyAdminPartnerConfig(): void
    {
        try {
            $rows = CourierPartnerConfig::all();
        } catch (\Throwable $e) {
            return; // table missing (pre-migration) or DB error — pure-env behavior
        }

        foreach ($rows as $row) {
            $creds    = (array) ($row->credentials ?? []);
            $settings = (array) ($row->settings ?? []);
            $pick = function (array $src, string $key, string $cfg) {
                $v = $src[$key] ?? null;
                return ($v === null || $v === '') ? config($cfg) : $v;
            };

            switch ($row->partner_code) {
                // Partner credentials (Borzo/Shiprocket/Porter) live ONLY in the Go shipping-service
                // env now — the monolith stores/overlays nothing but the service link itself.
                case 'shipping_service':
                    $apiKey = $pick($creds, 'api_key', 'services.shipping_service.api_key');
                    // callback_key mirrors the env default's intent: DB callback_key, else the DB
                    // api_key (same shared secret), else the env-resolved callback_key.
                    $callbackKey = !empty($creds['callback_key'])
                        ? $creds['callback_key']
                        : (!empty($creds['api_key']) ? $creds['api_key'] : config('services.shipping_service.callback_key'));
                    config([
                        'services.shipping_service.url'          => $pick($creds, 'url', 'services.shipping_service.url'),
                        'services.shipping_service.api_key'      => $apiKey,
                        'services.shipping_service.callback_key' => $callbackKey,
                        'services.shipping_service.timeout'      => $pick($settings, 'timeout', 'services.shipping_service.timeout'),
                    ]);
                    break;
            }
        }
    }

    /** The admin master switch (settings.options.courier.enabled) gates EVERY partner lane. */
    private function master(): bool
    {
        return (bool) ($this->opts['enabled'] ?? false);
    }

    /** Courier usable = master switch on AND the shipping service link configured. */
    public function enabled(): bool
    {
        return $this->shippingServiceEnabled();
    }

    /** @deprecated lanes are partner-agnostic now — kept for caller compatibility. */
    public function borzoEnabled(): bool
    {
        return $this->shippingServiceEnabled();
    }

    /** Any courier operation possible (same gate — the service is the only path). */
    public function anyEnabled(): bool
    {
        return $this->shippingServiceEnabled();
    }

    /**
     * The ONLY shipping path: the Go shipping-service. Gated by the admin master switch +
     * a configured service link (url + api key). No env cutover flag anymore — there is no
     * in-process fallback left to cut over from.
     */
    public function shippingServiceEnabled(): bool
    {
        return $this->master() && $this->shippingClient()->configured();
    }

    private function shippingClient(): ShippingServiceClient
    {
        return $this->shippingClient ??= new ShippingServiceClient();
    }

    /**
     * Per-shipment COD to collect = the order's real customer payable (paid_total) apportioned by
     * this shipment's share of the goods subtotal. Single-shipment collects the full payable;
     * degenerate splits evenly. The Go service collects the same amount.
     */
    public function shipmentCodAmount(Shipment $shipment, $order): float
    {
        $explicit = (float) ($shipment->cod_amount ?? 0);
        if ($explicit > 0) {
            return round($explicit, 2);
        }
        if (!$order) {
            return 0.0;
        }
        $payable = (float) ($order->paid_total ?? $order->amount ?? 0);
        if ($payable <= 0) {
            return 0.0;
        }
        $legGoods = (float) $shipment->items->sum(function ($i) {
            $sub = $i->subtotal ?? null;
            return $sub !== null ? (float) $sub : (float) ($i->unit_price ?? 0) * max(1, (int) ($i->order_quantity ?? 1));
        });
        $orderGoods = (float) ($order->amount ?? 0);
        if ($orderGoods <= 0 || $legGoods <= 0) {
            $count = max(1, Shipment::where('order_id', $order->id)->count());
            return round($payable / $count, 2);
        }
        return round($payable * min(1.0, $legGoods / $orderGoods), 2);
    }

    /**
     * Map a service-normalized SHIPMENT status to our {shipment_status, order_status} pair for the
     * shared applyNormalizedStatus seam (the callback receiver uses this).
     */
    public function mapServiceStatus(string $shipmentStatus): array
    {
        switch ($shipmentStatus) {
            case 'delivered':
                return ['shipment_status' => 'delivered', 'order_status' => 'order-completed'];
            case 'out_for_delivery':
                return ['shipment_status' => 'out_for_delivery', 'order_status' => 'order-out-for-delivery'];
            case 'shipped':
                return ['shipment_status' => 'shipped', 'order_status' => 'order-at-local-facility'];
            case 'rto':
                // Terminal bounce. order_status stays null: what happens to the
                // ORDER after an RTO (refund / re-ship / restock) is an operator
                // decision, not a webhook's.
                return ['shipment_status' => 'rto', 'order_status' => null];
            case 'cancelled':
                return ['shipment_status' => 'cancelled', 'order_status' => null];
            case 'assigned':
                return ['shipment_status' => 'assigned', 'order_status' => null];
            default:
                return ['shipment_status' => null, 'order_status' => null];
        }
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
     * Dispatch a shipment: the Go service routes it to the right partner for its mode
     * (courier → Shiprocket; instant/same-city → cheapest of Borzo/Porter), idempotent on
     * shipment_ref so a retry never double-books.
     */
    public function book(Shipment $shipment, ?string $partnerCode = null): array
    {
        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        $order = $shipment->order;
        // Checkout stores CASH_ON_DELIVERY, COD or CASH interchangeably. Comparing with === against
        // one of them booked a 'CASH' order as PREPAID, so the rider was never told to collect and
        // the order value was simply lost. Always ask the enum.
        $cod = $order && PaymentGatewayType::isCashOnDelivery($order->payment_gateway);
        return $this->shippingClient()->book($shipment, $this->modeOf($shipment), (bool) $cod, $this->shipmentCodAmount($shipment, $order), $partnerCode);
    }

    /** Cancel a booked shipment (the service cancels at whichever partner placed it). */
    public function cancel(Shipment $shipment, ?string $reason = null): array
    {
        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        return $this->shippingClient()->cancel($shipment, $reason);
    }

    /**
     * Ranked delivery quotes for this shipment's mode (+ COD), from the Go service's
     * partner fan-out (each partner isolated there; failures surface as partner_errors).
     */
    public function quoteShipment(Shipment $shipment, ?bool $cod = null): array
    {
        $order = $shipment->order;
        $cod = $cod ?? ($order && PaymentGatewayType::isCashOnDelivery($order->payment_gateway));
        $mode = $this->modeOf($shipment);

        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        return $this->shippingClient()->quoteShipment($shipment, $mode, (bool) $cod, $this->shipmentCodAmount($shipment, $order));
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
     * @deprecated The Shiprocket create+AWB sequence runs inside the Go service at dispatch.
     * Kept only so the legacy book-courier route fails cleanly instead of 404ing old clients.
     */
    public function bookShipment(Shipment $shipment): array
    {
        return $this->book($shipment);
    }

    /**
     * Mint the shipping label via the service (Shiprocket only — instant lanes carry none).
     * These were stubs returning ok:false while Capabilities claimed Label:true; the shipments
     * table's label_url column had been waiting unwritten since June.
     */
    public function generateLabel(Shipment $shipment): array
    {
        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        return $this->shippingClient()->generateLabel($shipment);
    }

    /** Schedule the courier pickup via the service. */
    public function schedulePickup(Shipment $shipment): array
    {
        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        return $this->shippingClient()->schedulePickup($shipment);
    }

    public function track(Shipment $shipment): array
    {
        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        return $this->shippingClient()->track($shipment);
    }

    /**
     * Vendor pickup registration is a shipping-service concern now. We still stamp the local
     * pickup nickname/postcode (domain data other flows read), but no partner API is called.
     */
    public function syncPickupLocation(Shop $shop): array
    {
        $addr = (array) ($shop->address ?? []);
        $shop->forceFill([
            'pickup_location_name' => 'shop-' . $shop->id,
            'pickup_postcode'      => $shop->pickup_postcode ?: ($addr['zip'] ?? null),
        ])->save();
        return ['ok' => true, 'pickup_location_name' => $shop->pickup_location_name, 'error' => null];
    }

    /**
     * Map a provider status string to our internal {shipment_status, order_status?}.
     * order_status is null when the provider event shouldn't advance the customer order.
     */
    public function mapStatus(string $providerStatus): array
    {
        $s = strtolower(trim($providerStatus));
        $has = fn (string $needle) => str_contains($s, $needle);

        // RTO must be matched FIRST. Shiprocket's RTO stages embed later keywords —
        // "RTO DELIVERED" contains "delivered", "RTO IN TRANSIT" contains "in transit" —
        // so with delivered checked first, a parcel arriving back at the ORIGIN was
        // recorded as delivered to the customer and the order advanced to completed,
        // firing settlement on a bounced shipment. rto is its own status (not folded
        // into cancelled) so the operator can find and act on returns distinctly;
        // order_status stays null — what happens to the ORDER after a bounce
        // (refund / re-ship / restock) is deliberately an operator decision.
        if ($has('rto') || $has('return to origin')) {
            return ['shipment_status' => 'rto', 'order_status' => null];
        }
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
        if ($has('return')) {
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
        // rto is terminal like delivered/cancelled: a bounced parcel never becomes
        // delivered, and what happens next is an operator decision, not a webhook's.
        if (in_array($cur, ['delivered', 'cancelled', 'rto'], true)) {
            $shipment->forceFill(['last_status_at' => $now])->save();
            return $map; // terminal → sticky
        }
        // rto interrupts like cancelled — a shipment bounces AFTER it has shipped,
        // so the forward-only rank comparison would never admit it.
        if (!in_array($target, ['cancelled', 'rto'], true) && ($rank[$target] ?? 0) <= ($rank[$cur] ?? 0)) {
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
        if ($target === 'rto' && !$shipment->failure_reason) {
            // Reuses the existing failure_reason column rather than adding an
            // rto_reason migration — one nullable free-text field is enough for
            // "track it and let the operator act".
            $fill['failure_reason'] = 'Returned to origin (RTO)';
        }
        $shipment->forceFill($fill)->save();

        // Activity log — sits AFTER the terminal-sticky and backward/no-op early returns,
        // so only REAL forward transitions log (webhook replays record nothing). Covers
        // webhooks, the reconcile poll, and admin mark-RTO — all route through this seam.
        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.status', [
            'shipment_id'    => $shipment->id,
            'from'           => $cur,
            'to'             => $target,
            'failure_reason' => $target === 'rto' ? $shipment->failure_reason : null,
        ]);

        if (!empty($map['order_status'])) {
            $order = $shipment->order;
            // A terminal/cancelled order is a FLOOR: a late or replayed shipment event must never
            // resurrect it (cancelled→completed) and fire vendor settlement on a cancelled order.
            if ($order && in_array($order->order_status, ['order-cancelled', 'order-refunded', 'order-failed'], true)) {
                return $map;
            }
            if ($order) {
                $dest = $map['order_status'];
                if ($dest === 'order-completed') {
                    // Terminal = delivered, cancelled OR rto; an in-flight leg keeps the
                    // order open. rto must be in this list — with it missing, one bounced
                    // leg would hold every multi-leg order at out-for-delivery forever.
                    $inFlight = Shipment::where('order_id', $order->id)
                        ->whereNotIn('status', ['delivered', 'cancelled', 'rto'])->exists();
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

}
