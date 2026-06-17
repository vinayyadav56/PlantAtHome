<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
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
        $res = $this->courier()->bookShipment($this->shipment($id));
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
    }

    /** GET shipments/{id}/shipping-quotes — ranked quotes across every eligible partner. */
    public function quotes(Request $request, $id)
    {
        $cod = $request->has('cod') ? $request->boolean('cod') : null;
        return response()->json($this->courier()->quoteShipment($this->shipment($id), $cod));
    }

    /**
     * POST shipments/{id}/dispatch — book the shipment via the partner for its mode
     * (courier → Shiprocket, instant/same-city → Borzo). Idempotent on provider_order_id.
     */
    public function dispatch(Request $request, $id)
    {
        $res = $this->courier()->book($this->shipment($id));
        return response()->json($res, !empty($res['ok']) ? 200 : 409);
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

    /** POST shops/{id}/sync-pickup — register the vendor address as a provider pickup location. */
    public function syncPickup(Request $request, $id)
    {
        $shop = Shop::findOrFail($id);
        return response()->json($this->courier()->syncPickupLocation($shop));
    }
}
