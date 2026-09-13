<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Services\Accounting\OpeningBalanceService;
use Marvel\Services\Accounting\PeriodService;
use Marvel\Services\Accounting\ReconciliationEngine;

/** Reconciliation runs/findings, periods, opening balances, audit log (spec §37-40, §46, §32). Thin. */
class AccountingReconciliationController extends CoreController
{
    private function actor(Request $r): string
    {
        return (string) ($r->user()?->id ?? 'system');
    }

    public function runs(Request $request)
    {
        return DB::table('acc_reconciliation_runs')->orderByDesc('id')->paginate((int) ($request->limit ?? 20));
    }

    public function run(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'kinds' => ['nullable', 'array'], 'kinds.*' => ['in:journal,vendor,payment,gst,legacy,inventory']]);
        $r = (new ReconciliationEngine())->run($data['from'] ?? null, $data['to'] ?? null, $data['kinds'] ?? null, $this->actor($request));
        return response()->json(['run' => $r['run'], 'findings' => $r['all_findings'], 'summary' => $r['summary']]);
    }

    public function gate(Request $request)
    {
        return response()->json((new ReconciliationEngine())->gate($request->input('from'), $request->input('to'), $this->actor($request)));
    }

    public function findings(Request $request)
    {
        $q = DB::table('acc_reconciliation_findings')->orderByDesc('id');
        foreach (['status', 'kind', 'subject_type', 'run_id'] as $col) {
            if ($request->filled($col)) {
                $q->where($col, $request->input($col));
            }
        }
        return $q->paginate((int) ($request->limit ?? 30));
    }

    public function resolveFinding(Request $request, $id)
    {
        $data = $request->validate(['status' => ['required', 'in:explained,resolved,open'], 'note' => ['required', 'string', 'max:2000']]);
        return response()->json(['finding' => (new ReconciliationEngine())->resolve((int) $id, $data['status'], $data['note'], $this->actor($request))]);
    }

    public function periods()
    {
        return response()->json(['data' => (new PeriodService())->list()]);
    }

    public function closePeriod(Request $request, $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'force' => ['nullable', 'boolean']]);
        try {
            return response()->json(['period' => (new PeriodService())->close((int) $id, $this->actor($request), $data['note'] ?? null, (bool) ($data['force'] ?? false))]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reopenPeriod(Request $request, $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return response()->json(['period' => (new PeriodService())->reopen((int) $id, $data['reason'], $this->actor($request))]);
    }

    public function openingBalances()
    {
        return response()->json(['data' => (new OpeningBalanceService())->prefill()]);
    }

    public function postOpeningBalance(Request $request)
    {
        $data = $request->validate(['shop_id' => ['required', 'integer'], 'amount' => ['required', 'numeric'], 'as_of' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        try {
            $je = (new OpeningBalanceService())->post((int) $data['shop_id'], number_format((float) $data['amount'], 2, '.', ''), $data['as_of'] ?? null, $this->actor($request), $data['note'] ?? null);
            return response()->json(['journal' => $je]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function confirmOpeningBalance(Request $request, $shopId)
    {
        $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        return response()->json(['journal' => (new OpeningBalanceService())->confirm((int) $shopId, $this->actor($request), $request->input('note'))]);
    }

    public function auditLog(Request $request)
    {
        $q = AccountingAuditLog::query()->orderByDesc('id');
        foreach (['auditable_type', 'auditable_id', 'action', 'actor_id', 'reference'] as $col) {
            if ($request->filled($col)) {
                $q->where($col, $request->input($col));
            }
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->input('to') . ' 23:59:59');
        }
        return $q->paginate((int) ($request->limit ?? 50));
    }
}
