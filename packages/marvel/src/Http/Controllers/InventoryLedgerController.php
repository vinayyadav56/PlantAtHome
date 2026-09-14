<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Services\Accounting\InventoryLedgerService;

/** Inventory ledger + valuation for platform-owned stock (spec §20). */
class InventoryLedgerController extends CoreController
{
    public function transactions(Request $request)
    {
        $q = DB::table('inventory_transactions')->orderByDesc('id');
        foreach (['product_id', 'type', 'warehouse_id', 'reference_type', 'reference_id'] as $c) {
            if ($request->filled($c)) {
                $q->where($c, $request->input($c));
            }
        }
        return $q->paginate((int) ($request->limit ?? 30));
    }

    public function valuation(Request $request)
    {
        $q = DB::table('inventory_valuations as v')->leftJoin('products as p', 'p.id', '=', 'v.product_id')->orderBy('v.product_id')
            ->select('v.*', 'p.name as product_name');
        if ($request->filled('product_id')) {
            $q->where('v.product_id', $request->input('product_id'));
        }
        $rows = $q->paginate((int) ($request->limit ?? 50));
        $total = DB::table('inventory_valuations')->selectRaw('COALESCE(SUM(total_value),0) as v, COALESCE(SUM(qty_on_hand),0) as q')->first();
        return response()->json(['data' => $rows, 'total_value' => number_format((float) $total->v, 2, '.', ''), 'total_qty' => (int) $total->q]);
    }

    /** Manual movement: receipt (with cost) or adjustment/damage (± qty at average cost). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:receipt,adjustment,damage'], 'product_id' => ['required', 'integer'], 'variation_option_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'], 'quantity' => ['required', 'integer', 'not_in:0'], 'unit_cost' => ['required_if:type,receipt', 'nullable', 'numeric', 'min:0'],
            'supplier_shop_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $svc = new InventoryLedgerService();
        $actor = (string) ($request->user()?->id ?? 'system');
        $key = 'manual:' . ($data['idempotency_key'] ?? \Illuminate\Support\Str::uuid());
        try {
            $je = $data['type'] === 'receipt'
                ? $svc->receipt((int) $data['product_id'], (int) ($data['variation_option_id'] ?? 0), (int) ($data['warehouse_id'] ?? 0), abs((int) $data['quantity']), number_format((float) $data['unit_cost'], 4, '.', ''), $key, $data['supplier_shop_id'] ?? null, $data['note'] ?? null, $actor)
                : $svc->adjust((int) $data['product_id'], (int) ($data['variation_option_id'] ?? 0), (int) ($data['warehouse_id'] ?? 0), $data['type'] === 'damage' ? -abs((int) $data['quantity']) : (int) $data['quantity'], $key, $data['note'] ?? null, $actor);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['journal' => $je?->entry_number, 'valuation' => $svc->valuation((int) $data['product_id'], (int) ($data['variation_option_id'] ?? 0), (int) ($data['warehouse_id'] ?? 0))], 201);
    }
}
