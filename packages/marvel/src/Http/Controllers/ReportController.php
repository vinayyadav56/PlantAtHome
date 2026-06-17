<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;

/**
 * Financial reports + exports (Financial completeness F4). Admin (SUPER_ADMIN) endpoints
 * export across every vendor (optionally filtered by shop_id / date range); vendor
 * (STORE_OWNER) variants are scoped to the caller's own shop. CSV is the primary format
 * (streamed, never buffered); a settlement statement is also available as a PDF.
 *
 * Reports: Vendor Ledger, Settlements/Payouts, Commission (per-vendor per-period), and
 * GST (per-vendor taxable value + tax + commission + GSTIN). All read-only.
 */
class ReportController extends CoreController
{
    /** Stream rows as a CSV download (header row + data rows). */
    private function streamCsv(string $filename, array $header, iterable $rows)
    {
        $headers = [
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires'             => '0',
            'Pragma'              => 'public',
        ];
        $callback = function () use ($header, $rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, array_map([$this, 'csvSafe'], $header));
            foreach ($rows as $row) {
                fputcsv($fh, array_map([$this, 'csvSafe'], (array) $row));
            }
            fclose($fh);
        };
        return response()->stream($callback, 200, $headers);
    }

    /**
     * Neutralise CSV formula injection: a vendor-controlled string (shop name, GSTIN)
     * beginning with = + - @ tab or CR could execute a formula/DDE when the admin opens
     * the export in Excel/Sheets. Prefix such STRING (non-numeric) cells with a quote so
     * negative numbers and amounts are left untouched.
     */
    public function csvSafe($v)
    {
        if (is_string($v) && $v !== '' && !is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $v;
        }
        return $v;
    }

    /**
     * The caller's owned shop ids for self-service exports: [requested] when an owned
     * shop_id is passed (403 otherwise), else ALL owned shops (so a multi-shop vendor's
     * export is complete, not silently scoped to one arbitrary shop).
     */
    private function ownedShopIds(Request $request): array
    {
        $user = $request->user();
        $shops = $user ? $user->shops : collect();
        if ($request->filled('shop_id')) {
            $requested = (int) $request->input('shop_id');
            if (!$shops->contains('id', $requested)) {
                abort(403, 'You do not own this vendor shop.');
            }
            return [$requested];
        }
        $ids = $shops->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (empty($ids)) {
            abort(422, 'No vendor shop is associated with your account.');
        }
        return $ids;
    }

    /** Apply shop/status/type/date filters shared by the ledger exports. */
    private function ledgerQuery(Request $request, ?array $shopIds = null)
    {
        $query = VendorLedgerEntry::query()->orderByDesc('id');
        if ($shopIds !== null) {
            $query->whereIn('shop_id', $shopIds);
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

    private function ledgerCsv(Request $request, ?array $shopIds = null)
    {
        $shopNames = Shop::pluck('name', 'id');
        $header = ['id', 'shop_id', 'shop', 'order_id', 'entry_type', 'amount', 'product_value', 'commission', 'platform_fee', 'pg_fee', 'cost_value', 'vendor_profit', 'tax_amount', 'status', 'earned_at', 'available_at'];
        $rows = $this->ledgerQuery($request, $shopIds)->cursor()->map(fn ($e) => [
            $e->id, $e->shop_id, $shopNames[$e->shop_id] ?? '', $e->order_id, $e->entry_type,
            $e->amount, $e->product_value, $e->commission_amount, $e->platform_fee, $e->pg_fee,
            $e->cost_value, $e->vendor_profit, $e->tax_amount, $e->status,
            optional($e->earned_at)->toDateTimeString(), optional($e->available_at)->toDateTimeString(),
        ]);
        return $this->streamCsv('vendor-ledger.csv', $header, $rows);
    }

    // ── Admin exports (SUPER_ADMIN) ───────────────────────────────
    public function exportLedger(Request $request)
    {
        return $this->ledgerCsv($request);
    }

    public function exportSettlements(Request $request, ?array $shopIds = null)
    {
        $shopNames = Shop::pluck('name', 'id');
        $query = VendorSettlement::with('run:id,run_date')->orderByDesc('id');
        if ($shopIds !== null) {
            $query->whereIn('shop_id', $shopIds);
        } elseif ($request->filled('shop_id')) {
            $query->where('shop_id', (int) $request->shop_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $header = ['id', 'run_id', 'run_date', 'shop_id', 'shop', 'gross_sales', 'commission', 'platform_fee', 'pg_fee', 'shipping_revenue', 'total_cost', 'total_profit', 'total_tax', 'refund_adjustments', 'net_payable', 'status', 'paid_at'];
        $rows = $query->cursor()->map(fn ($s) => [
            $s->id, $s->settlement_run_id, optional($s->run)->run_date, $s->shop_id, $shopNames[$s->shop_id] ?? '',
            $s->gross_sales, $s->total_commission, $s->total_platform_fee, $s->total_pg_fee, $s->shipping_revenue,
            $s->total_cost, $s->total_profit, $s->total_tax, $s->refund_adjustments, $s->net_payable, $s->status,
            optional($s->paid_at)->toDateTimeString(),
        ]);
        return $this->streamCsv('vendor-settlements.csv', $header, $rows);
    }

    /** Per-vendor per-period commission + earnings rollup. */
    public function exportCommission(Request $request)
    {
        $agg = $this->vendorAggregate($request);
        $header = ['shop_id', 'shop', 'gstin', 'gross_sales', 'commission', 'platform_fee', 'pg_fee', 'net_earning', 'entries'];
        $rows = $agg->map(fn ($r) => [
            $r->shop_id, $r->shop_name, $r->gst_number,
            round((float) $r->gross_sales, 2), round((float) $r->commission, 2),
            round((float) $r->platform_fee, 2), round((float) $r->pg_fee, 2),
            round((float) $r->net_earning, 2), $r->entries,
        ]);
        return $this->streamCsv('commission-report.csv', $header, $rows);
    }

    /** Per-vendor per-period GST report: taxable value + tax + commission + GSTIN. */
    public function exportGst(Request $request)
    {
        $agg = $this->vendorAggregate($request);
        $header = ['shop_id', 'shop', 'gstin', 'taxable_value', 'tax_collected', 'commission', 'entries'];
        $rows = $agg->map(fn ($r) => [
            $r->shop_id, $r->shop_name, $r->gst_number,
            round((float) $r->gross_sales, 2), round((float) $r->tax_collected, 2),
            round((float) $r->commission, 2), $r->entries,
        ]);
        return $this->streamCsv('gst-report.csv', $header, $rows);
    }

    /** Shared per-vendor aggregate over the (optionally date-bounded) ledger. */
    private function vendorAggregate(Request $request)
    {
        $q = VendorLedgerEntry::query()
            ->leftJoin('shops', 'shops.id', '=', 'vendor_ledger_entries.shop_id')
            ->groupBy('vendor_ledger_entries.shop_id', 'shops.name', 'shops.gst_number')
            ->select(
                'vendor_ledger_entries.shop_id',
                DB::raw('MAX(shops.name) as shop_name'),
                DB::raw('MAX(shops.gst_number) as gst_number'),
                DB::raw('SUM(product_value) as gross_sales'),
                DB::raw('SUM(commission_amount) as commission'),
                DB::raw('SUM(platform_fee) as platform_fee'),
                DB::raw('SUM(pg_fee) as pg_fee'),
                DB::raw('SUM(tax_amount) as tax_collected'),
                DB::raw('SUM(amount) as net_earning'),
                DB::raw('COUNT(*) as entries')
            );
        if ($request->filled('shop_id')) {
            $q->where('vendor_ledger_entries.shop_id', (int) $request->shop_id);
        }
        if ($request->filled('from')) {
            $q->where('earned_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->where('earned_at', '<=', $request->to);
        }
        return $q->get();
    }

    /** A single settlement as a PDF statement (admin, or the owning vendor). */
    public function settlementStatementPdf(Request $request, $id)
    {
        $settlement = VendorSettlement::with(['shop:id,name,gst_number', 'run:id,run_date', 'entries'])->findOrFail($id);
        // A vendor may only fetch their own; super-admin (no owned shop match) is allowed through.
        $user = $request->user();
        $owns = $user && $user->shops->contains('id', (int) $settlement->shop_id);
        $isAdmin = $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('super_admin');
        if (!$owns && !$isAdmin) {
            abort(403, 'Not allowed.');
        }
        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            abort(501, 'PDF generation is not available.');
        }
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->statementHtml($settlement));
        return $pdf->download('settlement-' . $settlement->id . '.pdf');
    }

    private function statementHtml(VendorSettlement $s): string
    {
        $shop = $s->shop->name ?? ('Shop #' . $s->shop_id);
        $gst = $s->shop->gst_number ?? '—';
        $date = optional($s->run)->run_date ?? '';
        $inr = fn ($v) => '₹' . number_format((float) $v, 2);
        $rows = '';
        foreach ($s->entries as $e) {
            $rows .= '<tr><td>' . $e->id . '</td><td>' . e($e->entry_type) . '</td><td>' . e((string) $e->order_id) . '</td>'
                . '<td style="text-align:right">' . $inr($e->product_value) . '</td>'
                . '<td style="text-align:right">' . $inr($e->commission_amount) . '</td>'
                . '<td style="text-align:right">' . $inr($e->amount) . '</td></tr>';
        }
        return '<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}'
            . 'table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:6px}'
            . 'h2{margin:0}</style></head><body>'
            . '<h2>PlantAtHome — Settlement Statement</h2>'
            . '<p><strong>Vendor:</strong> ' . e($shop) . ' &nbsp; <strong>GSTIN:</strong> ' . e($gst)
            . '<br><strong>Settlement #</strong>' . $s->id . ' &nbsp; <strong>Run date:</strong> ' . e((string) $date)
            . ' &nbsp; <strong>Status:</strong> ' . e($s->status) . '</p>'
            . '<table><thead><tr><th>Entry</th><th>Type</th><th>Order</th><th>Product value</th><th>Commission</th><th>Net</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p style="margin-top:12px;text-align:right;font-size:14px"><strong>Net payable: ' . $inr($s->net_payable) . '</strong></p>'
            . '</body></html>';
    }

    /**
     * COD reconciliation (C6): courier COD shipments — collected (delivered) vs pending vs
     * remitted. `remitted` is populated once the Shiprocket COD-remittance feed is imported
     * (a user-provided blocker); until then it shows collected vs pending so finance can see
     * cash owed by the courier. Optional date range on shipment creation.
     */
    public function codReconciliation(Request $request)
    {
        $q = Shipment::query()->where('payment_method', 'cod');
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->to);
        }
        $all = $q->get(['id', 'order_id', 'shop_id', 'status', 'cod_amount', 'awb_number', 'delivered_at']);
        $delivered = $all->where('status', 'delivered');
        $pending = $all->where('status', '!=', 'delivered');

        return response()->json([
            'orders'           => $all->count(),
            'cod_total'        => round((float) $all->sum('cod_amount'), 2),
            'delivered_orders' => $delivered->count(),
            'collected'        => round((float) $delivered->sum('cod_amount'), 2),
            'pending'          => round((float) $pending->sum('cod_amount'), 2),
            'remitted'         => 0.0,
            'note'             => 'Remittance reconciliation requires importing the Shiprocket COD remittance feed (user-provided). Until then this shows COD collected (delivered) vs pending.',
        ]);
    }

    // ── Vendor self exports (STORE_OWNER) ─────────────────────────
    public function myLedgerCsv(Request $request)
    {
        return $this->ledgerCsv($request, $this->ownedShopIds($request));
    }

    public function mySettlementsCsv(Request $request)
    {
        return $this->exportSettlements($request, $this->ownedShopIds($request));
    }
}
