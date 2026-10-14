<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Marvel\Rules\ValidCategoryParent;


class CategoryUpdateRequest extends FormRequest
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
            'name'        => ['string', 'max:255'],
            'slug'        => ['nullable', 'string'],
            'type_id'     => ['integer'],
            'icon'        => ['nullable', 'string'],
            'image'       => ['array'],
            'details'     => ['nullable', 'string'],
            'language'     => ['nullable', 'string'],
            'seo_title'       => ['nullable', 'string', 'max:191'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'noindex'         => ['nullable', 'boolean'],
            'parent'      => ['nullable', 'integer', new ValidCategoryParent($this->typeId(), $this->categoryId())],
            // Other parents this category is also listed under (navigation + roll-up only).
            'placements'   => ['nullable', 'array'],
            'placements.*' => ['integer', new ValidCategoryParent($this->typeId(), $this->categoryId())],
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
            'name.string'           => 'Name is not a valid string',
            'name.max:255'          => 'Name can not be more than 255 character',
            'image.string'          => 'image is not a valid string',
            'parent.integer'        => 'Parent is not a valid integer',
        ];
    }

    /** The category being edited: the REST route parameter, or the id GraphQL merges in. */
    private function categoryId(): ?int
    {
        $id = $this->route('category') ?? $this->input('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** The vertical after this save: the one sent, else the category's current one. */
    private function typeId(): ?int
    {
        if (is_numeric($this->input('type_id'))) {
            return (int) $this->input('type_id');
        }
        $id = $this->categoryId();
        $type = $id ? DB::table('categories')->where('id', $id)->value('type_id') : null;

        return $type ? (int) $type : null;
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
