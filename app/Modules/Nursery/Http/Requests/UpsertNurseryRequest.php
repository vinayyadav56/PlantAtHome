<?php

namespace App\Modules\Nursery\Http\Requests;

use App\Modules\Nursery\Infrastructure\Models\Nursery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertNurseryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by v1.can:nurseries.manage
    }

    public function rules(): array
    {
        // {nursery} arrives as a raw uuid-or-slug string (explicit resolution
        // happens in the controller); null on POST.
        $identifier = $this->route('nursery');
        $ignoreId = $identifier
            ? Nursery::query()->where('uuid', $identifier)->orWhere('slug', $identifier)->value('id')
            : null;

        return [
            'name'            => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug'            => ['nullable', 'string', 'max:255', Rule::unique('nursery_nurseries', 'slug')->ignore($ignoreId)],
            'description'     => ['nullable', 'string'],
            'logo'            => ['nullable', 'array'],
            'cover_image'     => ['nullable', 'array'],
            'address'         => ['nullable', 'array'],
            'settings'        => ['nullable', 'array'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Vendor profile + owner credentials (legacy-create parity).
            'contact_person'       => ['nullable', 'string', 'max:255'],
            'mobile'               => ['nullable', 'string', 'max:255'],
            'upi'                  => ['nullable', 'string', 'max:255'],
            'lat'                  => ['nullable', 'numeric'],
            'lng'                  => ['nullable', 'numeric'],
            'categories'           => ['nullable', 'array'],
            'categories.*'         => ['integer'],
            'service_areas'        => ['nullable', 'array'],
            'balance'              => ['nullable', 'array'],
            'balance.payment_info' => ['nullable', 'array'],
            'owner_email'          => ['nullable', 'email'],
            'owner_name'           => ['nullable', 'string', 'max:255'],
            'owner_password'       => ['nullable', 'string', 'min:8', 'required_with:owner_email'],
        ];
    }
}
