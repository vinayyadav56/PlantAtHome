<?php

namespace App\Http\Controllers;

use App\Models\PartnerConsoleOrder;
use App\Services\PartnerOrderLifecycle;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The partner-order ledger, read side. Super-admin routes (see Rest/Routes.php).
 *
 * index() is the operational dashboard feed: filterable, paginated, with a status summary on the
 * first page. show() is the debugging drawer: the full record — request, response, flow
 * initiation, latest tracking, errors — plus every mirrored webhook event for the order.
 */
class PartnerConsoleOrderController extends Controller
{
    private const PARTNERS = ['porter', 'borzo', 'shiprocket', 'shiprocket_quick'];

    public function index(Request $request)
    {
        $q = PartnerConsoleOrder::query()->orderByDesc('id');

        $partner = strtolower(trim((string) $request->query('partner', '')));
        if (in_array($partner, self::PARTNERS, true)) {
            $q->where('partner_code', $partner);
        }
        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status === 'failed') {
            // A failed attempt never got a CRN — that is its identity in the ledger.
            $q->whereNull('provider_order_id');
        } elseif (isset(PartnerOrderLifecycle::RANK[$status])) {
            $q->where('partner_status', $status);
        }
        $origin = strtolower(trim((string) $request->query('origin', '')));
        if (in_array($origin, ['console', 'shipment', 'webhook'], true)) {
            $q->where('origin', $origin);
        }
        if ($crn = trim((string) $request->query('crn', ''))) {
            $q->where('provider_order_id', 'like', '%' . addcslashes($crn, '%_\\') . '%');
        }
        if ($orderId = (int) $request->query('order_id', 0)) {
            $q->where('order_id', $orderId);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $page = $q->paginate(min(100, max(1, (int) $request->query('limit', 25))));

        // The summary describes the FILTERED partner scope (not the page), and only rides on the
        // first page — recomputing an aggregate per scroll would be wasted work.
        $out = $page->toArray();
        if ((int) $request->query('page', 1) === 1) {
            $base = PartnerConsoleOrder::query();
            if (in_array($partner, self::PARTNERS, true)) {
                $base->where('partner_code', $partner);
            }
            $byStatus = (clone $base)->whereNotNull('partner_status')
                ->selectRaw('partner_status, count(*) c')->groupBy('partner_status')->pluck('c', 'partner_status');
            $out['summary'] = [
                'total'     => (clone $base)->count(),
                'open'      => (int) ($byStatus['open'] ?? 0),
                'accepted'  => (int) ($byStatus['accepted'] ?? 0),
                'live'      => (int) ($byStatus['live'] ?? 0),
                'ended'     => (int) ($byStatus['ended'] ?? 0),
                'reopened'  => (int) ($byStatus['reopened'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                'failed'    => (clone $base)->whereNull('provider_order_id')->count(),
            ];
        }
        return $out;
    }

    public function show(Request $request, int $id)
    {
        $row = PartnerConsoleOrder::with('webhookEvents')->findOrFail($id);
        return response()->json($row);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'partner_code'      => 'required|string|in:porter,borzo,shiprocket,shiprocket_quick',
            'provider_order_id' => 'required|string|max:191',
        ]);
        $order = PartnerConsoleOrder::updateOrCreate(
            ['partner_code' => $data['partner_code'], 'provider_order_id' => trim($data['provider_order_id'])],
            ['created_by' => optional($request->user())->id],
        );
        return response()->json($order, 201);
    }
}
