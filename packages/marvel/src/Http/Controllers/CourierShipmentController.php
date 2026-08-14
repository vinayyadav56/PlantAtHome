<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorPickupLocation;
use Marvel\Enums\Permission;
use Marvel\Services\Courier\CourierService;

/**
 * Admin courier operations (C3): book a courier shipment with the provider, (re)allocate the
 * AWB, generate the label, schedule pickup, live-track, and register a vendor pickup location.
 * All idempotent + partial-failure aware (booking persists provider_order_id before the AWB
 * step, so a retry only runs the missing step). Inert when courier is not enabled.
 */
class CourierShipmentController extends CoreController
{
    private function courier(): CourierService
    {
        return new CourierService();
    }

    private function shipment($id): Shipment
    {
        return Shipment::with(['order.customer', 'shop', 'items.product'])->findOrFail($id);
    }

    /** POST shipments/{id}/book-courier — create Shiprocket provider order + allocate AWB. */
    public function book(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->rejectIncompleteAddress($shipment)) {
            return $resp;
        }
        $this->assertCustomerLocation($shipment);
        $res = $this->courier()->bookShipment($shipment);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * GET shipments/{id}/shipping-quotes — ranked quotes across every eligible partner.
     *
     * Optional `mode` quotes a lane other than the shipment's own. Partners are mode-exclusive,
     * so the admin asks for each lane in turn to list every delivery option; this is a read and
     * must not persist the lane (POST shipping-mode is the write).
     */
    public function quotes(Request $request, $id)
    {
        $cod = $request->has('cod') ? $request->boolean('cod') : null;

        $mode = $request->input('mode');
        if ($mode !== null && !in_array($mode, ['instant', 'same_city', 'courier'], true)) {
            return response()->json([
                'ok'    => false,
                'error' => 'mode must be one of: instant, same_city, courier.',
            ], 422);
        }

        return response()->json($this->courier()->quoteShipment($this->shipment($id), $cod, $mode));
    }

    /**
     * POST shipments/{id}/dispatch — book the shipment via the partner for its mode
     * (courier → Shiprocket, instant/same-city → Borzo). Idempotent on provider_order_id.
     *
     * NB: named dispatchShipment (not dispatch) — CoreController pulls in the
     * DispatchesJobs trait whose dispatch($job) signature would clash and fatal
     * the whole controller (and route:list) at link time.
     */
    public function dispatchShipment(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->rejectIncompleteAddress($shipment)) {
            return $resp;
        }
        $this->assertCustomerLocation($shipment);
        // Optional `partner` books the specific quote the operator chose instead of
        // letting the service re-route. Not validated against a list here on purpose:
        // the shipping service already checks the code against the same candidacy
        // rules (mode, COD, master switch) and returns "partner not available: X",
        // so a second allowlist in PHP would be a copy of those rules that can drift.
        $partner = $request->input('partner');
        $partner = is_string($partner) && $partner !== '' ? $partner : null;

        // Optional `mode` books the lane the operator's chosen quote was priced on. book() reads
        // the lane off the shipment row, so without this, picking a hyperlocal quote on a
        // courier-lane shipment fails with "partner not available: porter". Validated here
        // (unlike `partner`) because it is written to the row, not just forwarded.
        $mode = $request->input('mode');
        if ($mode !== null && $mode !== '') {
            if (!in_array($mode, ['instant', 'same_city', 'courier'], true)) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'mode must be one of: instant, same_city, courier.',
                ], 422);
            }
            if ($shipment->mode !== $mode) {
                $shipment->forceFill(['mode' => $mode])->save();
            }
        }

        // Optional `courier_id` books the exact courier the operator picked off the rate list.
        // NEVER trusted: a stale tab, a lane change since the rates were rendered, or a crafted
        // request all name a courier the partner is not offering, and Shiprocket would then refuse
        // the AWB or allocate a differently-priced one. Re-validated against a FRESH quote for this
        // shipment (after the mode write above, so it is quoted on the lane it will book on) and
        // only persisted when the partner is actually offering it.
        $courierId = (int) $request->input('courier_id', 0);
        if ($courierId > 0) {
            $chosen = $this->courier()->chooseCourier($shipment, $courierId);
            if (empty($chosen['ok'])) {
                return response()->json($chosen, 422);
            }
        }

        $res = $this->courier()->book($shipment, $partner);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * POST shipments/{id}/shipping-mode — override which lane this shipment ships on.
     *
     * Partners are mode-exclusive: Shiprocket serves `courier`, Porter and Borzo serve
     * `instant`/`same_city`. So a shipment classified into the wrong lane can never be
     * quoted by the partner that should carry it, which is what "why is Shiprocket not
     * showing" turned out to be. This sets `shipment.mode`, which CourierService::modeOf()
     * already prefers over the derived `fulfillment_mode` — no new mapping.
     *
     * Refused once the shipment is booked: the mode is what the partner was chosen on,
     * and changing it afterwards would leave the record describing a lane the live
     * booking is not on.
     */
    public function updateMode(Request $request, $id)
    {
        $mode = (string) $request->input('mode');
        if (!in_array($mode, ['instant', 'same_city', 'courier'], true)) {
            return response()->json([
                'ok'    => false,
                'error' => 'mode must be one of: instant, same_city, courier.',
            ], 422);
        }

        $shipment = $this->shipment($id);
        if ($shipment->isSelfDelivery()) {
            return response()->json([
                'ok'    => false,
                'error' => 'Self-delivery shipments have no courier lane.',
            ], 409);
        }
        if ($shipment->provider_order_id || $shipment->awb_number) {
            return response()->json([
                'ok'    => false,
                'error' => 'This shipment is already booked. Cancel it before changing its shipping mode.',
            ], 409);
        }

        $shipment->forceFill(['mode' => $mode])->save();

        return response()->json(['ok' => true, 'shipment' => $shipment->fresh()]);
    }

    /**
     * POST shipments/{id}/mark-rto — record that this shipment bounced back to origin.
     *
     * The manual half of RTO tracking: webhooks mark it automatically when the partner
     * reports an RTO stage, but a phone call from a rider or a partner dashboard is often
     * how the operator actually learns. Goes through applyNormalizedStatus — the same seam
     * as webhooks — so terminal-stickiness and the order-completion guard apply identically;
     * a manual mark cannot do anything a webhook couldn't.
     *
     * Deliberately does NOT restock or refund. The operator decides what happens to the
     * order after a bounce.
     */
    public function markRto(Request $request, $id)
    {
        $shipment = $this->shipment($id);

        if (in_array((string) $shipment->status, ['delivered', 'cancelled', 'rto'], true)) {
            return response()->json([
                'ok'    => false,
                'error' => "This shipment is already {$shipment->status}.",
            ], 409);
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason !== '') {
            $shipment->forceFill(['failure_reason' => $reason])->save();
        }

        $this->courier()->applyNormalizedStatus($shipment, ['shipment_status' => 'rto', 'order_status' => null]);

        return response()->json(['ok' => true, 'shipment' => $shipment->fresh()]);
    }

    /** POST shipments/{id}/cancel-shipment — cancel via whichever partner placed it. */
    public function cancelShipment(Request $request, $id)
    {
        $res = $this->courier()->cancel($this->shipment($id), $request->input('reason'));
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** POST shipments/{id}/generate-label */
    public function label(Request $request, $id)
    {
        return response()->json($this->courier()->generateLabel($this->shipment($id)));
    }

    /** POST shipments/{id}/schedule-pickup */
    public function pickup(Request $request, $id)
    {
        return response()->json($this->courier()->schedulePickup($this->shipment($id)));
    }

    /** GET shipments/{id}/courier-track — live status (admin view). */
    public function track(Request $request, $id)
    {
        return response()->json($this->courier()->track($this->shipment($id)));
    }

    /** POST shops/{id}/sync-pickup — register the vendor's DEFAULT door as a provider pickup location. */
    public function syncPickup(Request $request, $id)
    {
        $shop = Shop::findOrFail($id);
        return response()->json($this->courier()->syncPickupLocation($shop));
    }

    // ── vendor pickup locations (the doors a vendor loads from) ───────────────
    // shops.pickup_location_name could only ever name ONE door and carried no state, so a nursery
    // that loads from two yards had no way to say so and nothing recorded whether the partner had
    // actually accepted the location. These rows do both; the legacy column still answers for
    // vendors with no rows.

    /** GET shops/{id}/pickup-locations */
    public function pickupLocations(Request $request, $id)
    {
        Shop::findOrFail($id);
        return response()->json([
            'ok'        => true,
            'locations' => VendorPickupLocation::where('shop_id', $id)
                ->orderByDesc('is_default')->orderBy('id')->get(),
        ]);
    }

    /** POST shops/{id}/pickup-locations — add a door. Registering it is a separate, explicit step. */
    public function storePickupLocation(Request $request, $id)
    {
        $shop = Shop::findOrFail($id);
        $data = $this->validatePickupLocation($request, true);
        // A vendor's first door is its default — otherwise nothing would resolve until someone
        // remembered to press "set default".
        //
        // ...EXCEPT while the vendor is still booking through its legacy shops.pickup_location_name.
        // A brand-new door is `pending` until Shiprocket accepts it, and a resolved-but-unusable
        // door deliberately does NOT fall back to the legacy nickname (that would ship the parcel
        // out of a different yard). So auto-defaulting here would take a vendor that books fine
        // today and block every one of its shipments the moment an operator merely ADDS an address.
        // Let the explicit flag, or a successful registration, promote it instead.
        $booksOnLegacy = trim((string) ($shop->pickup_location_name ?? '')) !== '';
        $data['is_default'] = (bool) ($data['is_default'] ?? false)
            || (!$booksOnLegacy && !VendorPickupLocation::where('shop_id', $shop->id)->exists());

        $location = VendorPickupLocation::create($data + ['shop_id' => $shop->id]);
        if ($location->is_default) {
            $this->demoteOtherDefaults($location);
        }

        return response()->json(['ok' => true, 'location' => $location], 201);
    }

    /**
     * PUT pickup-locations/{id}
     *
     * ponytail: a local edit does NOT reach a door already registered — Shiprocket's API has
     * addpickup and a list, no update, and re-registering an existing nickname answers "already
     * registered" without changing the address. To move a registered door, add a new label.
     */
    public function updatePickupLocation(Request $request, $id)
    {
        $location = VendorPickupLocation::findOrFail($id);
        $location->fill($this->validatePickupLocation($request, false))->save();
        if ($location->is_default) {
            $this->demoteOtherDefaults($location);
        }
        return response()->json(['ok' => true, 'location' => $location->fresh()]);
    }

    /** POST pickup-locations/{id}/register — register THIS door at the partner. */
    public function registerPickupLocation(Request $request, $id)
    {
        $location = VendorPickupLocation::findOrFail($id);
        $shop = Shop::findOrFail($location->shop_id);

        $res = $this->courier()->syncPickupLocation($shop, $location);

        // A door the partner has now accepted becomes the vendor's default when it has no other
        // usable one. This is the migration off the legacy shops.pickup_location_name: creating a
        // door deliberately does NOT promote it (a pending door would block every shipment), so
        // acceptance is the moment it is safe to route through.
        if (!empty($res['ok']) && !$location->fresh()->is_default) {
            $hasUsableDefault = VendorPickupLocation::where('shop_id', $shop->id)
                ->where('id', '!=', $location->id)
                ->where('is_default', true)
                ->get()
                ->contains(fn ($l) => $l->isUsable());

            if (!$hasUsableDefault) {
                DB::transaction(function () use ($location) {
                    $location->forceFill(['is_default' => true])->save();
                    $this->demoteOtherDefaults($location);
                });
            }
        }

        return response()->json(
            $res + ['location' => $location->fresh()],
            !empty($res['ok']) ? 200 : 409,
        );
    }

    /** POST pickup-locations/{id}/set-default — the door every leg of this vendor leaves from. */
    public function setDefaultPickupLocation(Request $request, $id)
    {
        $location = VendorPickupLocation::findOrFail($id);
        DB::transaction(function () use ($location) {
            $location->forceFill(['is_default' => true])->save();
            $this->demoteOtherDefaults($location);
        });
        return response()->json(['ok' => true, 'location' => $location->fresh()]);
    }

    /** Exactly one default per vendor+partner — resolution reads the first one it finds. */
    private function demoteOtherDefaults(VendorPickupLocation $location): void
    {
        VendorPickupLocation::where('shop_id', $location->shop_id)
            ->where('partner', $location->partner)
            ->where('id', '!=', $location->id)
            ->update(['is_default' => false]);
    }

    private function validatePickupLocation(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'label'        => [$required, 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:96'],
            'phone'        => ['nullable', 'string', 'max:24'],
            'address'      => ['nullable', 'string', 'max:255'],
            'address_2'    => ['nullable', 'string', 'max:255'],
            'city'         => ['nullable', 'string', 'max:96'],
            'state'        => ['nullable', 'string', 'max:96'],
            'country'      => ['nullable', 'string', 'max:64'],
            'pincode'      => ['nullable', 'string', 'max:12'],
            'lat'          => ['nullable', 'numeric', 'between:-90,90'],
            'lng'          => ['nullable', 'numeric', 'between:-180,180'],
            'partner'      => ['nullable', 'string', 'max:24'],
            'is_default'   => ['nullable', 'boolean'],
        ]);
        // status / provider_location_name / provider_pickup_code are deliberately absent: they are
        // the partner's answer, not the operator's input (the model's $fillable says the same).
    }

    /**
     * POST shipments/{id}/package — operator's parcel correction.
     *
     * Separate from dispatch on purpose: couriers price on weight/volumetric
     * weight, so the package has to be saved BEFORE rates are fetched or the
     * quote the operator picks isn't the quote they get billed for. Any field
     * omitted (or sent null) reverts to the derived value.
     */
    public function updatePackage(Request $request, $id)
    {
        $data = $request->validate([
            'weight_g'   => 'nullable|integer|min:1|max:100000',
            'length_cm'  => 'nullable|numeric|min:1|max:300',
            'breadth_cm' => 'nullable|numeric|min:1|max:300',
            'height_cm'  => 'nullable|numeric|min:1|max:300',
        ]);

        $shipment = $this->shipment($id);
        if ($shipment->isLiveBooked()) {
            return response()->json([
                'ok'    => false,
                'error' => 'This shipment is already booked — cancel the booking before changing the parcel.',
            ], 409);
        }

        $shipment->forceFill([
            'weight_g'   => $data['weight_g'] ?? null,
            'length_cm'  => $data['length_cm'] ?? null,
            'breadth_cm' => $data['breadth_cm'] ?? null,
            'height_cm'  => $data['height_cm'] ?? null,
        ])->save();

        return response()->json(['ok' => true, 'shipment' => $shipment->fresh()]);
    }

    /**
     * POST shipments/{id}/self-status — manual status walk for SELF-delivery
     * shipments (the vendor fulfils these; no courier, no DP record). Routes
     * through applyNormalizedStatus — the exact same seam as partner webhooks —
     * so the order-status cascade, settlement trigger and terminal-stickiness
     * guards apply identically to a hand-reported delivery.
     *
     * Reachable by staff AND the vendor (nursery app): authorization is
     * super-admin OR the shop's owner OR staff linked to the shop.
     */
    public function selfStatus(Request $request, $id)
    {
        $status = (string) $request->input('status');
        if (!in_array($status, ['shipped', 'out_for_delivery', 'delivered', 'cancelled'], true)) {
            return response()->json([
                'ok'    => false,
                'error' => 'status must be one of: shipped, out_for_delivery, delivered, cancelled.',
            ], 422);
        }

        $shipment = $this->shipment($id);
        if (!$shipment->isSelfDelivery()) {
            return response()->json([
                'ok'    => false,
                'error' => 'Only self-delivery shipments can be updated manually — courier shipments track via the partner.',
            ], 422);
        }

        $user = $request->user();
        $authorized = $user && (
            $user->hasPermissionTo(Permission::SUPER_ADMIN)
            || Shop::where('id', $shipment->shop_id)->where('owner_id', $user->id)->exists()
            || (int) $user->shop_id === (int) $shipment->shop_id
        );
        if (!$authorized) {
            return response()->json(['ok' => false, 'error' => 'Not authorized for this shipment.'], 403);
        }

        if (in_array((string) $shipment->status, ['delivered', 'cancelled', 'rto'], true)) {
            return response()->json([
                'ok'    => false,
                'error' => "This shipment is already {$shipment->status}.",
            ], 409);
        }

        $svc = $this->courier();
        $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus($status));

        return response()->json(['ok' => true, 'shipment' => $shipment->fresh()]);
    }

    /**
     * POST shops/{id}/delivery-settings — vendor delivery capability
     * (platform courier stack vs self-delivery + operational metadata).
     * Writes ONLY the two dedicated columns: the generic PUT /shops/{id}
     * full-replaces settings/address, which makes it unsafe for partial
     * writes from the mobile app.
     */
    public function deliverySettings(Request $request, $id)
    {
        $shop = Shop::findOrFail($id);
        $user = $request->user();
        $authorized = $user && (
            $user->hasPermissionTo(Permission::SUPER_ADMIN)
            || (int) $shop->owner_id === (int) $user->id
        );
        if (!$authorized) {
            return response()->json(['ok' => false, 'error' => 'Not authorized for this shop.'], 403);
        }

        $data = $request->validate([
            'delivery_mode'               => ['required', 'in:platform,self'],
            'self_delivery'               => ['nullable', 'array'],
            'self_delivery.contact_name'  => ['nullable', 'string', 'max:120'],
            'self_delivery.contact_phone' => ['nullable', 'string', 'max:20'],
            'self_delivery.radius_km'     => ['nullable', 'numeric', 'min:0', 'max:500'],
            'self_delivery.same_day'      => ['nullable', 'boolean'],
            'self_delivery.cod'           => ['nullable', 'boolean'],
            'self_delivery.days'          => ['nullable', 'string', 'max:255'],
            'self_delivery.hours'         => ['nullable', 'string', 'max:255'],
            'self_delivery.notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $shop->forceFill([
            'delivery_mode' => $data['delivery_mode'],
            'self_delivery' => $data['self_delivery'] ?? null,
        ])->save();

        return response()->json([
            'ok'   => true,
            'shop' => [
                'id'            => $shop->id,
                'delivery_mode' => $shop->delivery_mode,
                'self_delivery' => $shop->self_delivery,
            ],
        ]);
    }

    /**
     * Complete-on-use gate at the last server hop before a courier partner:
     * a snapshot missing street/city/state or a valid 6-digit PIN would only
     * fail later with a raw partner 422 (Shiprocket's "Wrong address" class of
     * errors) — refuse here with a fixable message instead.
     */
    private function rejectIncompleteAddress(Shipment $shipment)
    {
        $order = $shipment->order;
        $missing = Address::missingFields((array) ($order->shipping_address ?? []));
        if (!$missing) {
            return null;
        }
        return response()->json([
            'ok'      => false,
            'code'    => 'ADDRESS_INCOMPLETE',
            'missing' => $missing,
            'error'   => 'The delivery address is incomplete (missing: ' . implode(', ', $missing) . '). Edit the order shipping address, then book.',
        ], 422);
    }

    /** Location Capture gate (flag-gated, default off) for courier bookings. */
    private function assertCustomerLocation($shipment): void
    {
        $order = $shipment->order ?? null;
        if ($order) {
            app(\Marvel\Services\LocationCaptureService::class)->assertCustomerVerifiedForDispatch($order);
        }
    }
}
