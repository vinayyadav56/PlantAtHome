<?php

namespace App\Modules\Rules\Http\Requests;

use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleScope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:rules.manage
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'scope'                 => ['required', Rule::in(RuleScope::ALL)],
            'priority'              => ['nullable', 'integer'],
            'condition_combinator'  => ['nullable', 'in:all,any,ALL,ANY'],
            'is_active'             => ['nullable', 'boolean'],

            'conditions'            => ['nullable', 'array'],
            'conditions.*.fact'     => ['required_with:conditions', 'string', 'max:128'],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(Operator::ALL)],
            'conditions.*.value'    => ['nullable'],

            'actions'               => ['present', 'array', 'min:1'],
            'actions.*.type'        => ['required', Rule::in(ActionType::ALL)],
            'actions.*.params'      => ['nullable', 'array'],
            'actions.*.sort'        => ['nullable', 'integer'],
        ];
    }

    /**
     * Operator-aware value typing: in/not_in need an array and gt/gte/lt/lte a
     * number. Without this a mis-typed condition is stored and then silently
     * never fires at evaluation time (a scalar value makes in/not_in always false).
     * Fail loudly at authoring time (422) instead.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('conditions', []) as $i => $condition) {
                $op = $condition['operator'] ?? null;
                $value = $condition['value'] ?? null;

                if (in_array($op, [Operator::IN, Operator::NOT_IN], true) && ! is_array($value)) {
                    $v->errors()->add("conditions.{$i}.value", "Operator '{$op}' requires an array value.");
                }
                if (in_array($op, [Operator::GT, Operator::GTE, Operator::LT, Operator::LTE], true) && ! is_numeric($value)) {
                    $v->errors()->add("conditions.{$i}.value", "Operator '{$op}' requires a numeric value.");
                }
            }
        });
    }
}
