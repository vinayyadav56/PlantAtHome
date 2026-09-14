<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Services\Accounting\FinancialReports;

/** Accounting reports (spec §59). Read-only; every figure is a DB aggregate of posted journal lines. */
class AccountingReportController extends CoreController
{
    private function range(Request $r): array
    {
        $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        return [$r->input('from') ?: null, $r->input('to') ?: null];
    }

    public function dashboard(Request $request)
    {
        [$from, $to] = $this->range($request);
        return response()->json((new FinancialReports())->dashboard($from, $to));
    }

    public function generalLedger(Request $request)
    {
        $this->range($request);
        $f = $request->only(['from', 'to', 'account', 'source_type', 'reference_type', 'reference_id', 'entry_number', 'search', 'shop_id', 'customer_id', 'order_id', 'order_item_id', 'refund_id', 'settlement_id', 'vendor_payment_id', 'shipment_id', 'tax_kind']);
        return response()->json((new FinancialReports())->generalLedger($f, (int) ($request->page ?? 1), (int) ($request->limit ?? 50)));
    }

    public function trialBalance(Request $request)
    {
        [$from, $to] = $this->range($request);
        return response()->json((new FinancialReports())->trialBalance($from, $to));
    }

    public function profitAndLoss(Request $request)
    {
        [$from, $to] = $this->range($request);
        return response()->json((new FinancialReports())->profitAndLoss($from, $to));
    }

    public function balanceSheet(Request $request)
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        return response()->json((new FinancialReports())->balanceSheet($request->input('as_of')));
    }

    public function accountsPayable(Request $request)
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        return response()->json((new FinancialReports())->accountsPayable($request->input('as_of')));
    }

    public function cashBank(Request $request)
    {
        [$from, $to] = $this->range($request);
        return response()->json((new FinancialReports())->cashAndBank($from, $to));
    }

    public function taxLedger(Request $request)
    {
        [$from, $to] = $this->range($request);
        return response()->json((new FinancialReports())->taxLedger($from, $to));
    }

    public function customerLedger(Request $request, $customerId)
    {
        $this->range($request);
        $f = ['customer_id' => (int) $customerId, 'from' => $request->input('from'), 'to' => $request->input('to'), 'account' => $request->input('account')];
        return response()->json((new FinancialReports())->generalLedger(array_filter($f, fn ($v) => $v !== null && $v !== ''), (int) ($request->page ?? 1), (int) ($request->limit ?? 50)));
    }

    /** Admin: any shop. Format json (default) | csv | pdf. */
    public function vendorStatement(Request $request, $shopId)
    {
        [$from, $to] = $this->range($request);
        return $this->statementResponse((int) $shopId, $from, $to, (string) $request->input('format', 'json'));
    }

    /** Vendor: own shop only (ownership check mirrors ReportController::ownedShopIds). */
    public function myStatement(Request $request)
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();
        $shops = $user ? $user->shops : collect();
        $shopId = $request->filled('shop_id') ? (int) $request->input('shop_id') : (int) ($shops->first()->id ?? 0);
        if (!$shopId || !$shops->contains('id', $shopId)) {
            abort(403, 'You do not own this vendor shop.');
        }
        return $this->statementResponse($shopId, $from, $to, (string) $request->input('format', 'json'));
    }

    private function statementResponse(int $shopId, ?string $from, ?string $to, string $format)
    {
        $st = (new FinancialReports())->vendorStatement($shopId, $from, $to);
        if ($format === 'csv') {
            $header = ['date', 'entry', 'source', 'description', 'debit', 'credit', 'running_balance'];
            $rows = array_map(fn ($r) => [$r['entry_date'], $r['entry_number'], $r['source_type'], $r['description'] ?: $r['entry_description'], $r['debit'], $r['credit'], $r['running_balance'] ?? ''], $st['rows']);
            array_unshift($rows, ['', '', '', 'Opening balance', '', '', $st['opening_balance']]);
            $rows[] = ['', '', '', 'Closing balance', '', '', $st['closing_balance']];
            return $this->streamCsv('vendor-statement-' . $shopId . '.csv', $header, $rows);
        }
        if ($format === 'pdf') {
            if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                abort(501, 'PDF generation is not available.');
            }
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->statementHtml($st));
            $pdf->getDomPDF()->getOptions()->setIsPhpEnabled(false);
            return $pdf->download('vendor-statement-' . $shopId . '.pdf');
        }
        return response()->json($st);
    }

    private function statementHtml(array $st): string
    {
        $inr = fn ($v) => '₹' . number_format((float) $v, 2);
        $rows = '';
        foreach ($st['rows'] as $r) {
            $rows .= '<tr><td>' . e($r['entry_date']) . '</td><td>' . e($r['entry_number']) . '</td><td>' . e($r['source_type']) . '</td><td>' . e((string) ($r['description'] ?: $r['entry_description'])) . '</td>'
                . '<td style="text-align:right">' . $inr($r['debit']) . '</td><td style="text-align:right">' . $inr($r['credit']) . '</td><td style="text-align:right">' . $inr($r['running_balance'] ?? 0) . '</td></tr>';
        }
        $sum = '';
        foreach ($st['summary'] as $k => $v) {
            $sum .= '<tr><td>' . e(ucfirst(str_replace('_', ' ', $k))) . '</td><td style="text-align:right">' . $inr($v) . '</td></tr>';
        }
        return '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:5px}h2{margin:0}</style></head><body>'
            . '<h2>PlantAtHome — Vendor Statement</h2>'
            . '<p><strong>Vendor:</strong> ' . e((string) ($st['shop']['name'] ?? ('Shop #' . $st['shop']['id']))) . ' &nbsp; <strong>GSTIN:</strong> ' . e((string) ($st['shop']['gstin'] ?? '—'))
            . '<br><strong>Period:</strong> ' . e((string) ($st['from'] ?? 'start')) . ' → ' . e((string) ($st['to'] ?? 'today')) . '</p>'
            . '<table><thead><tr><th>Date</th><th>Entry</th><th>Source</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead><tbody>'
            . '<tr><td colspan="6">Opening balance</td><td style="text-align:right">' . $inr($st['opening_balance']) . '</td></tr>' . $rows
            . '<tr><td colspan="6"><strong>Closing balance (owed to vendor)</strong></td><td style="text-align:right"><strong>' . $inr($st['closing_balance']) . '</strong></td></tr></tbody></table>'
            . '<h3>Summary</h3><table><tbody>' . $sum . '</tbody></table>'
            . '<p style="margin-top:12px;font-size:10px;color:#666">Positive balance = owed to the vendor. Every line is a posted, immutable journal entry.</p></body></html>';
    }

    private function streamCsv(string $filename, array $header, iterable $rows)
    {
        $safe = fn ($v) => is_string($v) && $v !== '' && !is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"', 'Cache-Control' => 'no-store'];
        return response()->stream(function () use ($header, $rows, $safe) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, array_map($safe, $header));
            foreach ($rows as $row) {
                fputcsv($fh, array_map($safe, (array) $row));
            }
            fclose($fh);
        }, 200, $headers);
    }
}
