<?php

namespace App\Http\Controllers;

use App\Models\PartnerConsoleOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Partner Orders — list the orders created through the courier test console (persisted by
 * CourierPartnerProxyController::testBook), and let an operator manually record a known
 * provider_order_id (e.g. an existing Porter CRN) so it becomes trackable. Tracking itself reuses
 * the existing courier/partners/{code}/test/track proxy. Super-admin only (route group gate).
 */
class PartnerConsoleOrderController extends Controller
{
    private const PARTNERS = ['porter', 'borzo', 'shiprocket'];

    /** GET courier/console-orders?partner=&limit= — newest first, optional partner filter. */
    public function index(Request $request)
    {
        $q = PartnerConsoleOrder::query()->orderByDesc('id');

        $partner = strtolower(trim((string) $request->query('partner', '')));
        if (in_array($partner, self::PARTNERS, true)) {
            $q->where('partner_code', $partner);
        }

        return $q->paginate(min(100, max(1, (int) $request->query('limit', 25))));
    }

    /** POST courier/console-orders — manually record a known provider order id so it can be tracked. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'partner_code'      => 'required|string|in:porter,borzo,shiprocket',
            'provider_order_id' => 'required|string|max:191',
        ]);

        $order = PartnerConsoleOrder::updateOrCreate(
            [
                'partner_code'      => $data['partner_code'],
                'provider_order_id' => trim($data['provider_order_id']),
            ],
            ['created_by' => optional($request->user())->id],
        );

        return response()->json($order, 201);
    }
}
