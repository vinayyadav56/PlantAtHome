<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\BundleRule;
use Marvel\Exceptions\MarvelException;
use Marvel\Services\BundleRuleEvaluator;

/**
 * F5 — CRUD + live preview for rule-based bundles. Eligibility references
 * existing products; the matching pool + price are resolved by
 * BundleRuleEvaluator so saved rules never go stale.
 */
class BundleRuleController extends CoreController
{
    protected BundleRuleEvaluator $evaluator;

    public function __construct(BundleRuleEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return BundleRule::query()
            ->when($request->is_active !== null && $request->is_active !== '', function ($q) use ($request) {
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->shop_id, fn ($q) => $q->where('shop_id', $request->shop_id))
            ->when($request->name, fn ($q) => $q->where('name', 'like', "%{$request->name}%"))
            ->orderBy('created_at', 'desc')
            ->paginate($limit);
    }

    public function show($id)
    {
        try {
            $rule = BundleRule::findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }

        $products = $this->evaluator->matchingProducts($rule->toArray());

        return array_merge($rule->toArray(), [
            'matching_count'    => $products->count(),
            'matching_products' => $this->slim($products),
            'price'             => $this->evaluator->priceBreakdown($rule->toArray(), $products),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by_user_id'] = optional($request->user())->id;

        return BundleRule::create($data);
    }

    public function update(Request $request, $id)
    {
        try {
            $rule = BundleRule::findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }

        $rule->update($this->validated($request));

        return $rule->fresh();
    }

    public function destroy($id)
    {
        try {
            $rule = BundleRule::findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }

        $rule->delete();

        return $rule;
    }

    /** Flip a rule's active state. */
    public function toggleActive(Request $request, $id)
    {
        try {
            $rule = BundleRule::findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }

        $rule->is_active = $request->has('is_active')
            ? $request->boolean('is_active')
            : !$rule->is_active;
        $rule->save();

        return $rule;
    }

    /**
     * Live preview for the rule builder — evaluates an UNSAVED rule from the
     * payload and returns the matching plants + price. Lenient: partial input
     * just yields an empty match rather than a validation error.
     */
    public function preview(Request $request)
    {
        $rule = [
            'type'           => $request->input('type', 'product_set'),
            'eligibility'    => (array) $request->input('eligibility', []),
            'quantity_rule'  => (array) $request->input('quantity_rule', []),
            'bundle_price'   => $request->input('bundle_price'),
            'discount_type'  => $request->input('discount_type'),
            'discount_value' => $request->input('discount_value'),
        ];

        $products = $this->evaluator->matchingProducts($rule);

        return [
            'matching_count'    => $products->count(),
            'matching_products' => $this->slim($products),
            'price'             => $this->evaluator->priceBreakdown($rule, $products),
        ];
    }

    /** Shared validation + consistency checks; returns the persistable attributes. */
    protected function validated(Request $request): array
    {
        $type = $request->input('type');

        $rules = [
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'type'                => 'required|in:product_set,cost_condition',
            'quantity_rule'       => 'nullable|array',
            'quantity_rule.min'   => 'nullable|integer|min:1',
            'quantity_rule.max'   => 'nullable|integer|min:1|gte:quantity_rule.min',
            'bundle_price'        => 'nullable|numeric|min:0',
            'discount_type'       => 'nullable|in:flat,percentage',
            'discount_value'      => 'nullable|numeric|min:0',
            'is_active'           => 'nullable|boolean',
            'shop_id'             => 'nullable|integer|exists:shops,id',
        ];

        if ($type === 'product_set') {
            $rules['eligibility']               = 'required|array';
            $rules['eligibility.product_ids']   = 'required|array|min:1';
            $rules['eligibility.product_ids.*'] = 'integer|exists:products,id';
        } elseif ($type === 'cost_condition') {
            $rules['eligibility']             = 'required|array';
            $rules['eligibility.operator']    = 'required|in:<,<=,=,>=,>';
            $rules['eligibility.threshold']   = 'required|numeric|min:0';
            $rules['eligibility.category_id'] = 'nullable|integer|exists:categories,id';
        }

        if ($request->input('discount_type') === 'percentage') {
            $rules['discount_value'] = 'nullable|numeric|min:0|max:100';
        }

        $validated = $request->validate($rules);

        // A bundle is priced EITHER by a fixed bundle_price OR a discount — not both.
        $hasBundlePrice = $request->filled('bundle_price');
        $hasDiscount = $request->filled('discount_type') || $request->filled('discount_value');
        if ($hasBundlePrice && $hasDiscount) {
            throw ValidationException::withMessages([
                'bundle_price' => ['Set either a fixed bundle price or a discount, not both.'],
            ]);
        }
        if ($request->filled('discount_type') && !$request->filled('discount_value')) {
            throw ValidationException::withMessages([
                'discount_value' => ['A discount value is required for the selected discount type.'],
            ]);
        }

        return $validated;
    }

    /** Trim products to a lightweight, payload-safe shape for the preview/list. */
    protected function slim(Collection $products): array
    {
        return $products->take(60)->map(function ($p) {
            return [
                'id'        => $p->id,
                'name'      => $p->name,
                'slug'      => $p->slug ?? null,
                'price'     => (float) ($p->price ?? $p->min_price ?? 0),
                'min_cost'  => isset($p->min_cost) ? (float) $p->min_cost : null,
                'image'     => $p->image ?? null,
            ];
        })->values()->all();
    }
}
