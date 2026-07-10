<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FileProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:catalog.propose
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'botanical_name' => ['nullable', 'string', 'max:255'],
            'hindi_name'     => ['nullable', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'category_uuid'  => ['nullable', 'uuid'],
            'care'           => ['nullable', 'array'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ];
    }
}
