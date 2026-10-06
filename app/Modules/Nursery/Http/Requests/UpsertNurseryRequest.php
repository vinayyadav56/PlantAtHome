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
            // Fulfilment — identical rules to the legacy ShopUpdateRequest. Without these the
            // whole "Delivery & fulfilment" step was silently dropped on every V2-backed update:
            // validated() never carried the keys, so fill() never saw them.
            'delivery_mode'               => ['sometimes', 'in:platform,self'],
            'self_delivery'               => ['nullable', 'array'],
            'self_delivery.contact_name'  => ['nullable', 'string', 'max:120'],
            'self_delivery.contact_phone' => ['nullable', 'string', 'max:20'],
            'self_delivery.radius_km'     => ['nullable', 'numeric', 'min:0', 'max:500'],
            'self_delivery.same_day'      => ['nullable', 'boolean'],
            'self_delivery.cod'           => ['nullable', 'boolean'],
            'self_delivery.days'          => ['nullable', 'string', 'max:255'],
            'self_delivery.hours'         => ['nullable', 'string', 'max:255'],
            'self_delivery.notes'         => ['nullable', 'string', 'max:500'],
            'balance'              => ['nullable', 'array'],
            'balance.payment_info' => ['nullable', 'array'],
            'owner_email'          => ['nullable', 'email'],
            'owner_name'           => ['nullable', 'string', 'max:255'],
            'owner_password'       => ['nullable', 'string', 'min:8', 'required_with:owner_email'],
        ];
    }
}
