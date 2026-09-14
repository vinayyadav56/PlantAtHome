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

    public function taxLedger(Request $request)
    {
        [$from, $to] = $this->range($request);
        return response()->json((new FinancialReports())->taxLedger($from, $to));
    }
}
