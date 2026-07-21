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

    public function failedValidation(Validator $validator)
    {

        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
