<?php

namespace Marvel\Services;

use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Enums\OrderStatus;

/**
 * Money-parity reconciliation — the gate before the P4 cutover (retiring the legacy
 * per-vertical child-order commission for the new vendor ledger). Both paths key off the
 * SAME completed child order (legacy credits balances in updateBalanceShop; the ledger
 * records a sale in the same call), so we can do a 1:1 coverage check by order_id.
 *
 * The CUTOVER GATE is coverage_missing == 0 — i.e. every completed order that credited a
 * vendor's legacy balance (after the ledger was enabled) has a matching ledger sale entry.
 * Amounts intentionally DIFFER: legacy earns on order.total (incl. tax + delivery); the
 * ledger earns on product value minus commission + platform + PG fees. So the gate is
 * coverage, not amount equality — the per-vendor breakdown explains the amount delta.
 */
class ReconciliationService
{
    public function report(?string $from = null, ?string $to = null): array
    {
        $rates = Balance::pluck('admin_commission_rate', 'shop_id');

        // Legacy: completed child orders (the unit the legacy commission credits).
        $legacyQuery = Order::whereNotNull('parent_id')
            ->where('order_status', OrderStatus::COMPLETED)
            ->whereNotNull('shop_id');
        if ($from) {
            $legacyQuery->where('updated_at', '>=', $from);
        }
        if ($to) {
            $legacyQuery->where('updated_at', '<=', $to);
        }
        $legacyOrders = $legacyQuery->get(['id', 'shop_id', 'total']);

        $legacy = [];
        foreach ($legacyOrders as $o) {
            $rate = (float) ($rates[$o->shop_id] ?? 0);
            $earn = round((float) $o->total * (100 - $rate) / 100, 2);
            $sid = (int) $o->shop_id;
            $legacy[$sid]['earnings'] = ($legacy[$sid]['earnings'] ?? 0) + $earn;
            $legacy[$sid]['orders']   = ($legacy[$sid]['orders'] ?? 0) + 1;
            $legacy[$sid]['order_ids'][(int) $o->id] = true;
        }

        // Ledger: sale entries (the new model) in the same window.
        $ledgerQuery = VendorLedgerEntry::where('entry_type', 'sale');
        if ($from) {
            $ledgerQuery->where('earned_at', '>=', $from);
        }
        if ($to) {
            $ledgerQuery->where('earned_at', '<=', $to);
        }
        $ledgerEntries = $ledgerQuery->get();

        $ledger = [];
        foreach ($ledgerEntries as $e) {
            $sid = (int) $e->shop_id;
            $ledger[$sid]['product_value']  = ($ledger[$sid]['product_value'] ?? 0) + (float) $e->product_value;
            $ledger[$sid]['commission']     = ($ledger[$sid]['commission'] ?? 0) + (float) $e->commission_amount;
            $ledger[$sid]['platform_fee']   = ($ledger[$sid]['platform_fee'] ?? 0) + (float) $e->platform_fee;
            $ledger[$sid]['pg_fee']         = ($ledger[$sid]['pg_fee'] ?? 0) + (float) $e->pg_fee;
            $ledger[$sid]['vendor_earning'] = ($ledger[$sid]['vendor_earning'] ?? 0) + (float) $e->amount;
            $ledger[$sid]['orders']         = ($ledger[$sid]['orders'] ?? 0) + 1;
            $ledger[$sid]['order_ids'][(int) $e->order_id] = true;
        }

        $shopIds = array_values(array_unique(array_merge(array_keys($legacy), array_keys($ledger))));
        $byVendor = [];
        $totalLegacy = 0.0;
        $totalLedger = 0.0;
        $coverageMissing = 0;
        $coverageExtra = 0;
        $missingOrderIds = [];

        foreach ($shopIds as $sid) {
            $L = $legacy[$sid] ?? [];
            $G = $ledger[$sid] ?? [];
            $legacyIds = array_keys($L['order_ids'] ?? []);
            $ledgerIds = array_keys($G['order_ids'] ?? []);
            $missing = array_values(array_diff($legacyIds, $ledgerIds)); // credited legacy, NO ledger entry → gap
            $extra   = array_values(array_diff($ledgerIds, $legacyIds)); // ledger entry, not a completed legacy credit

            $coverageMissing += count($missing);
            $coverageExtra += count($extra);
            $missingOrderIds = array_merge($missingOrderIds, array_slice($missing, 0, 20));
            $totalLegacy += $L['earnings'] ?? 0;
            $totalLedger += $G['vendor_earning'] ?? 0;

            $byVendor[] = [
                'shop_id'                => $sid,
                'legacy_earnings'        => round($L['earnings'] ?? 0, 2),
                'legacy_orders'          => $L['orders'] ?? 0,
                'ledger_vendor_earning'  => round($G['vendor_earning'] ?? 0, 2),
                'ledger_product_value'   => round($G['product_value'] ?? 0, 2),
                'ledger_commission'      => round($G['commission'] ?? 0, 2),
                'ledger_fees'            => round(($G['platform_fee'] ?? 0) + ($G['pg_fee'] ?? 0), 2),
                'ledger_orders'          => $G['orders'] ?? 0,
                'missing_ledger_orders'  => count($missing),
                'extra_ledger_orders'    => count($extra),
            ];
        }

        usort($byVendor, fn ($a, $b) => $b['missing_ledger_orders'] <=> $a['missing_ledger_orders']);

        return [
            'period'         => ['from' => $from, 'to' => $to],
            'ledger_enabled' => (new VendorLedgerService())->enabled(),
            'overall'        => [
                'legacy_earnings'       => round($totalLegacy, 2),
                'ledger_vendor_earning' => round($totalLedger, 2),
                'legacy_orders'         => $legacyOrders->count(),
                'ledger_orders'         => $ledgerEntries->count(),
                'coverage_missing'      => $coverageMissing, // CUTOVER GATE: must be 0
                'coverage_extra'        => $coverageExtra,
                'cutover_safe'          => $coverageMissing === 0,
                'sample_missing_order_ids' => array_slice(array_values(array_unique($missingOrderIds)), 0, 20),
                'note'                  => 'Amounts differ by design (legacy = order total incl. tax/delivery; ledger = product value − commission − fees). The cutover gate is coverage_missing=0, not amount equality.',
            ],
            'by_vendor'      => $byVendor,
        ];
    }
}
