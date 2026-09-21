<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;

/**
 * Admin -> Pricing -> Variant Delivery Charges.
 *
 * The variant master (the Size attribute's values) holds three things checkout
 * needs: a stable code, the display order, and what the customer pays to have
 * that size delivered. This edits exactly those three on exactly those rows.
 *
 * Deliberately NOT the generic attribute endpoint. updateAttribute DELETES any
 * value missing from the payload it is given, so driving it from a screen whose
 * job is editing three numbers would put "wipe every size on the platform" one
 * malformed request away — and every variable product references those rows.
 */
class VariantDeliveryChargeController extends CoreController
{
    /** GET variant-delivery-charges — the master, in display order. */
    public function index(Request $request)
    {
        return $this->sizeValues()->map(fn ($value) => [
            'id'              => (int) $value->id,
            'attribute_id'    => (int) $value->attribute_id,
            'value'           => $value->value,
            'code'            => $value->code,
            'sort_order'      => (int) $value->sort_order,
            'delivery_charge' => $value->delivery_charge === null ? null : (float) $value->delivery_charge,
            'products_count'  => $value->products_count,
        ])->values();
    }

    /** PUT variant-delivery-charges/{id} { code?, sort_order?, delivery_charge? } */
    public function update(Request $request, $id)
    {
        $value = AttributeValue::findOrFail((int) $id);
        $this->assertIsASize($value);

        $data = $request->validate([
            // Short, and unique within the attribute — checkout and the order
            // snapshot key off it.
            'code'            => ['nullable', 'string', 'max:8', 'alpha_num'],
            'sort_order'      => ['nullable', 'integer', 'min:0', 'max:999'],
            // Per UNIT. Nullable = not configured, which falls back to the
            // product's own charge rather than to free.
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if (array_key_exists('code', $data) && $data['code'] !== null && $data['code'] !== '') {
            $clash = AttributeValue::where('attribute_id', $value->attribute_id)
                ->where('id', '!=', $value->id)
                ->whereRaw('LOWER(code) = ?', [strtolower($data['code'])])
                ->exists();
            if ($clash) {
                abort(422, 'Another size already uses the code "' . $data['code'] . '".');
            }
        }

        $before = $value->only(['code', 'sort_order', 'delivery_charge']);
        $value->fill(array_intersect_key($data, array_flip(['code', 'sort_order', 'delivery_charge'])));

        if (!$value->isDirty()) {
            return $value->fresh();
        }

        $value->save();

        \Marvel\Database\Models\Accounting\AccountingAuditLog::record(
            'variant_delivery_charge',
            $value->id,
            'updated',
            $before,
            $value->only(['code', 'sort_order', 'delivery_charge']),
            'Admin -> Pricing -> Variant Delivery Charges'
        );

        return $value->fresh();
    }

    /**
     * Sizes, with how many products each one would reprice — the number that
     * makes an edit here feel as consequential as it is.
     */
    private function sizeValues()
    {
        $attributeIds = Attribute::where(function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['size'])->orWhereRaw('LOWER(name) = ?', ['size']);
        })->pluck('id');

        if ($attributeIds->isEmpty()) {
            return collect();
        }

        return AttributeValue::whereIn('attribute_id', $attributeIds)
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function assertIsASize(AttributeValue $value): void
    {
        $isSize = DB::table('attributes')
            ->where('id', $value->attribute_id)
            ->where(function ($q) {
                $q->whereRaw('LOWER(slug) = ?', ['size'])->orWhereRaw('LOWER(name) = ?', ['size']);
            })
            ->exists();

        if (!$isSize) {
            abort(422, 'Only sizes carry a delivery charge.');
        }
    }
}
