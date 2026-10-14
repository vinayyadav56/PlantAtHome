<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Rules\ValidCategoryParent;


class CategoryCreateRequest extends FormRequest
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
            'name'        => ['required', 'string'],
            'slug'        => ['nullable', 'string'],
            'type_id'     => ['required', 'integer'],
            'icon'        => ['nullable', 'string'],
            'image'       => ['array'],
            'details'     => ['nullable', 'string'],
            'language'     => ['nullable', 'string'],
            'seo_title'       => ['nullable', 'string', 'max:191'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'noindex'         => ['nullable', 'boolean'],
            'parent'      => ['nullable', 'integer', new ValidCategoryParent($this->typeId())],
            // Other parents this category is also listed under (navigation + roll-up only).
            'placements'   => ['nullable', 'array'],
            'placements.*' => ['integer', new ValidCategoryParent($this->typeId())],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // GST rate inherited by this category's products (nullable = unset).
            'tax_rate_id' => ['nullable', 'integer', 'exists:tax_classes,id'],
        ];
    }

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'         => 'Name field is required',
            'name.string'           => 'Name is not a valid string',
            'name.max:255'          => 'Name can not be more than 255 character',
            'icon.string'           => 'icon is not a valid string',
            'image.string'          => 'image is not a valid image',
            'details.string'        => 'Details is not a valid string',
            'parent.integer'        => 'Parent is not a valid integer',
        ];
    }

    private function typeId(): ?int
    {
        return is_numeric($this->input('type_id')) ? (int) $this->input('type_id') : null;
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
