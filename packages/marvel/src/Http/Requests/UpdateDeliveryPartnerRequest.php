<?php

namespace Marvel\Http\Requests;

/**
 * Same format validation as onboarding, but relaxed for partial edits — first
 * name + mobile are only validated when present (the admin form sends the full
 * object, but partial updates stay safe).
 */
class UpdateDeliveryPartnerRequest extends StoreDeliveryPartnerRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'mobile'     => ['sometimes', 'string', 'regex:/^[+0-9][0-9\-\s]{7,19}$/'],
        ]);
    }
}
