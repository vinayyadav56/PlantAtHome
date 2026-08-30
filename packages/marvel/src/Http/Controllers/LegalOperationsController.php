<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Marvel\Database\Models\Legal\LegalComplianceItem;
use Marvel\Database\Models\Legal\LegalCorrectiveAction;
use Marvel\Database\Models\Legal\LegalRiskItem;
use Marvel\Services\Legal\RiskScoring;

/** Risk register, compliance register and the shared corrective-action queue. */
class LegalOperationsController extends CoreController
{
    /* ── risks ────────────────────────────────────────────────────── */

    public function risks(Request $request)
    {
        return LegalRiskItem::query()
            ->with('owner:id,name')
            ->withCount(['actions as open_actions_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress'])])
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('level'), fn ($q, $l) => $q->where('risk_level', $l))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($qq) => $qq
                ->where('title', 'like', "%{$t}%")->orWhere('risk_code', 'like', "%{$t}%")))
            ->orderByDesc('risk_score')->orderBy('title')
            ->paginate(min((int) $request->query('limit', 50), 100));
    }

    public function storeRisk(Request $request)
    {
        $data = $this->validateRisk($request);
        $data['risk_code'] = $this->nextCode(LegalRiskItem::class, 'risk_code', 'RSK');
        $data['created_by'] = $request->user()->id;

        return LegalRiskItem::create($data)->load('owner:id,name');
    }

    public function updateRisk(Request $request, string $uuid)
    {
        $risk = LegalRiskItem::where('uuid', $uuid)->firstOrFail();
        $risk->update($this->validateRisk($request, false));

        return $risk->fresh('owner:id,name');
    }

    public function destroyRisk(string $uuid)
    {
        LegalRiskItem::where('uuid', $uuid)->firstOrFail()->delete();

        return ['success' => true];
    }

    private function validateRisk(Request $request, bool $required = true): array
    {
        $rule = $required ? 'required' : 'sometimes';
        $request->validate([
            'title' => "{$rule}|string|max:255",
            'category' => "{$rule}|in:" . implode(',', RiskScoring::CATEGORIES),
            'description' => 'nullable|string|max:5000',
            'probability' => 'nullable|integer|min:1|max:5',
            'impact' => 'nullable|integer|min:1|max:5',
            'mitigation_plan' => 'nullable|string|max:5000',
            'contingency_plan' => 'nullable|string|max:5000',
            'owner_id' => 'nullable|integer|exists:users,id',
            'department' => 'nullable|string|max:64',
            'status' => 'nullable|in:open,mitigating,accepted,closed',
            'review_date' => 'nullable|date',
        ]);

        return $request->only([
            'title', 'category', 'description', 'probability', 'impact', 'mitigation_plan',
            'contingency_plan', 'owner_id', 'department', 'status', 'review_date',
        ]);
    }

    /* ── compliance ───────────────────────────────────────────────── */

    public function complianceItems(Request $request)
    {
        return LegalComplianceItem::query()
            ->with(['owner:id,name', 'document:id,uuid,title'])
            ->withCount(['actions as open_actions_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress'])])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('area'), fn ($q, $a) => $q->where('compliance_area', 'like', "%{$a}%"))
            ->when($request->boolean('review_due'), fn ($q) => $q->whereNotNull('next_review_date')->whereDate('next_review_date', '<=', now()))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($qq) => $qq
                ->where('title', 'like', "%{$t}%")->orWhere('item_code', 'like', "%{$t}%")))
            ->orderBy('compliance_area')->orderBy('title')
            ->paginate(min((int) $request->query('limit', 50), 100));
    }

    public function storeCompliance(Request $request)
    {
        $data = $this->validateCompliance($request);
        $data['item_code'] = $this->nextCode(LegalComplianceItem::class, 'item_code', 'CMP');
        $data['created_by'] = $request->user()->id;

        return LegalComplianceItem::create($data)->load('owner:id,name');
    }

    public function updateCompliance(Request $request, string $uuid)
    {
        $item = LegalComplianceItem::where('uuid', $uuid)->firstOrFail();
        $item->update($this->validateCompliance($request, false));

        return $item->fresh(['owner:id,name', 'document:id,uuid,title']);
    }

    public function destroyCompliance(string $uuid)
    {
        LegalComplianceItem::where('uuid', $uuid)->firstOrFail()->delete();

        return ['success' => true];
    }

    private function validateCompliance(Request $request, bool $required = true): array
    {
        $rule = $required ? 'required' : 'sometimes';
        $request->validate([
            'title' => "{$rule}|string|max:255",
            'compliance_area' => "{$rule}|string|max:120",
            'requirement' => 'nullable|string|max:5000',
            'evidence' => 'nullable|string|max:5000',
            'applicable_department' => 'nullable|string|max:64',
            'owner_id' => 'nullable|integer|exists:users,id',
            'status' => 'nullable|in:' . implode(',', LegalComplianceItem::STATUSES),
            'review_frequency' => 'nullable|in:monthly,quarterly,half_yearly,annually',
            'last_review_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',
            'document_id' => 'nullable|integer|exists:legal_documents,id',
        ]);

        return $request->only([
            'title', 'compliance_area', 'requirement', 'evidence', 'applicable_department',
            'owner_id', 'status', 'review_frequency', 'last_review_date', 'next_review_date', 'document_id',
        ]);
    }

    /* ── corrective actions ───────────────────────────────────────── */

    public function actions(Request $request)
    {
        return LegalCorrectiveAction::query()
            ->with('owner:id,name')
            ->when($request->query('source_type'), fn ($q, $s) => $q->where('source_type', $s))
            ->when($request->query('source_id'), fn ($q, $i) => $q->where('source_id', $i))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereNotNull('due_date')
                ->whereDate('due_date', '<', now())->whereNotIn('status', ['done', 'cancelled']))
            ->orderByRaw("FIELD(priority,'critical','high','medium','low')")
            ->orderBy('due_date')
            ->paginate(min((int) $request->query('limit', 50), 100));
    }

    public function storeAction(Request $request)
    {
        $request->validate([
            'source_type' => 'required|in:' . implode(',', LegalCorrectiveAction::SOURCES),
            'source_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'priority' => 'nullable|in:' . implode(',', LegalCorrectiveAction::PRIORITIES),
            'owner_id' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        return LegalCorrectiveAction::create($request->only([
            'source_type', 'source_id', 'title', 'description', 'priority', 'owner_id', 'due_date',
        ]) + ['created_by' => $request->user()->id])->load('owner:id,name');
    }

    public function updateAction(Request $request, string $uuid)
    {
        $action = LegalCorrectiveAction::where('uuid', $uuid)->firstOrFail();
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'priority' => 'nullable|in:' . implode(',', LegalCorrectiveAction::PRIORITIES),
            'owner_id' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
            'status' => 'nullable|in:' . implode(',', LegalCorrectiveAction::STATUSES),
        ]);
        $data = $request->only(['title', 'description', 'priority', 'owner_id', 'due_date', 'status']);
        if (($data['status'] ?? null) === 'done' && ! $action->completed_at) {
            $data['completed_at'] = now();
        }
        $action->update($data);

        return $action->fresh('owner:id,name');
    }

    /* ── dashboard ────────────────────────────────────────────────── */

    public function operationsOverview()
    {
        $byLevel = LegalRiskItem::where('status', '!=', 'closed')
            ->selectRaw('risk_level, COUNT(*) n')->groupBy('risk_level')->pluck('n', 'risk_level');
        $byStatus = LegalComplianceItem::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return [
            'risks' => [
                'total' => (int) $byLevel->sum(),
                'critical' => (int) ($byLevel['critical'] ?? 0),
                'high' => (int) ($byLevel['high'] ?? 0),
                'medium' => (int) ($byLevel['medium'] ?? 0),
                'low' => (int) ($byLevel['low'] ?? 0),
                'review_due' => LegalRiskItem::whereNotNull('review_date')->whereDate('review_date', '<=', now())->count(),
            ],
            'compliance' => [
                'total' => (int) $byStatus->sum(),
                'compliant' => (int) ($byStatus['compliant'] ?? 0),
                'partially_compliant' => (int) ($byStatus['partially_compliant'] ?? 0),
                'non_compliant' => (int) ($byStatus['non_compliant'] ?? 0),
                'under_review' => (int) ($byStatus['under_review'] ?? 0),
                'review_due' => LegalComplianceItem::whereNotNull('next_review_date')->whereDate('next_review_date', '<=', now())->count(),
            ],
            'actions' => [
                'open' => LegalCorrectiveAction::whereIn('status', ['open', 'in_progress'])->count(),
                'overdue' => LegalCorrectiveAction::whereNotNull('due_date')
                    ->whereDate('due_date', '<', now())->whereNotIn('status', ['done', 'cancelled'])->count(),
            ],
            'risk_thresholds' => RiskScoring::thresholds(),
        ];
    }

    private function nextCode(string $model, string $column, string $prefix): string
    {
        $n = 1;
        do {
            $code = sprintf('%s-%03d', $prefix, $n);
            $n++;
        } while ($model::withTrashed()->where($column, $code)->exists());

        return $code;
    }
}
