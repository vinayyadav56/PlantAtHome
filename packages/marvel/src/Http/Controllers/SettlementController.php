<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Services\ReconciliationService;
use Marvel\Services\SettlementService;

/**
 * Vendor ledger + T+N settlement endpoints. Admin (SUPER_ADMIN) sees every vendor and
 * runs/pays settlements; a vendor (STORE_OWNER) sees only their OWN ledger, settlements
 * and ledger-derived balance. Customers never see any of this.
 */
class SettlementController extends CoreController
{
    /** The caller's shop id — an owned `shop_id` if passed, else their (single) shop. */
    private function resolveShopId(Request $request): int
    {
        $user = $request->user();
        $shops = $user ? $user->shops : collect();
        if ($request->filled('shop_id')) {
            $requested = (int) $request->input('shop_id');
            if (!$shops->contains('id', $requested)) {
                abort(403, 'You do not own this vendor shop.');
            }
            return $requested;
        }
        $first = $shops->first();
        if (!$first) {
            abort(422, 'No vendor shop is associated with your account.');
        }
        return (int) $first->id;
    }

    private function ledgerQuery(Request $request, ?int $shopId = null)
    {
        $query = VendorLedgerEntry::query()->orderByDesc('id');
        if ($shopId) {
            $query->where('shop_id', $shopId);
        } elseif ($request->filled('shop_id')) {
            $query->where('shop_id', (int) $request->shop_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('entry_type')) {
            $query->where('entry_type', $request->entry_type);
        }
        if ($request->filled('from')) {
            $query->where('earned_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('earned_at', '<=', $request->to);
        }
        return $query;
    }

    private function balanceSummary(int $shopId): array
    {
        $now = Carbon::now();
        $pending = (float) VendorLedgerEntry::where('shop_id', $shopId)->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '>', $now))->sum('amount');
        $eligible = (float) VendorLedgerEntry::where('shop_id', $shopId)->where('status', 'pending')
            ->whereNotNull('available_at')->where('available_at', '<=', $now)->sum('amount');
        $open = VendorSettlement::where('shop_id', $shopId)->whereIn('status', \Marvel\Services\SettlementService::OPEN)->get(['net_payable', 'remaining_payable', 'amount_paid']);
        $settledUnpaid = (float) $open->sum(fn ($s) => (float) $s->amount_paid > 0 || (float) $s->remaining_payable > 0 ? (float) $s->remaining_payable : (float) $s->net_payable);
        $paid = (float) VendorLedgerEntry::where('shop_id', $shopId)->where('entry_type', 'vendor_payment')->sum('amount') * -1;
        // THE answer to "how much do we owe this vendor": the sub-ledger, sales − reversals ± adjustments − payments (spec §8).
        $currentPayable = (float) VendorLedgerEntry::where('shop_id', $shopId)->where('status', '!=', 'reversed')->sum('amount');
        // Lifetime cost of goods + resulting profit (only over sales whose cost is known).
        $cost = (float) VendorLedgerEntry::where('shop_id', $shopId)->whereNotNull('cost_value')->sum('cost_value');
        $profit = (float) VendorLedgerEntry::where('shop_id', $shopId)->whereNotNull('vendor_profit')->sum('vendor_profit');
        // Double-entry view of the same vendor (GL 2010 per shop) — must reconcile with the ledger.
        $glPayable = null;
        try {
            if (\Marvel\Services\Accounting\AccountingPostingService::enabled()) {
                $glPayable = (new \Marvel\Services\Accounting\FinancialReports())->accountBalance('2010', null, $shopId);
            }
        } catch (\Throwable $e) {
            $glPayable = null;
        }
        $lastPayment = null;
        try {
            $lp = \Marvel\Database\Models\VendorPayment::where('shop_id', $shopId)->where('status', 'completed')->orderByDesc('payment_date')->orderByDesc('id')->first();
            $lastPayment = $lp ? ['amount' => round((float) $lp->amount, 2), 'paid_at' => $lp->payment_date, 'method' => $lp->payment_method] : null;
        } catch (\Throwable $e) {
            $lastPayment = null;
        }

        return [
            'current_payable'    => round($currentPayable, 2),
            'pending_settlement' => round($pending + $eligible, 2),
            'gl_payable'         => $glPayable,
            'last_payment'       => $lastPayment,
            'on_hold'            => round(max(0, $pending), 2), // earned, inside the T+N window
            'eligible'           => round($eligible, 2),        // past hold, next sweep
            'awaiting_payout'    => round($settledUnpaid, 2),   // settled, not yet (fully) paid
            'paid'               => round($paid, 2),
            'lifetime'           => round($currentPayable + $paid, 2),
            'cost'               => round($cost, 2),            // lifetime cost of goods (known-cost sales)
            'profit'             => round($profit, 2),          // lifetime profit (net earning − cost)
        ];
    }

    // ── Admin ──────────────────────────────────────────────────────
    public function ledger(Request $request)
    {
        return $this->ledgerQuery($request)->with('shop:id,name')->paginate((int) ($request->limit ?? 30));
    }

    public function settlements(Request $request)
    {
        $query = VendorSettlement::with(['shop:id,name', 'run:id,run_date'])->orderByDesc('id');
        if ($request->filled('shop_id')) {
            $query->where('shop_id', (int) $request->shop_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        return $query->paginate((int) ($request->limit ?? 30));
    }

    public function showSettlement($id)
    {
        return VendorSettlement::with(['shop:id,name', 'run', 'entries'])->findOrFail($id);
    }

    public function runSettlements(Request $request)
    {
        $run = (new SettlementService())->run(null, optional($request->user())->id);
        return response()->json([
            'message'      => "Settled {$run->vendor_count} vendor(s).",
            'run'          => $run,
            'vendor_count' => $run->vendor_count,
            'total_amount' => $run->total_amount,
        ]);
    }

    public function paySettlement(Request $request, $id)
    {
        $settlement = VendorSettlement::findOrFail($id);
        $paid = (new SettlementService())->pay($settlement);
        return response()->json(['message' => 'Marked paid.', 'settlement' => $paid]);
    }

    /** Admin: money-parity reconciliation (legacy commission vs vendor ledger) — cutover gate. */
    public function reconciliation(Request $request)
    {
        return (new ReconciliationService())->report($request->input('from'), $request->input('to'));
    }

    // ── Vendor (self) ──────────────────────────────────────────────
    public function myLedger(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        return $this->ledgerQuery($request, $shopId)->paginate((int) ($request->limit ?? 30));
    }

    public function mySettlements(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        return VendorSettlement::with('run:id,run_date')->where('shop_id', $shopId)
            ->orderByDesc('id')->paginate((int) ($request->limit ?? 30));
    }

    public function myBalance(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        return response()->json(['data' => $this->balanceSummary($shopId)]);
    }
}
