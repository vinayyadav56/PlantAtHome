<?php

namespace App\Modules\Serviceability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can + nursery-scope in the controller
    }

    public function rules(): array
    {
        return [
            'nursery_id'         => ['required', 'uuid'],
            'city_uuid'          => ['required', 'uuid'],
            'delivery_radius_km' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'center_lat'         => ['nullable', 'numeric', 'between:-90,90'],
            'center_lng'         => ['nullable', 'numeric', 'between:-180,180'],
            'is_active'          => ['nullable', 'boolean'],
        ];
    }
}
