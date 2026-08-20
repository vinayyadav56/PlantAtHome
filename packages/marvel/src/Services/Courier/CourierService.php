<?php

namespace Marvel\Services\Courier;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\CourierPartnerConfig;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorPickupLocation;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\OrderStatus;
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
    /**
     * The RETURN leg (customer → warehouse), ordered. Mirrors the shipping-service's own
     * domain.rtoStage so both sides agree on what "further along" means.
     *
     * Legacy `rto` is stage 1: every row written before the leg was modelled holds it, and the
     * partner mapper still emits it for anything it can only tell is "a return". Being stage 1
     * means such a row can still be advanced by a later in-transit/delivered event instead of
     * being stuck at the first thing we heard.
     */
    private const RTO_STAGES = [
        'rto'            => 1,
        'rto_initiated'  => 1,
        'rto_in_transit' => 2,
        'rto_delivered'  => 3,
    ];

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
            case 'rto_initiated':
            case 'rto_in_transit':
            case 'rto_delivered':
                // The return leg, stage by stage. order_status stays null at every stage: what
                // happens to the ORDER after a bounce (refund / re-ship / restock) is an operator
                // decision, not a webhook's. applyNormalizedStatus persists the leg as `rto` and
                // tracks the stage — see RTO_STAGES there for why.
                return ['shipment_status' => $shipmentStatus, 'order_status' => null];
            case 'ndr':
                // A FAILED delivery attempt, not an end state — the courier reattempts (and
                // auto-RTOs after 3). It used to map to nothing at all, so an undelivered parcel
                // was invisible right up until it bounced.
                return ['shipment_status' => 'ndr', 'order_status' => null];
            case 'cancelled':
                return ['shipment_status' => 'cancelled', 'order_status' => null];
            case 'assigned':
            case 'packed':
                return ['shipment_status' => $shipmentStatus, 'order_status' => null];
            case 'pending':
                // The state a booking starts in. A no-op in practice (book() already stamps it and
                // the rank guard refuses a sideways move), but mapping it explicitly is what keeps
                // `default` meaning "we have never heard of this word" instead of "shrug".
                return ['shipment_status' => 'pending', 'order_status' => null];
            case 'reopened':
                // Porter's assigned rider dropped and it is searching again — the ONE legal step
                // backwards (see PartnerOrderLifecycle, which ranks it beside `accepted` for the
                // same reason). It used to fall through to `default` and vanish: the ledger
                // recorded the reopen, the shipment never did, so the drawer went on showing a
                // rider who had already cancelled. order_status stays null — a reopen is not a
                // customer-visible downgrade.
                return ['shipment_status' => 'reopened', 'order_status' => null];
            default:
                // Every word the shipping service can emit is handled above; reaching here means
                // a partner adapter learned a new one and this table did not. Silently returning
                // null is what let `reopened` disappear for months, so it is now audible.
                if (trim($shipmentStatus) !== '') {
                    Log::warning('courier.status.unmapped', ['normalized_status' => $shipmentStatus]);
                }
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
    public function book(Shipment $shipment, ?string $partnerCode = null, ?int $courierId = null, array $options = []): array
    {
        // Root-cause guard for SELF-delivery vendors: this is the single funnel
        // every booking path routes through (auto-book listener, admin book +
        // dispatch buttons, legacy bookShipment) — a self leg must never reach
        // a courier partner regardless of which caller asked.
        if ($shipment->isSelfDelivery()) {
            return ['ok' => false, 'code' => 'SELF_DELIVERY', 'error' => 'Self-delivery shipment — the vendor fulfils this one; no courier booking.'];
        }
        if (!$this->shippingServiceEnabled()) {
            return ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
        }
        // One vendor shipment = one live booking. The service is idempotent on shipment_ref anyway,
        // but refusing here gives the operator a plain answer instead of a silently-deduped 200.
        // A CANCELLED booking is rebookable (the service reopens the ref with a fresh attempt).
        if (($shipment->provider_order_id || $shipment->awb_number) && $shipment->status !== 'cancelled') {
            return ['ok' => false, 'error' => 'This vendor shipment is already booked — cancel the existing booking first to rebook.'];
        }
        // Courier lanes reference the vendor's pickup location BY NICKNAME, and Shiprocket rejects
        // the order if that nickname was never registered on the account. Refuse here with an
        // actionable message: the alternative is a partner 422 per booking that reads like a
        // Shiprocket outage. Hyperlocal lanes carry coordinates instead, so they are exempt.
        if ($this->modeOf($shipment) === 'courier' && ($err = $this->pickupBlocker($shipment))) {
            return ['ok' => false, 'code' => 'PICKUP_NOT_REGISTERED', 'error' => $err];
        }

        $order = $shipment->order;
        // Checkout stores CASH_ON_DELIVERY, COD or CASH interchangeably. Comparing with === against
        // one of them booked a 'CASH' order as PREPAID, so the rider was never told to collect and
        // the order value was simply lost. Always ask the enum.
        $cod = $order && PaymentGatewayType::isCashOnDelivery($order->payment_gateway);
        // $courierId travels as an ARGUMENT, never off the row: see buildRequest's note — a
        // persisted choice replayed on a later booking is a stale rate card.
        return $this->shippingClient()->book($shipment, $this->modeOf($shipment), (bool) $cod, $this->shipmentCodAmount($shipment, $order), $partnerCode, $courierId, $options);
    }

    /**
     * The vendor door THIS leg leaves from: the shipment's own pickup_location_id, else the
     * vendor's default row. ALWAYS scoped to $shipment->shop_id, so a stale or hand-edited
     * pickup_location_id can never book one vendor's parcel out of another vendor's yard.
     *
     * Null when the vendor has no rows (or the table predates this deploy) — callers then fall
     * back to the legacy shops.pickup_location_name, which is what keeps every already-live
     * vendor booking working with no backfill.
     */
    public function pickupLocationFor(Shipment $shipment): ?VendorPickupLocation
    {
        if (!$shipment->shop_id) {
            return null;
        }
        try {
            $rows = VendorPickupLocation::where('shop_id', $shipment->shop_id)
                ->orderByDesc('is_default')->orderBy('id')->get();
        } catch (\Throwable $e) {
            return null; // table not migrated yet — the legacy shop column still answers
        }
        if ($rows->isEmpty()) {
            return null;
        }
        if ($shipment->pickup_location_id) {
            $own = $rows->firstWhere('id', (int) $shipment->pickup_location_id);
            if ($own) {
                return $own;
            }
        }
        return $rows->first(); // is_default first, then oldest
    }

    /**
     * The `pickup_location` nickname this leg books under; '' when the vendor has no usable door.
     * $location skips the lookup for callers that already resolved it.
     */
    public function pickupNameFor(Shipment $shipment, ?VendorPickupLocation $location = null): string
    {
        $location = $location ?: $this->pickupLocationFor($shipment);
        if ($location) {
            // A resolved door the partner never accepted books NOTHING: falling back to the
            // vendor's legacy nickname here would ship the parcel out of a different yard.
            return $location->isUsable() ? (string) $location->provider_location_name : '';
        }
        return trim((string) ($shipment->shop->pickup_location_name ?? ''));
    }

    /**
     * Why this shipment cannot be booked on a courier lane yet, or null when it can.
     *
     * Reads the pickup off THIS shipment (its own door, else its vendor's default, else the
     * legacy shop nickname) — the same resolution buildRequest sends — so one vendor's shipment
     * can never be booked against another vendor's pickup location, and a shipment whose door
     * Shiprocket never accepted is refused rather than silently sent with a nickname it does
     * not know.
     */
    public function pickupBlocker(Shipment $shipment): ?string
    {
        $shop = $shipment->shop;
        if (!$shop) {
            return 'This shipment has no vendor, so it has no pickup location.';
        }
        $vendor = $shop->name ?: "Vendor #{$shop->id}";
        $location = $this->pickupLocationFor($shipment);

        if ($this->pickupNameFor($shipment, $location) === '') {
            return $location
                ? sprintf(
                    '%s: pickup location "%s" is %s, not registered with Shiprocket. Register it first.',
                    $vendor,
                    $location->label,
                    $location->status,
                )
                : sprintf(
                    '%s has no registered Shiprocket pickup location. Use "Register Pickup" on this vendor group first.',
                    $vendor,
                );
        }
        if ($missing = $this->missingPickupFields($shop, $location)) {
            return sprintf(
                '%s is missing %s on its pickup address — Shiprocket will refuse the booking.',
                $vendor,
                implode(', ', $missing),
            );
        }
        return null;
    }

    /**
     * Validate an operator-chosen courier against a FRESH quote for this shipment and persist it.
     *
     * A courier id off the client is untrusted input on a money path: a stale tab, a hand-crafted
     * request or a lane change since the quote was rendered all name a courier the partner is not
     * offering right now, and Shiprocket would either refuse the AWB or allocate a different (and
     * differently priced) courier. Only an id the partner just quoted for THIS shipment is stored.
     *
     * The persisted courier_company_id is DISPLAY ONLY. The validated id is returned so the caller
     * can hand it to the booking it just validated it for — nothing reads the column back into a
     * partner request (see ShippingServiceClient::buildRequest).
     */
    public function chooseCourier(Shipment $shipment, int $courierId): array
    {
        $res = $this->quoteShipment($shipment);
        $quotes = (array) ($res['quotes'] ?? []);

        foreach ($quotes as $q) {
            $q = (array) $q;
            if ($courierId > 0 && (int) ($q['courier_id'] ?? 0) === $courierId) {
                $shipment->forceFill([
                    'courier_company_id' => $courierId,
                    // Provisional: book() overwrites it with whatever the partner actually allocated.
                    'courier_name'       => ((string) ($q['courier_name'] ?? '')) ?: $shipment->courier_name,
                ])->save();
                return ['ok' => true, 'courier_id' => $courierId, 'quote' => $q];
            }
        }

        return [
            'ok'      => false,
            'code'    => 'COURIER_NOT_QUOTED',
            'error'   => $quotes
                ? 'That courier is not being offered for this shipment right now — re-fetch the rates and pick again.'
                : ($res['error'] ?? 'No courier rates are available for this shipment right now.'),
            'offered' => array_values(array_filter(array_map(
                static fn ($q) => (int) (((array) $q)['courier_id'] ?? 0),
                $quotes,
            ))),
        ];
    }

    /**
     * The one gate every service-backed operation sits behind: the refusal array, or null when the
     * call may proceed. `return $this->offline() ?? $this->shippingClient()->x()` reads as the
     * guard it is, and keeps the operator-facing wording in a single place.
     */
    private function offline(): ?array
    {
        return $this->shippingServiceEnabled()
            ? null
            : ['ok' => false, 'error' => 'Courier is off or the shipping service is not configured.'];
    }

    /** Cancel a booked shipment (the service cancels at whichever partner placed it). */
    public function cancel(Shipment $shipment, ?string $reason = null): array
    {
        return $this->offline() ?? $this->shippingClient()->cancel($shipment, $reason);
    }

    /**
     * Cancel only the AWB, leaving the partner order alive — what a reassignment or a re-pack
     * needs. cancel() above kills the whole order.
     */
    /** Create an exchange (replacement out, original collected back) — both legs in one call. */
    public function createExchange(Shipment $shipment, array $payload): array
    {
        return $this->offline() ?? $this->shippingClient()->createExchange($shipment, $payload);
    }

    /** Retry waybill allocation for a shipment stuck without one. */
    public function assignAwb(Shipment $shipment): array
    {
        return $this->offline() ?? $this->shippingClient()->assignAwb($shipment);
    }

    public function cancelAwb(Shipment $shipment, ?string $reason = null): array
    {
        return $this->offline() ?? $this->shippingClient()->cancelAwb($shipment, $reason);
    }

    /**
     * Move a booked shipment onto a different courier. The id is validated against a FRESH quote
     * first — same rule as booking: an id off the client is untrusted input on a money path, and a
     * reassignment re-prices the leg exactly like a new booking does.
     */
    public function reassignCourier(Shipment $shipment, int $courierId): array
    {
        if ($res = $this->offline()) {
            return $res;
        }
        $chosen = $this->chooseCourier($shipment, $courierId);
        if (empty($chosen['ok'])) {
            return $chosen;
        }
        return $this->shippingClient()->reassignCourier($shipment, $courierId);
    }

    /** Partner invoice PDF (persists invoice_url). */
    public function generateInvoice(Shipment $shipment): array
    {
        return $this->offline() ?? $this->shippingClient()->generateInvoice($shipment);
    }

    /** Manifest for one shipment (persists manifest_url). */
    public function generateManifest(Shipment $shipment): array
    {
        return $this->offline() ?? $this->shippingClient()->generateManifest($shipment);
    }

    /** ONE manifest across a batch — the sheet the driver signs for the whole pickup. */
    public function generateManifestBulk($shipments): array
    {
        return $this->offline() ?? $this->shippingClient()->generateManifestBulk($shipments);
    }

    /** Open NDRs at the partner (the 3-attempt countdown before an automatic RTO). */
    public function ndrList(array $query = []): array
    {
        return $this->offline() ?? $this->shippingClient()->ndrList($query);
    }

    /** One NDR by waybill; syncs the counters onto $shipment when we hold that row. */
    public function ndrDetail(string $awb, ?Shipment $shipment = null): array
    {
        return $this->offline() ?? $this->shippingClient()->ndrDetail($awb, $shipment);
    }

    /** Submit an NDR action (reattempt / return / …) — the last chance before the auto-RTO. */
    public function ndrAction(Shipment $shipment, string $action, ?string $comments = null): array
    {
        return $this->offline() ?? $this->shippingClient()->ndrAction($shipment, $action, $comments);
    }

    /** Customer return = a REVERSE shipment with its own AWB. Not an RTO. */
    public function createReturn(Shipment $shipment, ?string $reason = null): array
    {
        return $this->offline() ?? $this->shippingClient()->createReturn($shipment, $reason);
    }

    /**
     * Ranked delivery quotes for this shipment's mode (+ COD), from the Go service's
     * partner fan-out (each partner isolated there; failures surface as partner_errors).
     */
    /**
     * $mode quotes a lane OTHER than the shipment's persisted one, so the admin can show every
     * delivery option at once (partners are mode-exclusive: Shiprocket serves `courier`, Porter
     * and Borzo serve `instant`/`same_city`, so one lane can only ever return half the picture).
     * Read-only by design — quoting a lane must never persist it; that is updateMode's job.
     */
    public function quoteShipment(Shipment $shipment, ?bool $cod = null, ?string $mode = null): array
    {
        $order = $shipment->order;
        $cod = $cod ?? ($order && PaymentGatewayType::isCashOnDelivery($order->payment_gateway));
        $mode = $mode ?: $this->modeOf($shipment);

        return $this->offline() ?? $this->shippingClient()->quoteShipment($shipment, $mode, (bool) $cod, $this->shipmentCodAmount($shipment, $order));
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
        return $this->offline() ?? $this->shippingClient()->generateLabel($shipment);
    }

    /** Schedule the courier pickup via the service (persists the slot + token it returns). */
    public function schedulePickup(Shipment $shipment): array
    {
        return $this->offline() ?? $this->shippingClient()->schedulePickup($shipment);
    }

    public function track(Shipment $shipment): array
    {
        return $this->offline() ?? $this->shippingClient()->track($shipment);
    }

    /**
     * Register a vendor pickup location: register it AT Shiprocket via the shipping service, then
     * record what the partner said on the VendorPickupLocation row (and, for the vendor's default
     * door, on the legacy shop columns other flows still read). The create call references
     * pickup_location by nickname — an unregistered one 422s every booking, and for a long time
     * nothing here actually registered it (the button was a local-only stamp).
     *
     * $location is the specific door; omitted, it means the vendor's default (or, for a vendor
     * with no rows at all, the pure legacy path).
     */
    public function syncPickupLocation(Shop $shop, ?VendorPickupLocation $location = null): array
    {
        $addr = (array) ($shop->address ?? []);
        $location = $location ?: $this->defaultPickupLocation($shop);
        $legacyName = 'shop-' . $shop->id;
        // The nickname is the partner's own key for a door, so an already-registered one is reused
        // verbatim; the default door keeps the legacy shop-{id} nickname every live booking already
        // sends, and each extra yard gets a suffix of its own.
        $nickname = trim((string) ($location->provider_location_name ?? ''))
            ?: (($location && !$location->is_default) ? $legacyName . '-' . $location->id : $legacyName);
        $postcode = trim((string) ($location->pincode ?? '')) ?: ($shop->pickup_postcode ?: ($addr['zip'] ?? null));

        // The local stamp is what every booking sends as `pickup_location`. It used to be written
        // HERE — before the partner call and regardless of its outcome — so a FAILED registration
        // was indistinguishable from a good one, and every later booking quoted a nickname that
        // did not exist at Shiprocket. Nothing is persisted now until the partner confirms.
        if (!$this->shippingServiceEnabled()) {
            return [
                'ok'                   => false,
                'pickup_location_name' => $shop->pickup_location_name,
                'error'                => 'Courier is off or the shipping service is not configured — nothing was registered.',
            ];
        }

        // Answer with the field names rather than relaying Shiprocket's opaque 422.
        if ($missing = $this->missingPickupFields($shop, $location)) {
            $error = 'This vendor is missing ' . implode(', ', $missing)
                . '. Shiprocket refuses a pickup location without them — fix the vendor address, then register again.';
            $this->stampPickupFailure($location, $error);
            return [
                'ok'                   => false,
                'pickup_location_name' => $shop->pickup_location_name,
                'error'                => $error,
            ];
        }

        $res = $this->shippingClient()->registerPickup($shop, $nickname, $location);
        $ok  = (bool) ($res['ok'] ?? false);

        if ($ok) {
            $location?->forceFill([
                'status'                 => 'verified',
                'provider_location_name' => $nickname,
                // Shiprocket's own id for the door (addpickup → address.pickup_code); absent on the
                // "already registered" path, so never overwrite a code we already hold with null.
                'provider_pickup_code'   => ($res['pickup_code'] ?? null) ?: $location->provider_pickup_code,
                'last_synced_at'         => Carbon::now(),
                'last_error'             => null,
            ])->save();
            // Only the door that owns the legacy nickname stamps the shop columns — a second yard
            // would otherwise silently repoint every booking that still reads them.
            if ($nickname === $legacyName) {
                $shop->forceFill([
                    'pickup_location_name' => $nickname,
                    'pickup_postcode'      => $postcode,
                ])->save();
            }
        } else {
            $this->stampPickupFailure($location, (string) ($res['error'] ?? 'Registration failed.'));
        }

        return [
            'ok'                   => $ok,
            'pickup_location_name' => $ok ? $nickname : $shop->pickup_location_name,
            'pickup_code'          => $res['pickup_code'] ?? null,
            'location_id'          => $location?->id,
            'outcome'              => $res['outcome'] ?? null,
            'error'                => $res['error'] ?? null,
        ];
    }

    /** The vendor's default door (is_default first, then oldest), or null when it has none. */
    public function defaultPickupLocation(Shop $shop): ?VendorPickupLocation
    {
        try {
            return VendorPickupLocation::where('shop_id', $shop->id)
                ->orderByDesc('is_default')->orderBy('id')->first();
        } catch (\Throwable $e) {
            return null; // table not migrated yet — pure legacy path
        }
    }

    /**
     * Record a failed registration on the row. This is the whole point of the status column:
     * "never registered" and "registration failed last Tuesday" used to look identical, and the
     * operator only found out at the 422 on a real booking.
     */
    private function stampPickupFailure(?VendorPickupLocation $location, string $error): void
    {
        $location?->forceFill([
            'status'         => 'failed',
            'last_synced_at' => Carbon::now(),
            'last_error'     => mb_substr($error, 0, 255),
        ])->save();
    }

    /**
     * The pickup fields Shiprocket rejects a location without — checked before we call, so the
     * operator is told which fields to fill instead of receiving a partner validation error.
     */
    public function missingPickupFields(Shop $shop, ?VendorPickupLocation $location = null): array
    {
        $a = $this->shippingClient()->pickupAddressOf($shop, $location);
        $need = [
            'address' => 'street address',
            'city'    => 'city',
            'state'   => 'state',
            'pincode' => 'pincode',
            'phone'   => 'phone',
        ];

        $missing = [];
        foreach ($need as $key => $label) {
            if (trim((string) ($a[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
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
            // The return leg has stages of its own, and they embed forward keywords the same way:
            // "RTO DELIVERED" means back AT THE ORIGIN, not delivered to the customer.
            if ($has('delivered') || $has('acknowledged') || $has('received')) {
                return ['shipment_status' => 'rto_delivered', 'order_status' => null];
            }
            if ($has('transit') || $has('dispatched') || $has('out for pickup')) {
                return ['shipment_status' => 'rto_in_transit', 'order_status' => null];
            }
            return ['shipment_status' => 'rto', 'order_status' => null];
        }
        // BEFORE the delivered check, and not optional: "Undelivered" CONTAINS "delivered", so a
        // failed delivery attempt was being recorded as delivered — completing the order and
        // firing vendor settlement on a parcel still sitting in the courier's van.
        if ($has('undelivered') || $has('ndr') || $has('not delivered') || $has('delivery attempt')) {
            return ['shipment_status' => 'ndr', 'order_status' => null];
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
     * How far along the return leg a shipment already is (0 = not returning).
     *
     * The stage lives in last_status because the status column flattens the whole leg to `rto`
     * (see applyNormalizedStatus). A row whose status is `rto` but whose last_status predates
     * staging still counts as stage 1, so it can be advanced rather than treated as finished.
     */
    private function rtoStage(string $status, string $lastStatus): int
    {
        return self::RTO_STAGES[$lastStatus] ?? (self::RTO_STAGES[$status] ?? 0);
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

        // Monotonic + terminal-sticky. Once delivered/cancelled/back-at-origin, ignore later events;
        // otherwise only move FORWARD (cancelled may interrupt any pre-terminal state). This makes
        // out-of-order or duplicate webhooks (common across multi-leg orders, esp. Borzo jumping
        // straight to out_for_delivery) a true no-op instead of regressing the shipment — and never
        // reverses delivered_at or fires settlement-reversing order downgrades.
        //
        // ndr shares out_for_delivery's rank: a failed attempt is the same point of the journey,
        // not a step past it — and the reattempt (out_for_delivery again) has to be able to follow
        // it, which a strict `>` would refuse, freezing the shipment at ndr forever.
        //
        // `reopened` shares assigned's rank for the same reason ndr shares out_for_delivery's: it
        // is the same point of the journey re-entered, not a step past it, and the rider that
        // follows it (assigned again) has to be able to land.
        $rank = ['pending' => 0, 'assigned' => 1, 'reopened' => 1, 'packed' => 1, 'shipped' => 2, 'in_transit' => 2, 'out_for_delivery' => 3, 'ndr' => 3, 'delivered' => 4];
        // States that may interrupt any pre-terminal status, and that a shipment must be able to
        // LEAVE at the same rank it entered them (otherwise it freezes there forever).
        $interrupts = ['cancelled', 'ndr', 'reopened'];
        $resumable  = ['ndr', 'reopened'];
        $cur = (string) $shipment->status;
        $curStage = $this->rtoStage($cur, (string) $shipment->last_status);
        $inStage  = self::RTO_STAGES[$target] ?? 0;

        // Terminal = delivered, cancelled, or a COMPLETED return leg. The first RTO stage used to
        // be terminal, which made every later stage invisible: a parcel marked "RTO initiated"
        // could never be seen to arrive back, so ops had no way to know a refund was safe.
        if (in_array($cur, ['delivered', 'cancelled'], true) || $curStage >= 3) {
            $shipment->forceFill(['last_status_at' => $now])->save();
            return $map; // terminal → sticky
        }
        // Repeat of the state we are already in. Explicit because `ndr` and `cancelled` interrupt
        // by design and so skip the rank comparison below — without this, every duplicate NDR
        // webhook would re-log a transition that never happened.
        if ($target === $cur && $inStage === 0) {
            $shipment->forceFill(['last_status_at' => $now])->save();
            return $map;
        }
        if ($inStage > 0) {
            // The return leg interrupts any forward status (a parcel bounces AFTER it has shipped,
            // so a rank comparison would never admit it) and from then on only advances.
            if ($inStage <= $curStage) {
                $shipment->forceFill(['last_status_at' => $now])->save();
                return $map; // same or earlier stage → no-op
            }
        } elseif ($curStage > 0) {
            // Already heading back: nothing on the forward leg applies any more (a late
            // "out for delivery" must not un-bounce it). An operator cancel still can.
            if ($target !== 'cancelled') {
                $shipment->forceFill(['last_status_at' => $now])->save();
                return $map;
            }
        } elseif (!in_array($target, $interrupts, true) && ($rank[$target] ?? 0) <= ($rank[$cur] ?? 0)) {
            // cancelled, ndr and reopened interrupt at any pre-terminal point (all three happen
            // mid-journey, so a forward-only rank comparison would never admit them). Everything
            // else must move up the rank — except the move OUT of an interrupt state, which sits
            // at the same rank and would otherwise be rejected, freezing the shipment there
            // forever (the ndr reattempt, and the second rider after a Porter reopen).
            if (!(in_array($cur, $resumable, true) && ($rank[$target] ?? 0) >= ($rank[$cur] ?? 0))) {
                $shipment->forceFill(['last_status_at' => $now])->save();
                return $map; // same or backward → no-op
            }
        }

        // What the shipment is moving FROM, for the activity log: inside the return leg that is the
        // previous stage, not the flattened `rto` the status column carries.
        $from = $curStage > 0 && $shipment->last_status ? (string) $shipment->last_status : $cur;

        // Every stage of the return leg persists as `status = 'rto'`: that one value is what the
        // rest of the system (order-completion guard, the has_rto order filter, the reconcile and
        // sweep commands) already understands as "bounced", and splitting it into three would make
        // a parcel silently vanish from all of them mid-return. The STAGE lives in last_status.
        $fill = [
            'status'         => $inStage > 0 ? 'rto' : $target,
            'last_status'    => $target,
            'last_status_at' => $now,
        ];
        if ($target === 'shipped' && !$shipment->shipped_at) {
            $fill['shipped_at'] = $now;
        }
        if ($target === 'delivered') {
            $fill['delivered_at'] = $now;
        }
        if ($inStage > 0) {
            if (!$shipment->rto_at) {
                $fill['rto_at'] = $now; // when it turned back, stamped once at the first stage
            }
            if (!$shipment->failure_reason) {
                // Reuses the existing failure_reason column rather than adding an
                // rto_reason migration — one nullable free-text field is enough for
                // "track it and let the operator act".
                $fill['failure_reason'] = 'Returned to origin (RTO)';
            }
        }
        $shipment->forceFill($fill)->save();

        // Activity log — sits AFTER the terminal-sticky and backward/no-op early returns,
        // so only REAL forward transitions log (webhook replays record nothing). Covers
        // webhooks, the reconcile poll, and admin mark-RTO — all route through this seam.
        \Marvel\Database\Models\OrderEvent::record($shipment->order_id, 'shipment.status', [
            'shipment_id'    => $shipment->id,
            'from'           => $from,
            'to'             => $target,
            'failure_reason' => $inStage > 0 ? $shipment->failure_reason : null,
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
                $orank = OrderStatus::ranks();
                $from = $orank[$order->order_status] ?? null;
                $to   = $orank[$dest] ?? null;
                $advance = $from === null || $to === null || $to > $from;
                if ($order->order_status !== $dest && $advance) {
                    // The partner is authoritative, so record the destination it actually
                    // reported in ONE transition. Walking the skipped rungs instead would
                    // write status history for states that never happened and fire a separate
                    // customer notification for each fabricated one.
                    app(OrderRepository::class)->changeOrderStatus($order, $dest, true);
                }
            }
        }
        return $map;
    }

}
