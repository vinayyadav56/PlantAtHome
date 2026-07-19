<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:marketing.manage
    }

    public function rules(): array
    {
        return [
            'sql_query' => ['required', 'string', 'max:20000'],
        ];
    }
}
