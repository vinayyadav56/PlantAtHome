<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name'        => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'sql_query'   => [$creating ? 'required' : 'sometimes', 'string', 'max:20000'],
            'status'      => ['sometimes', 'in:draft,ready,archived'],
        ];
    }
}
