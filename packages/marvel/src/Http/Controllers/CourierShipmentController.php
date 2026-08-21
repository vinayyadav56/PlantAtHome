<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\ShipmentPackage;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorPickupLocation;
use Marvel\Enums\Permission;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\ShipmentPlanner;

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
        //
        // The validated id is then handed to THIS booking as an argument. It is deliberately not
        // taken back off the row at build time: the column is never cleared, so a rebook hours
        // later (or the auto-book listener, which chooses nothing) used to replay a courier off an
        // expired rate card.
        $courierId = (int) $request->input('courier_id', 0);
        if ($courierId > 0) {
            $chosen = $this->courier()->chooseCourier($shipment, $courierId);
            if (empty($chosen['ok'])) {
                return response()->json($chosen, 422);
            }
        }

        $options = $this->validateDeliveryOptions($request);
        if ($options instanceof \Illuminate\Http\JsonResponse) {
            return $options;
        }

        $res = $this->courier()->book($shipment, $partner, $courierId > 0 ? $courierId : null, $options);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * Validate the booking wizard's delivery options.
     *
     * This endpoint had NO validation of any kind — it forwarded three optional keys and trusted
     * the partner to complain. That was survivable while the only inputs were a partner code and
     * a courier id; it is not once an operator can set an insured value, a collection amount and
     * a time window, because the partner's complaint about those arrives as an untranslated
     * parameter_errors blob after a real API call.
     *
     * Deliberately duplicated with the Go adapter's validateBorzoOptions rather than deferring to
     * it: this layer turns a bad value into a FIELD error the wizard can attach to the input the
     * operator is looking at, while the adapter's copy is the guarantee for callers that never
     * come through here (the console, the auto-book listener, a future integration).
     *
     * @return array|\Illuminate\Http\JsonResponse validated options, or a 422 to return as-is
     */
    private function validateDeliveryOptions(Request $request)
    {
        if (!$request->has('options')) {
            return [];
        }

        try {
            $data = $request->validate([
                'options'                 => 'array',
                'options.delivery_type'   => 'nullable|string|in:standard,endofday',
                'options.vehicle_id'      => 'nullable|string|max:32',
                'options.insurance_amount' => 'nullable|numeric|min:0|max:1000000',
                'options.route_optimize'  => 'nullable|boolean',
                // Borzo counts the driver inside this number, so 11 is the ceiling, not 11 extra.
                'options.loaders'         => 'nullable|integer|min:0|max:11',
                'options.moto_box'        => 'nullable|boolean',
                'options.thermo_box'      => 'nullable|boolean',
                'options.return_required' => 'nullable|boolean',
                'options.promo_code'      => 'nullable|string|max:64',
                'options.payment_method'  => 'nullable|string|in:balance,cash,bank_card',
                'options.bank_card_id'    => 'nullable|string|max:64',
                'options.collect_amount'  => 'nullable|numeric|min:0|max:1000000',
                'options.cash_voucher'    => 'nullable|boolean',
                'options.buyout_amount'   => 'nullable|numeric|min:0|max:1000000',
                'options.notify_client'   => 'nullable|boolean',
                'options.notify_recipient' => 'nullable|boolean',
                'options.window_start'    => 'nullable|date',
                'options.window_end'      => 'nullable|date|after_or_equal:options.window_start',
                'options.instructions'    => 'nullable|string|max:1000',
                // Porter caps this at 256 and rejects the order over it. Validated here so the
                // operator is told before the booking goes out; the adapter also truncates, for
                // callers that never pass through this endpoint.
                'options.internal_note'   => 'nullable|string|max:256',

                // ── courier-partner order detail (Shiprocket) ────────────────────────────────
                'options.shipping_mode'   => 'nullable|string|in:surface,air',
                'options.order_type'      => 'nullable|string|max:64',
                'options.is_document'     => 'nullable|boolean',
                'options.tags'            => 'nullable|string|max:191',
                'options.reseller_name'   => 'nullable|string|max:191',

                'options.charges'             => 'nullable|array',
                'options.charges.shipping'    => 'nullable|numeric|min:0|max:1000000',
                'options.charges.gift_wrap'   => 'nullable|numeric|min:0|max:1000000',
                'options.charges.transaction' => 'nullable|numeric|min:0|max:1000000',
                'options.charges.discount'    => 'nullable|numeric|min:0|max:1000000',

                'options.tax'              => 'nullable|array',
                // 15 chars, and the 2-digit state code + PAN + entity/Z/checksum shape. Rejecting
                // a malformed GSTIN here beats Shiprocket rejecting the whole order for it.
                'options.tax.gstin'        => 'nullable|string|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/',
                'options.tax.invoice_no'   => 'nullable|string|max:64',
                'options.tax.eway_bill_no' => 'nullable|string|max:32',

                'options.billing'          => 'nullable|array',
                // Required WITH: a partial payer is worse than none — Shiprocket stops copying the
                // recipient across the moment a billing block appears, so an incomplete one ships
                // the parcel to a half-filled address.
                'options.billing.name'     => 'required_with:options.billing|string|max:191',
                'options.billing.phone'    => 'required_with:options.billing|string|max:20',
                'options.billing.email'    => 'nullable|email|max:191',
                'options.billing.address'  => 'required_with:options.billing|string|max:255',
                'options.billing.line2'    => 'nullable|string|max:255',
                'options.billing.city'     => 'required_with:options.billing|string|max:120',
                'options.billing.state'    => 'required_with:options.billing|string|max:120',
                'options.billing.pincode'  => 'required_with:options.billing|string|max:12',
                'options.billing.country'  => 'nullable|string|max:64',
            ], [
                'options.loaders.max'          => 'Borzo allows at most 11 people including the driver.',
                'options.window_end.after_or_equal' => 'The latest arrival time must not be before the earliest.',
                'options.payment_method.in'    => 'Choose account balance, cash, or a bank card.',
                'options.tax.gstin.regex'      => 'That does not look like a GSTIN — 15 characters, e.g. 07AABCU9603R1ZM.',
                'options.shipping_mode.in'     => 'Choose Surface or Air.',
                'options.internal_note.max'    => 'Porter allows at most 256 characters in the internal note.',
                'options.billing.name.required_with'    => 'A separate billing address needs a name.',
                'options.billing.address.required_with' => 'A separate billing address needs a street address.',
                'options.billing.city.required_with'    => 'A separate billing address needs a city.',
                'options.billing.state.required_with'   => 'A separate billing address needs a state.',
                'options.billing.pincode.required_with' => 'A separate billing address needs a pincode.',
                'options.billing.phone.required_with'   => 'A separate billing address needs a phone number.',
            ])['options'] ?? [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Field-keyed so the wizard can pin each message to its own input.
            return response()->json(['ok' => false, 'error' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
        }

        // Cross-field rules the provider enforces, stated in the operator's language. End-of-day
        // is a different Borzo product: it assigns the vehicle and schedules the window itself,
        // and rejects the whole order if either is supplied.
        if (($data['delivery_type'] ?? null) === 'endofday') {
            $conflict = null;
            if (trim((string) ($data['vehicle_id'] ?? '')) !== '') {
                $conflict = ['options.vehicle_id' => ['End-of-day deliveries do not take a vehicle choice — Borzo assigns one.']];
            } elseif (!empty($data['window_start']) || !empty($data['window_end'])) {
                $conflict = ['options.window_start' => ['End-of-day deliveries do not take a delivery time window.']];
            }
            if ($conflict) {
                return response()->json(['ok' => false, 'error' => reset($conflict)[0], 'errors' => $conflict], 422);
            }
        }
        if (!empty($data['bank_card_id']) && ($data['payment_method'] ?? null) !== 'bank_card') {
            return response()->json([
                'ok'     => false,
                'error'  => 'A card was selected but the payment method is not bank card.',
                'errors' => ['options.payment_method' => ['Choose "Bank card" to pay with the selected card.']],
            ], 422);
        }

        return $this->pruneEmpty($data);
    }

    /**
     * Drop nulls and blanks, at every level.
     *
     * The nested blocks (charges, tax, billing) arrive with a key per input the operator saw, so a
     * section they opened and abandoned would otherwise reach the adapter as an object full of
     * nulls — and a non-nil billing block is what switches Shiprocket out of "bill the recipient".
     * The adapter guards on the address being present too; this keeps the wire clean as well.
     */
    private function pruneEmpty(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = $this->pruneEmpty($v);
                if ($v !== []) {
                    $out[$k] = $v;
                }
                continue;
            }
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
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

    /**
     * POST shipments/{id}/cancel-awb — stop the parcel, keep the partner order.
     *
     * Separate from cancel-shipment because Shiprocket has two different cancels and they are not
     * interchangeable: orders/cancel kills the order (and with it the manifest, forcing a fresh
     * create), while cancel/shipment/awbs voids just this waybill — which is what a re-pack or a
     * courier swap needs.
     */
    /**
     * POST shipments/{id}/exchange — send a replacement and collect the original.
     *
     * The operator supplies what only they know: which item is being swapped for what, why, and
     * how big each parcel is. Everything else is resolved here — the buyer's address from the
     * order, the seller's numeric Shiprocket location from the registered pickup row — so the
     * form asks for the exchange, not for an API request.
     */
    public function createExchange(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'create an exchange')) {
            return $resp;
        }

        try {
            $data = $request->validate([
                'return_reason'          => 'required|string|max:191',
                'payment_method'         => 'nullable|string|in:prepaid,cod',
                'qc_check'               => 'nullable|boolean',
                'items'                  => 'required|array|min:1',
                'items.*.name'           => 'required|string|max:191',
                'items.*.sku'            => 'required|string|max:191',
                'items.*.qty'            => 'required|integer|min:1',
                'items.*.unit_price'     => 'required|numeric|min:0',
                // Required by Shiprocket per line, and our catalogue has no HSN column — so it
                // can only come from the person filling this in.
                'items.*.hsn'            => 'required|string|max:32',
                'items.*.returned_name'  => 'required|string|max:191',
                'items.*.returned_sku'   => 'required|string|max:191',
                'items.*.returned_id'    => 'nullable|string|max:64',
                // Two parcels, both mandatory: the item coming back and the one going out are
                // rarely the same size, and deriving one from the other misprices a leg.
                'return_package.length_cm'   => 'required|numeric|gt:0',
                'return_package.breadth_cm'  => 'required|numeric|gt:0',
                'return_package.height_cm'   => 'required|numeric|gt:0',
                'return_package.weight_kg'   => 'required|numeric|gt:0',
                'exchange_package.length_cm'  => 'required|numeric|gt:0',
                'exchange_package.breadth_cm' => 'required|numeric|gt:0',
                'exchange_package.height_cm'  => 'required|numeric|gt:0',
                'exchange_package.weight_kg'  => 'required|numeric|gt:0',
            ], [
                'items.*.hsn.required' => 'Each item needs an HSN code — the courier raises a tax invoice for an exchange and the catalogue does not carry one.',
                'return_package.weight_kg.gt' => 'The returned parcel needs a weight.',
                'exchange_package.weight_kg.gt' => 'The replacement parcel needs a weight.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
        }

        // Shiprocket addresses the seller by its own NUMERIC location id, which is not the
        // nickname every forward booking uses. A door registered before that id was captured
        // cannot serve an exchange, and saying which vendor to re-register is more useful than
        // relaying a field error about a parameter the operator never filled in.
        $door = $this->courier()->pickupLocationFor($shipment);
        $sellerLocationId = trim((string) ($door->provider_pickup_code ?? ''));
        if ($sellerLocationId === '') {
            return $this->conflict(
                'This vendor\'s pickup location has no Shiprocket location id recorded, which an exchange needs. '
                . 'Re-register the pickup location for ' . ($shipment->shop->name ?? ('vendor #' . $shipment->shop_id)) . ', then try again.',
            );
        }

        $order = $shipment->order;
        $buyer = $this->exchangeBuyerAddress($order);

        $res = $this->courier()->createExchange($shipment, [
            'exchange_order_ref' => 'EX-' . $order->tracking_number . '-' . $shipment->id,
            'return_order_ref'   => 'RT-' . $order->tracking_number . '-' . $shipment->id,
            'order_date'         => now()->toDateString(),
            'payment_method'     => $data['payment_method'] ?? 'prepaid',
            'return_reason'      => $data['return_reason'],
            'qc_check'           => (bool) ($data['qc_check'] ?? false),
            // Both buyer legs are the SAME person: we collect the original from them and deliver
            // the replacement to them. Shiprocket keeps them as separate blocks because they need
            // not match, but from an order they do.
            'buyer_pickup'  => $buyer,
            'buyer_drop'    => $buyer,
            'seller_pickup_location_id'   => $sellerLocationId,
            'seller_shipping_location_id' => $sellerLocationId,
            'items'            => array_map(static fn ($i) => [
                'name'          => $i['name'],
                'sku'           => $i['sku'],
                'qty'           => (int) $i['qty'],
                'unit_price'    => (float) $i['unit_price'],
                'hsn'           => $i['hsn'],
                'returned_name' => $i['returned_name'],
                'returned_sku'  => $i['returned_sku'],
                'returned_id'   => $i['returned_id'] ?? null,
            ], $data['items']),
            'sub_total'        => array_sum(array_map(static fn ($i) => (float) $i['unit_price'] * (int) $i['qty'], $data['items'])),
            'return_package'   => $data['return_package'],
            'exchange_package' => $data['exchange_package'],
        ]);

        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** The buyer, in the shape the exchange payload wants. Reuses the order's own address. */
    private function exchangeBuyerAddress($order): array
    {
        $ship = (array) ($order->shipping_address ?? []);
        $a = (array) ($ship['address'] ?? $ship);
        return [
            'name'    => (string) ($order->customer_name ?? $a['name'] ?? 'Customer'),
            'phone'   => (string) ($order->customer_contact ?? $a['phone'] ?? ''),
            'address' => (string) ($a['street_address'] ?? $a['address'] ?? ''),
            'line2'   => (string) ($a['street_address2'] ?? $a['line2'] ?? ''),
            'city'    => (string) ($a['city'] ?? ''),
            'state'   => (string) ($a['state'] ?? ''),
            'pincode' => (string) ($a['zip'] ?? $a['pincode'] ?? ''),
        ];
    }

    /**
     * POST shipments/{id}/assign-awb — retry waybill allocation.
     *
     * Guarded on the two states where it makes no sense rather than letting the partner explain
     * it after a round trip: a shipment with a waybill, and one that was never booked.
     */
    public function assignAwb(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'allocate a waybill')) {
            return $resp;
        }
        if (trim((string) $shipment->awb_number) !== '') {
            return $this->conflict('This shipment already has a waybill (' . $shipment->awb_number . ').');
        }
        $res = $this->courier()->assignAwb($shipment);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    public function cancelAwb(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireAwb($shipment, 'cancel a waybill')) {
            return $resp;
        }
        if ((string) $shipment->status === 'cancelled') {
            return $this->conflict('This shipment is already cancelled.');
        }
        $res = $this->courier()->cancelAwb($shipment, $request->input('reason'));
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * POST shipments/{id}/reassign-courier — move a booked parcel onto a different courier.
     *
     * Refused once the courier has it: after pickup the parcel is physically in one network and a
     * reassignment would mint an AWB nobody is carrying. The courier_id is re-validated against a
     * fresh quote (in CourierService::reassignCourier) exactly like a booking — a reassignment
     * re-prices the leg, so it is the same money path.
     */
    public function reassignCourier(Request $request, $id)
    {
        $courierId = (int) $request->input('courier_id', 0);
        if ($courierId <= 0) {
            return response()->json(['ok' => false, 'error' => 'courier_id is required.'], 422);
        }

        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'reassign the courier')) {
            return $resp;
        }
        if ($this->pickedUp($shipment)) {
            return $this->conflict("The courier has already picked this shipment up ({$shipment->status}) — cancel the waybill instead of reassigning it.");
        }

        $res = $this->courier()->reassignCourier($shipment, $courierId);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** POST shipments/{id}/generate-label */
    public function label(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'print a label')) {
            return $resp;
        }
        $res = $this->courier()->generateLabel($shipment);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** POST shipments/{id}/generate-invoice */
    public function invoice(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'print an invoice')) {
            return $resp;
        }
        $res = $this->courier()->generateInvoice($shipment);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** POST shipments/{id}/generate-manifest */
    public function manifest(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'generate a manifest')) {
            return $resp;
        }
        $res = $this->courier()->generateManifest($shipment);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * POST shipments/manifests — ONE manifest for a batch of shipments, which is how a handover
     * actually happens: the driver signs a single sheet for the whole pickup.
     *
     * All-or-nothing on the guard: a partial batch would hand the driver a sheet that silently
     * omits parcels, so an unbooked id refuses the call and names the offenders.
     */
    public function manifestBulk(Request $request)
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'shipment_ids.*' => ['integer'],
        ]);

        $shipments = Shipment::whereIn('id', $data['shipment_ids'])->get();
        if ($missing = array_values(array_diff(array_map('intval', $data['shipment_ids']), $shipments->pluck('id')->all()))) {
            return $this->conflict('No such shipment: ' . implode(', ', $missing) . '.');
        }
        if ($unbooked = $shipments->reject->isLiveBooked()->pluck('id')->all()) {
            return $this->conflict('These shipments have no live courier booking: ' . implode(', ', $unbooked) . '. Book them first.');
        }

        $res = $this->courier()->generateManifestBulk($shipments);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** POST shipments/{id}/schedule-pickup */
    public function pickup(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'schedule a pickup')) {
            return $resp;
        }
        $res = $this->courier()->schedulePickup($shipment);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    // ── NDR: the 3-attempt countdown before the courier gives up and auto-RTOs ────────────────

    /** GET courier/ndr — open NDRs at the partner. Paging keys are forwarded as-is. */
    public function ndrList(Request $request)
    {
        return response()->json($this->courier()->ndrList($request->only(['limit', 'page', 'from', 'to'])));
    }

    /** GET courier/ndr/{awb} — one NDR; syncs reason/attempts onto our row when we hold it. */
    public function ndrDetail(Request $request, $awb)
    {
        $awb = (string) $awb;
        $res = $this->courier()->ndrDetail($awb, Shipment::where('awb_number', $awb)->first());
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * POST shipments/{id}/ndr-action — tell the courier what to do with a failed attempt.
     *
     * The action vocabulary is the partner's and is forwarded verbatim: a PHP allowlist would be a
     * second copy of their list, and it is their API that rejects an unknown one.
     */
    public function ndrAction(Request $request, $id)
    {
        $data = $request->validate([
            'action'   => ['required', 'string', 'max:64'],
            'comments' => ['nullable', 'string', 'max:255'],
        ]);

        $shipment = $this->shipment($id);
        if ($resp = $this->requireAwb($shipment, 'action an NDR')) {
            return $resp;
        }

        $res = $this->courier()->ndrAction($shipment, $data['action'], $data['comments'] ?? null);
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /**
     * POST shipments/{id}/create-return — a REVERSE shipment (pickup = the customer).
     *
     * Not an RTO and not a cancel: an RTO is the forward parcel bouncing on its own, a cancel stops
     * it before it moves. This is a new, separately-tracked shipment in the opposite direction, so
     * it mints its own AWB and lands in return_* — the forward identifiers stay as the record of
     * what was shipped out.
     */
    public function createReturn(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        if ($resp = $this->requireBooking($shipment, 'create a return')) {
            return $resp;
        }
        if ($shipment->return_awb) {
            return $this->conflict("A return already exists for this shipment (AWB {$shipment->return_awb}).");
        }

        $res = $this->courier()->createReturn($shipment, $request->input('reason'));
        // The service answers "<code> does not book returns" for a partner with no reverse API.
        // True, but it names our partner code at an operator who picked a courier by its brand —
        // and it only ever reaches anyone whose admin predates the `returns` capability gate.
        if (empty($res['ok']) && str_contains(strtolower((string) ($res['error'] ?? '')), 'does not book returns')) {
            $name = $shipment->courier_name ?: ucfirst((string) $shipment->provider);
            $res['error'] = "{$name} cannot book return pickups. Arrange the collection as a new delivery from the customer's address, or move this order to a courier partner that supports returns.";
        }
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    // ── state guards ─────────────────────────────────────────────────────────────────────────
    // Every post-booking operation needs the booking to exist at the partner. Without these the
    // partner answers a raw 422 naming an id it has never seen, which reads to an operator like an
    // outage rather than "you skipped a step".

    private function conflict(string $error)
    {
        return response()->json(['ok' => false, 'error' => $error], 409);
    }

    /** $what completes "…before you can X". Null when the shipment carries a live booking. */
    private function requireBooking(Shipment $shipment, string $what)
    {
        if ($shipment->isLiveBooked()) {
            return null;
        }
        return $this->conflict(
            $shipment->status === 'cancelled'
                ? "This shipment's booking was cancelled — rebook it before you can {$what}."
                : "This shipment is not booked with a courier yet — dispatch it before you can {$what}.",
        );
    }

    private function requireAwb(Shipment $shipment, string $what)
    {
        if (trim((string) $shipment->awb_number) !== '') {
            return null;
        }
        return $this->conflict("This shipment has no AWB yet — a courier has to allocate one before you can {$what}.");
    }

    /** In the courier's hands already: reassigning past this point mints a waybill nobody carries. */
    private function pickedUp(Shipment $shipment): bool
    {
        return $shipment->shipped_at
            || in_array((string) $shipment->status, ['shipped', 'out_for_delivery', 'ndr', 'delivered', 'rto'], true);
    }

    /** GET shipments/{id}/courier-track — live status (admin view). */
    public function track(Request $request, $id)
    {
        $shipment = $this->shipment($id);
        $res = (array) $this->courier()->track($shipment);

        // A shipments row is reused across every booking attempt, so a tracking response scoped
        // only to the shipment cannot say WHICH booking it describes — that is how a cancelled
        // attempt's rider and flow kept surfacing on the booking that replaced it. The ledger row
        // for the CURRENT provider_order_id is the per-attempt truth, so the answer carries it.
        $res['booking'] = $this->currentBooking($shipment, $res);

        return response()->json($res);
    }

    /**
     * Resolve (and freshen) the ledger row for the shipment's CURRENT booking.
     *
     * The reconcile pass deliberately leaves origin=shipment rows alone, so for a real customer
     * shipment this live call is the only thing that ever sees the rider — persist it here or the
     * driver card stays empty until a webhook happens to arrive.
     */
    private function currentBooking(Shipment $shipment, array $res): ?array
    {
        $pid = trim((string) $shipment->provider_order_id);
        if ($pid === '') {
            return null;
        }
        $row = \App\Models\PartnerConsoleOrder::where('provider_order_id', $pid)->first();
        if (!$row) {
            return null;
        }

        $body = $res['exchange']['response']['body'] ?? null;
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : null;
        }
        if ($row->syncDriverFrom(is_array($body) ? $body : null)) {
            \Illuminate\Support\Facades\Log::info('courier.booking.driver', [
                'order_id'          => $shipment->order_id,
                'shipment_id'       => $shipment->id,
                'provider'          => $shipment->provider,
                'provider_order_id' => $pid,
                // Last 4 only — a rider's number is personal data and does not belong in logs.
                'driver_phone_last4' => substr(preg_replace('/\D/', '', (string) $row->driver_phone), -4) ?: null,
                'event_type'        => 'driver_assigned',
            ]);
        }

        $payload = $row->toBookingPayload();
        // The shipment row is where book() records the partner's own reference, so prefer it over
        // the ledger's copy — the ledger may have been created by a reconcile that never saw the
        // booking response.
        if (trim((string) $shipment->provider_reference) !== '') {
            $payload['provider_reference'] = $shipment->provider_reference;
        }

        return $payload;
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
        $location->fill($this->validatePickupLocation($request, false));

        // A verified row whose ADDRESS just changed is no longer verified — the partner still
        // holds the old address, and (see the ponytail note above) Shiprocket has no update API
        // to push the new one. Leaving it `verified` was silent divergence: every booking quoted
        // the partner's stale door while the admin showed a green badge. Demoting to `pending`
        // makes the mismatch visible and routes the operator to the documented fix (register a
        // new label). Contact/label edits don't demote — the partner key is the address.
        $addressDirty = $location->isDirty(['address', 'address_2', 'city', 'state', 'pincode', 'lat', 'lng']);
        if ($addressDirty && $location->status === 'verified') {
            $location->status = 'pending';
            $location->last_error = 'Address changed after registration — the partner still has the old address. Register again (or add a new label) before booking from this door.';
        }
        $location->save();
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
            // Single-parcel shape — unchanged, still what the parcel editor sends.
            'weight_g'   => 'nullable|integer|min:1|max:100000',
            'length_cm'  => 'nullable|numeric|min:1|max:300',
            'breadth_cm' => 'nullable|numeric|min:1|max:300',
            'height_cm'  => 'nullable|numeric|min:1|max:300',
            // Multi-parcel shape.
            'packages'                 => 'nullable|array|max:20',
            'packages.*.weight_g'      => 'nullable|integer|min:1|max:100000',
            'packages.*.length_cm'     => 'nullable|numeric|min:1|max:300',
            'packages.*.breadth_cm'    => 'nullable|numeric|min:1|max:300',
            'packages.*.height_cm'     => 'nullable|numeric|min:1|max:300',
            'packages.*.declared_value' => 'nullable|numeric|min:0',
            'packages.*.contents'      => 'nullable|string|max:255',
            'packages.*.fragile'       => 'nullable|boolean',
        ]);

        $shipment = $this->shipment($id);
        if ($shipment->isLiveBooked()) {
            return response()->json([
                'ok'    => false,
                'error' => 'This shipment is already booked — cancel the booking before changing the parcel.',
            ], 409);
        }

        // is_array, NOT has(): `{"packages": null}` passes has() and would wipe every parcel
        // row while silently ignoring the flat weight sent alongside it. An explicit empty
        // array still clears them — that is a real instruction; null is not.
        if (is_array($request->input('packages'))) {
            $this->replacePackages($shipment, (array) $request->input('packages'));
        } else {
            $shipment->forceFill([
                'weight_g'   => $data['weight_g'] ?? null,
                'length_cm'  => $data['length_cm'] ?? null,
                'breadth_cm' => $data['breadth_cm'] ?? null,
                'height_cm'  => $data['height_cm'] ?? null,
            ])->save();
            // Keep the parcel list in step with the single-parcel editor, so the two
            // surfaces never disagree about what is in this shipment.
            $this->syncSinglePackage($shipment);
        }

        $shipment = $shipment->fresh();

        return response()->json([
            'ok'       => true,
            'shipment' => $shipment,
            'packages' => $this->packagesOf($shipment),
        ]);
    }

    /**
     * GET shipments/{id}/replan — what to do about a parcel nothing will carry.
     *
     * Returns the parcel's own totals plus a PROPOSED split. Creates nothing: a provider
     * refusal is an input to PlantAtHome's planning decision, never an instruction to
     * destroy the shipment. `capacity_kg` may be passed from the capacity a partner
     * reported on its quote; otherwise the planner falls back to a conservative default.
     */
    public function replan(Request $request, $id)
    {
        $request->validate(['capacity_kg' => 'nullable|numeric|min:0.1|max:10000']);

        $shipment = $this->shipment($id);
        $planner = new ShipmentPlanner($this->courier());
        $capacity = $request->filled('capacity_kg') ? (float) $request->input('capacity_kg') : null;

        return response()->json([
            'shipment_id' => (int) $shipment->id,
            'summary'     => $planner->summarize($shipment),
            'proposal'    => $planner->proposeSplit($shipment, $capacity),
            // Why the operator is on this screen at all — book() stamps these on a refusal.
            'needs_replanning' => ($shipment->last_status ?? '') === 'book_failed',
            'failure_reason'   => $shipment->failure_reason,
        ]);
    }

    /** GET shipments/{id}/packages — the parcels recorded for this shipment. */
    public function packages($id)
    {
        $shipment = $this->shipment($id);

        return response()->json([
            'shipment_id' => (int) $shipment->id,
            'packages'    => $this->packagesOf($shipment),
            // The partner is booked for ONE parcel: buildRequest() sends a single flat
            // weight + L/B/H and the Go shipping-service has no packages[] field. The
            // admin surfaces this so nobody reads three rows as three booked parcels.
            'booked_as_single_parcel' => true,
            'rollup' => [
                'weight_g'   => $shipment->weight_g,
                'length_cm'  => $shipment->length_cm,
                'breadth_cm' => $shipment->breadth_cm,
                'height_cm'  => $shipment->height_cm,
            ],
        ]);
    }

    private function packagesOf(Shipment $shipment)
    {
        if (!Schema::hasTable('shipment_packages')) {
            return [];
        }

        return ShipmentPackage::where('shipment_id', $shipment->id)->orderBy('package_number')->get();
    }

    /**
     * Replace this shipment's parcels and recompute the flat rollup that is actually sent
     * to the partner.
     *
     * Weights SUM; dimensions take the LARGEST single box rather than summing, for the
     * same reason packageDims() already gives: three boxes are not one box three times as
     * long, and a courier prices the volumetric weight of each parcel it carries.
     */
    private function replacePackages(Shipment $shipment, array $packages): void
    {
        if (!Schema::hasTable('shipment_packages')) {
            return;
        }

        DB::transaction(function () use ($shipment, $packages) {
            // Re-check UNDER A LOCK. The 409 above ran before the transaction opened, so a
            // booking that started in between would have its parcel rewritten underneath it —
            // the courier is then carrying a box whose declared weight has since changed.
            $fresh = Shipment::whereKey($shipment->id)->lockForUpdate()->first();
            if ($fresh && $fresh->isLiveBooked()) {
                return;
            }

            ShipmentPackage::where('shipment_id', $shipment->id)->delete();

            $number = 0;
            $totalWeight = 0;
            $dims = ['length_cm' => 0.0, 'breadth_cm' => 0.0, 'height_cm' => 0.0];
            $biggestVolume = -1.0;

            foreach ($packages as $package) {
                $number++;
                $row = ShipmentPackage::create([
                    'shipment_id'    => $shipment->id,
                    'package_number' => $number,
                    'weight_g'       => $package['weight_g'] ?? null,
                    'length_cm'      => $package['length_cm'] ?? null,
                    'breadth_cm'     => $package['breadth_cm'] ?? null,
                    'height_cm'      => $package['height_cm'] ?? null,
                    'declared_value' => $package['declared_value'] ?? null,
                    'contents'       => $package['contents'] ?? null,
                    'fragile'        => (bool) ($package['fragile'] ?? false),
                ]);

                $totalWeight += (int) ($row->weight_g ?? 0);
                $volume = (float) ($row->length_cm ?? 0) * (float) ($row->breadth_cm ?? 0) * (float) ($row->height_cm ?? 0);
                if ($volume > $biggestVolume) {
                    $biggestVolume = $volume;
                    $dims = [
                        'length_cm'  => $row->length_cm,
                        'breadth_cm' => $row->breadth_cm,
                        'height_cm'  => $row->height_cm,
                    ];
                }
            }

            // No parcels recorded => back to NULL, which means "derive from product data,
            // then settings, then 20x15x15" — not "an empty box was measured".
            $shipment->forceFill(array_merge(
                ['weight_g' => $totalWeight > 0 ? $totalWeight : null],
                $number > 0 ? $dims : ['length_cm' => null, 'breadth_cm' => null, 'height_cm' => null],
            ))->save();
        });
    }

    /** Mirror a single-parcel edit into the parcel list (or clear it when everything is null). */
    private function syncSinglePackage(Shipment $shipment): void
    {
        if (!Schema::hasTable('shipment_packages')) {
            return;
        }

        $hasAny = $shipment->weight_g !== null || $shipment->length_cm !== null
            || $shipment->breadth_cm !== null || $shipment->height_cm !== null;

        ShipmentPackage::where('shipment_id', $shipment->id)->delete();
        if (!$hasAny) {
            return;
        }

        ShipmentPackage::create([
            'shipment_id'    => $shipment->id,
            'package_number' => 1,
            'weight_g'       => $shipment->weight_g,
            'length_cm'      => $shipment->length_cm,
            'breadth_cm'     => $shipment->breadth_cm,
            'height_cm'      => $shipment->height_cm,
        ]);
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
