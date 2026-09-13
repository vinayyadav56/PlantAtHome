<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\VendorAdjustment;
use Marvel\Database\Models\VendorPayment;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Services\Accounting\VendorAdjustmentService;
use Marvel\Services\Accounting\VendorPaymentService;
use Marvel\Services\SettlementService;

/**
 * Settlement approval / cancel, vendor payments (partial) and vendor adjustments —
 * the money-moving admin actions of spec §22-25. Thin: validation + delegation only.
 * Route permissions: accounting.settlements.approve / .pay, accounting.adjustments.*.
 */
class AccountingSettlementController extends CoreController
{
    private function actor(Request $r): string
    {
        return (string) ($r->user()?->id ?? 'system');
    }

    public function approve(Request $request, $id)
    {
        $s = (new SettlementService())->approve(VendorSettlement::findOrFail($id), $this->actor($request), $request->input('note'));
        return response()->json(['message' => 'Settlement approved.', 'settlement' => $s]);
    }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $s = (new SettlementService())->cancel(VendorSettlement::findOrFail($id), $request->input('reason'), $this->actor($request));
        return response()->json(['message' => 'Settlement cancelled; its ledger rows will be re-swept.', 'settlement' => $s]);
    }

    public function payments(Request $request, $id)
    {
        return VendorPayment::where('vendor_settlement_id', $id)->orderByDesc('id')->paginate((int) ($request->limit ?? 30));
    }

    public function recordPayment(Request $request, $id)
    {
        $data = $request->validate([
            'amount'                => ['required', 'numeric', 'gt:0'],
            'payment_method'        => ['required', 'in:' . implode(',', VendorPayment::METHODS)],
            'payment_date'          => ['nullable', 'date'],
            'bank_reference'        => ['nullable', 'string', 'max:120'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'idempotency_key'       => ['nullable', 'string', 'max:191'],
        ]);
        try {
            $payment = (new VendorPaymentService())->record(
                VendorSettlement::findOrFail($id),
                number_format((float) $data['amount'], 2, '.', ''),
                $data['payment_method'],
                ['bank_reference' => $data['bank_reference'] ?? null, 'transaction_reference' => $data['transaction_reference'] ?? null, 'notes' => $data['notes'] ?? null],
                $this->actor($request),
                $data['idempotency_key'] ?? null,
                $data['payment_date'] ?? null,
            );
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Payment recorded.', 'payment' => $payment, 'settlement' => $payment->settlement]);
    }

    // ── adjustments ──────────────────────────────────────────────────────────

    public function adjustments(Request $request)
    {
        $q = VendorAdjustment::with('shop:id,name')->orderByDesc('id');
        if ($request->filled('shop_id')) {
            $q->where('shop_id', (int) $request->shop_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        return $q->paginate((int) ($request->limit ?? 30));
    }

    public function createAdjustment(Request $request)
    {
        $data = $request->validate([
            'shop_id'        => ['required', 'integer'],
            'type'           => ['required', 'in:credit,debit'],
            'amount'         => ['required', 'numeric', 'gt:0'],
            'reason'         => ['required', 'string', 'max:500'],
            'reference'      => ['nullable', 'string', 'max:191'],
            'effective_date' => ['nullable', 'date'],
        ]);
        $adj = (new VendorAdjustmentService())->create((int) $data['shop_id'], $data['type'], number_format((float) $data['amount'], 2, '.', ''), $data['reason'], $data['reference'] ?? null, $data['effective_date'] ?? null, $this->actor($request));
        return response()->json(['message' => $adj->status === 'approved' ? 'Adjustment posted.' : 'Adjustment awaiting approval.', 'adjustment' => $adj]);
    }

    public function approveAdjustment(Request $request, $id)
    {
        try {
            $adj = (new VendorAdjustmentService())->approve(VendorAdjustment::findOrFail($id), $this->actor($request), $request->input('note'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Adjustment approved and posted.', 'adjustment' => $adj]);
    }

    public function rejectAdjustment(Request $request, $id)
    {
        $request->validate(['note' => ['required', 'string', 'max:500']]);
        $adj = (new VendorAdjustmentService())->reject(VendorAdjustment::findOrFail($id), $request->input('note'), $this->actor($request));
        return response()->json(['message' => 'Adjustment rejected.', 'adjustment' => $adj]);
    }

    /** Vendor (self): own payments, read-only — ownership-checked like SettlementController::resolveShopId. */
    public function myPayments(Request $request)
    {
        $user = $request->user();
        $shops = $user ? $user->shops : collect();
        if ($request->filled('shop_id')) {
            $shopId = (int) $request->input('shop_id');
            if (!$shops->contains('id', $shopId)) {
                abort(403, 'You do not own this vendor shop.');
            }
        } else {
            $shopId = (int) ($shops->first()?->id ?? 0);
        }
        return VendorPayment::where('shop_id', $shopId)->orderByDesc('id')->paginate((int) ($request->limit ?? 30));
    }
}
