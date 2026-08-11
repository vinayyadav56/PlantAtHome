<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'type'        => ['required', 'string', 'max:255'],
            'default'     => ['boolean'],
            'address'     => ['required', 'array'],
            // Canonical address shape (Address::JSON_KEYS) — strict here; the
            // legacy PUT /users/{id} array path stays soft for old clients.
            'address.house_no'              => ['nullable', 'string', 'max:120'],
            'address.street_address'        => ['required', 'string', 'max:255'],
            'address.street_address2'       => ['nullable', 'string', 'max:255'],
            'address.area'                  => ['nullable', 'string', 'max:120'],
            'address.landmark'              => ['nullable', 'string', 'max:255'],
            'address.city'                  => ['required', 'string', 'max:120'],
            'address.state'                 => ['required', 'string', 'max:120'],
            'address.zip'                   => ['required', 'regex:/^[1-9][0-9]{5}$/'],
            'address.country'               => ['nullable', 'string', 'max:64'],
            'address.delivery_instructions' => ['nullable', 'string', 'max:500'],
            // Server-set from the authenticated user in the controller — never trusted from the client.
            'customer_id' => ['nullable', 'exists:Marvel\Database\Models\User,id'],
            // Shopping-City redesign — map-pin coordinates (the draggable pin is the source
            // of truth) + address kind + optional recipient (deliver-to-someone-else saves).
            // rg_* fields are NOT accepted from the client: the server re-derives them from
            // latitude/longitude via the geo/reverse logic in the controller.
            'latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180'],
            'google_place_id' => ['nullable', 'string', 'max:255'],
            'location'        => ['nullable', 'array'],
            'address_type'    => ['nullable', 'string', 'in:home,office,other'],
            'recipient_name'  => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages()
    {
        return [
            'address.street_address.required' => 'Street address is required',
            'address.city.required'           => 'City is required',
            'address.state.required'          => 'State is required',
            'address.zip.required'            => 'PIN code is required',
            'address.zip.regex'               => 'Enter a valid 6-digit PIN code',
        ];
    }

    public function failedValidation(Validator $validator)
    {

        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
