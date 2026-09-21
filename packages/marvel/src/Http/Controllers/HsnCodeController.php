<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\HsnCode;
use Marvel\Database\Models\Product;

/**
 * The HSN/SAC master. Products store the code as a string — that is what is
 * snapshotted on an order line and printed on the invoice — and this is the
 * list they are validated against, so the same pot cannot be filed under 3924
 * on Monday and 39240090 on Tuesday.
 */
class HsnCodeController extends CoreController
{
    public function index(Request $request)
    {
        $query = HsnCode::query()->with('defaultTaxRate:id,name,rate');

        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', $term)->orWhere('description', 'like', $term);
            });
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $query->orderBy('code');

        return $request->filled('limit')
            ? $query->paginate((int) $request->input('limit'))->withQueryString()
            : $query->get();
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $hsn = HsnCode::create($data);

        AccountingAuditLog::record('hsn_code', $hsn->id, 'created', null, $hsn->only(array_keys($data)));

        return $hsn->load('defaultTaxRate:id,name,rate');
    }

    public function update(Request $request, $id)
    {
        $hsn = HsnCode::findOrFail((int) $id);
        $data = $this->validatePayload($request, $hsn->id);

        $before = $hsn->only(['code', 'description', 'default_tax_rate_id', 'is_active']);
        $hsn->fill($data);
        if (!$hsn->isDirty()) {
            return $hsn->load('defaultTaxRate:id,name,rate');
        }
        $hsn->save();

        AccountingAuditLog::record(
            'hsn_code',
            $hsn->id,
            'updated',
            $before,
            $hsn->only(['code', 'description', 'default_tax_rate_id', 'is_active'])
        );

        return $hsn->fresh()->load('defaultTaxRate:id,name,rate');
    }

    /**
     * Deactivates rather than deletes once products reference the code: the
     * string lives on every order line and invoice already issued under it, and
     * an HSN that vanishes from the master turns those into unexplainable rows.
     */
    public function destroy(Request $request, $id)
    {
        $hsn = HsnCode::findOrFail((int) $id);
        $inUse = Product::where('hsn_code', $hsn->code)->exists();

        if ($inUse) {
            $before = $hsn->only(['is_active']);
            $hsn->update(['is_active' => false]);
            AccountingAuditLog::record('hsn_code', $hsn->id, 'deactivated', $before, ['is_active' => false], 'still referenced by products');

            return ['deactivated' => true, 'message' => 'Products still use this code, so it was deactivated rather than deleted.'];
        }

        AccountingAuditLog::record('hsn_code', $hsn->id, 'deleted', $hsn->only(['code', 'description', 'default_tax_rate_id', 'is_active']), null);
        $hsn->delete();

        return ['deleted' => true];
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:hsn_codes,code' . ($ignoreId ? ',' . $ignoreId : '');

        $data = $request->validate([
            'code'                => ['required', 'string', 'max:8', 'regex:/^[0-9]{4,8}$/', $unique],
            'description'         => ['nullable', 'string', 'max:255'],
            // The rate this code usually attracts, offered when an admin picks
            // the code. Never applied on its own — an unconfigured product is 0%.
            'default_tax_rate_id' => ['nullable', 'integer', 'exists:tax_classes,id'],
            'is_active'           => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }
}
