<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Services\Accounting\VendorCommissionRuleResolver;

/** Vendor commission rules (spec §12). Rules never move history — the applied rule is frozen per line. */
class CommissionRuleController extends CoreController
{
    private const RULES = [
        'scope'          => ['required', 'in:vendor,category,product'],
        'shop_id'        => ['nullable', 'integer'],
        'category_id'    => ['nullable', 'integer'],
        'product_id'     => ['nullable', 'integer'],
        'mode'           => ['required', 'in:percentage,fixed_per_item,fixed_per_order,cost_sheet'],
        'value'          => ['nullable', 'numeric', 'min:0'],
        'priority'       => ['nullable', 'integer'],
        'effective_from' => ['nullable', 'date'],
        'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
        'is_active'      => ['nullable', 'boolean'],
        'note'           => ['nullable', 'string', 'max:500'],
    ];

    public function index(Request $request)
    {
        $q = DB::table('vendor_commission_rules')->orderByDesc('id');
        foreach (['shop_id', 'scope', 'mode', 'product_id', 'category_id'] as $c) {
            if ($request->filled($c)) {
                $q->where($c, $request->input($c));
            }
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        return $q->paginate((int) ($request->limit ?? 30));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = (string) ($request->user()?->id ?? 'system');
        $data['created_at'] = $data['updated_at'] = now();
        $id = DB::table('vendor_commission_rules')->insertGetId($data);
        AccountingAuditLog::record('commission_rule', $id, 'created', null, $data, $data['note'] ?? null, null, $data['created_by']);
        return response()->json(['data' => DB::table('vendor_commission_rules')->find($id)], 201);
    }

    public function update(Request $request, $id)
    {
        $before = DB::table('vendor_commission_rules')->find($id);
        abort_if(!$before, 404);
        $data = $this->validated($request);
        $data['updated_at'] = now();
        DB::table('vendor_commission_rules')->where('id', $id)->update($data);
        AccountingAuditLog::record('commission_rule', $id, 'updated', (array) $before, $data, $data['note'] ?? null, null, (string) ($request->user()?->id ?? 'system'));
        return response()->json(['data' => DB::table('vendor_commission_rules')->find($id)]);
    }

    /** Deactivate (never delete: history references the rule id). */
    public function destroy(Request $request, $id)
    {
        $before = DB::table('vendor_commission_rules')->find($id);
        abort_if(!$before, 404);
        DB::table('vendor_commission_rules')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        AccountingAuditLog::record('commission_rule', $id, 'deactivated', ['is_active' => (bool) $before->is_active], ['is_active' => false], null, null, (string) ($request->user()?->id ?? 'system'));
        return response()->json(['message' => 'Rule deactivated.']);
    }

    /** Per-vendor accounting modes (D1 commission_mode, D2 recognition_mode). */
    public function updateShopModes(Request $request, $id)
    {
        $data = $request->validate(['recognition_mode' => ['nullable', 'in:principal,agent'], 'commission_mode' => ['nullable', 'in:cost_sheet,commission']]);
        $shop = \Marvel\Database\Models\Shop::findOrFail($id);
        $upd = [];
        foreach (['recognition_mode', 'commission_mode'] as $c) {
            if (isset($data[$c]) && \Illuminate\Support\Facades\Schema::hasColumn('shops', $c)) { $upd[$c] = $data[$c]; }
        }
        if ($upd) {
            $before = ['recognition_mode' => $shop->recognition_mode ?? null, 'commission_mode' => $shop->commission_mode ?? null];
            $shop->forceFill($upd)->saveQuietly();
            AccountingAuditLog::record('shop', $shop->id, 'modes_updated', $before, $upd, null, null, (string) ($request->user()?->id ?? 'system'));
        }
        return response()->json(['data' => ['id' => $shop->id, 'recognition_mode' => $shop->recognition_mode ?? 'principal', 'commission_mode' => $shop->commission_mode ?? 'cost_sheet'], 'message' => 'Vendor accounting modes updated.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(self::RULES);
        $need = ['vendor' => 'shop_id', 'category' => 'category_id', 'product' => 'product_id'][$data['scope']];
        if (empty($data[$need])) {
            abort(422, $need . ' is required for a ' . $data['scope'] . ' rule.');
        }
        if ($data['mode'] !== VendorCommissionRuleResolver::COST_SHEET && !isset($data['value'])) {
            abort(422, 'value is required for ' . $data['mode'] . '.');
        }
        $data['value'] = number_format((float) ($data['value'] ?? 0), 4, '.', '');
        $data['priority'] = (int) ($data['priority'] ?? 0);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        return $data;
    }
}
