<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetryCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope'   => ['required', 'in:failed,selected,all'],
            'uuids'   => [Rule::requiredIf($this->input('scope') === 'selected'), 'array'],
            'uuids.*' => ['string'],
        ];
    }
}
