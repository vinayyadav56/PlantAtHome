<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Marvel\Database\Models\Accounting\Account;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Services\Accounting\Exceptions\AccountingException;
use Marvel\Services\Accounting\JournalService;

/** Journal entries (list / show / manual post / reverse) and the chart of accounts (spec §5-8, §14). */
class AccountingJournalController extends CoreController
{
    private function actor(Request $r): string
    {
        return (string) ($r->user()?->id ?? 'system');
    }

    public function entries(Request $request)
    {
        $q = JournalEntry::query()->orderByDesc('entry_date')->orderByDesc('id');
        foreach (['status', 'source_type', 'reference_type', 'reference_id', 'entry_number', 'source_key'] as $c) {
            if ($request->filled($c)) {
                $q->where($c, $request->input($c));
            }
        }
        if ($request->filled('from')) { $q->where('entry_date', '>=', $request->input('from')); }
        if ($request->filled('to')) { $q->where('entry_date', '<=', $request->input('to')); }
        if ($request->boolean('flagged')) { $q->where('requires_reconciliation', true); }
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $q->where(fn ($w) => $w->where('description', 'like', $s)->orWhere('entry_number', 'like', $s)->orWhere('source_key', 'like', $s));
        }
        return $q->paginate((int) ($request->limit ?? 30));
    }

    public function entry($id)
    {
        return JournalEntry::with(['lines.account:id,code,name,type,normal_side', 'reverses:id,entry_number', 'reversedBy:id,entry_number'])->findOrFail($id);
    }

    /** A hand-posted entry (accruals, corrections). Balanced, into an OPEN period, audited; never redirected. */
    public function postManual(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:2'], 'lines.*.account' => ['required', 'string'], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'], 'lines.*.shop_id' => ['nullable', 'integer'], 'lines.*.customer_id' => ['nullable', 'integer'], 'lines.*.order_id' => ['nullable', 'integer'],
        ]);
        $lines = [];
        foreach ($data['lines'] as $l) {
            $d = (float) ($l['debit'] ?? 0); $c = (float) ($l['credit'] ?? 0);
            if (($d > 0) === ($c > 0)) {
                return response()->json(['message' => 'Each line needs exactly one of debit or credit.'], 422);
            }
            $row = ['account' => $l['account'], 'description' => $l['description'] ?? null];
            if ($d > 0) { $row['debit'] = number_format($d, 2, '.', ''); } else { $row['credit'] = number_format($c, 2, '.', ''); }
            foreach (['shop_id', 'customer_id', 'order_id'] as $dim) {
                if (!empty($l[$dim])) { $row[$dim] = (int) $l[$dim]; }
            }
            $lines[] = $row;
        }
        try {
            $je = (new JournalService())->postLines($lines, [
                'source_type' => 'MANUAL', 'source_id' => $data['reference'] ?? null, 'source_key' => 'MANUAL:' . Str::uuid(), 'entry_date' => $data['entry_date'],
                'description' => $data['description'], 'reference_type' => $data['reference'] ? 'manual' : null, 'metadata' => ['redirect_closed_period' => false, 'reference' => $data['reference'] ?? null],
                'actor' => $this->actor($request),
            ]);
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['journal' => $je->load('lines')], 201);
    }

    public function reverse(Request $request, $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'entry_date' => ['nullable', 'date']]);
        try {
            $rev = (new JournalService())->reverse(JournalEntry::findOrFail($id), $data['reason'], $this->actor($request), $data['entry_date'] ?? null);
        } catch (AccountingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['journal' => $rev->load('lines')]);
    }

    // ── chart of accounts ────────────────────────────────────────────────────

    public function accounts(Request $request)
    {
        $q = Account::query()->orderBy('code');
        if ($request->filled('type')) { $q->where('type', $request->input('type')); }
        if (!$request->boolean('include_inactive')) { $q->where('is_active', true); }
        $accounts = $q->get();
        if ($request->boolean('with_balances')) {
            $tb = collect((new \Marvel\Services\Accounting\FinancialReports())->trialBalance($request->input('from'), $request->input('to'))['rows'])->keyBy('code');
            $accounts->each(fn ($a) => $a->setAttribute('balance', $tb[$a->code]['balance'] ?? '0.00'));
        }
        return response()->json(['data' => $accounts]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'unique:acc_accounts,code'], 'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'normal_side' => ['nullable', 'in:debit,credit'], 'parent_id' => ['nullable', 'integer', 'exists:acc_accounts,id'], 'description' => ['nullable', 'string', 'max:500'],
        ]);
        $data['normal_side'] = $data['normal_side'] ?? (in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
        $data['is_system'] = false; $data['is_active'] = true; $data['created_by'] = is_numeric($this->actor($request)) ? (int) $this->actor($request) : null;
        $a = Account::create($data);
        AccountingAuditLog::record('account', $a->id, 'created', null, $data, null, $a->code, $this->actor($request));
        return response()->json(['data' => $a], 201);
    }

    public function updateAccount(Request $request, $id)
    {
        $a = Account::findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'parent_id' => ['nullable', 'integer', 'exists:acc_accounts,id'], 'is_active' => ['nullable', 'boolean']]);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $a->is_system) {
            return response()->json(['message' => 'System accounts cannot be deactivated (they are mapped by posting rules).'], 422);
        }
        // code, type and side are frozen once the account has lines — history must keep its meaning
        $before = $a->only(['name', 'description', 'parent_id', 'is_active']);
        $a->update($data);
        AccountingAuditLog::record('account', $a->id, 'updated', $before, $data, null, $a->code, $this->actor($request));
        return response()->json(['data' => $a]);
    }

    public function deactivateAccount(Request $request, $id)
    {
        $a = Account::findOrFail($id);
        if ($a->is_system) {
            return response()->json(['message' => 'System accounts cannot be deactivated.'], 422);
        }
        $a->update(['is_active' => false]);
        AccountingAuditLog::record('account', $a->id, 'deactivated', ['is_active' => true], ['is_active' => false], $request->input('reason'), $a->code, $this->actor($request));
        return response()->json(['data' => $a, 'message' => $a->hasTransactions() ? 'Deactivated; its history stays on the books.' : 'Deactivated.']);
    }
}
