<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

class ProductUpdateRequest extends FormRequest
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
        $productStatus = [
            ProductStatus::UNDER_REVIEW,
            ProductStatus::APPROVED,
            ProductStatus::REJECTED,
            ProductStatus::PUBLISH,
            ProductStatus::UNPUBLISH,
            ProductStatus::DRAFT,
        ];

        $productType = [
            ProductType::SIMPLE,
            ProductType::VARIABLE,
            ProductType::BUNDLE
        ];
        return [
            'name'                         => ['string', 'max:255'],
            'price'                        => ['nullable', 'numeric'],
            'sale_price'                   => ['nullable', 'lte:price'],
            'type_id'                      => ['exists:Marvel\Database\Models\Type,id'],
            'shop_id'                      => ['exists:Marvel\Database\Models\Shop,id'],
            'manufacturer_id'              => ['nullable', 'exists:Marvel\Database\Models\Manufacturer,id'],
            'author_id'                    => ['nullable', 'exists:Marvel\Database\Models\Author,id'],
            'categories'                   => ['exists:Marvel\Database\Models\Category,id'],
            'tags'                         => ['exists:Marvel\Database\Models\Tag,id'],
            'dropoff_locations'            => ['array'],
            'pickup_locations'             => ['array'],
            'language'                     => ['nullable', 'string'],
            'digital_file'                 => ['array'],
            'product_type'                 => ['required', Rule::in($productType)],
            'unit'                         => ['string'],
            'description'                  => ['nullable', 'string', 'max:10000'],
            'quantity'                     => ['nullable', 'integer'],
            'sku'                          => ['string', Rule::unique('variation_options')->where(fn ($query) => $query->whereSku($this->sku))],
            'image'                        => ['array'],
            'gallery'                      => ['array'],
            'video'                        => ['array'],
            'status'                       => ['string', Rule::in($productStatus)],
            'height'                       => ['nullable', 'string'],
            'length'                       => ['nullable', 'string'],
            'width'                        => ['nullable', 'string'],
            'external_product_url'         => ['nullable', 'string'],
            'external_product_button_text' => ['nullable', 'string'],
            'in_stock'                     => ['boolean'],
            'is_taxable'                   => ['boolean'],
            'is_digital'                   => ['boolean'],
            'is_external'                  => ['boolean'],
            'is_rental'                    => ['boolean'],
            // Bundle composition + config (authoritative rules live in BundleController).
            'bundle_type'                  => ['nullable', 'string', Rule::in(['manual', 'auto'])],
            'pricing_mode'                 => ['nullable', 'string', Rule::in(['fixed', 'percentage', 'flat', 'margin'])],
            'pricing_value'                => ['nullable', 'numeric', 'min:0'],
            'display_priority'             => ['nullable', 'integer'],
            'bundle_config'                => ['nullable', 'array'],
            'bundle_items'                 => ['nullable', 'array'],
            'bundle_items.*.id'            => ['required_with:bundle_items', 'integer', 'exists:Marvel\Database\Models\Product,id'],
            'bundle_items.*.quantity'      => ['nullable', 'integer', 'min:1'],
            // Curated botanical text (plants only; see ProductRepository::syncPlantAttributeFromRequest).
            'plant_attribute'              => ['nullable', 'array'],
            'plant_attribute.hindi_name'   => ['nullable', 'string', 'max:191'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
