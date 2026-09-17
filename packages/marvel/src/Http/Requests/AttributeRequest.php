<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;


class AttributeRequest extends FormRequest
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
            // Unique per language: the storefront groups a product's chips by
            // attribute, so two attributes named "Size" render one heading with
            // every value listed twice. Slug uniqueness is not enough — the
            // slugifier suffixes collisions (`size-zLh`), leaving the names equal.
            'name'        => [
                'required',
                'string',
                Rule::unique('attributes', 'name')
                    ->where(fn ($q) => $q->where('language', $this->input('language', 'en')))
                    ->ignore($this->route('attribute')),
            ],
            'slug'        => ['nullable', 'string'],
            'shop_id'     => ['required', 'exists:Marvel\Database\Models\Shop,id'],
            'values'      => ['array'],
            'language'     => ['nullable', 'string'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
