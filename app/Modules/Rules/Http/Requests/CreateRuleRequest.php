<?php

namespace App\Modules\Rules\Http\Requests;

use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleScope;
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
}
