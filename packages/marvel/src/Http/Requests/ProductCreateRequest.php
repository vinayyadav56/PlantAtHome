<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

class ProductCreateRequest extends FormRequest
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
     * Normalize empty-string numeric inputs to null so a blank field (e.g. the
     * admin form's untouched delivery_charge) passes `nullable|numeric` and is
     * stored as null instead of 422-ing or crashing the decimal cast.
     */
    protected function prepareForValidation()
    {
        $numeric = ['price', 'sale_price', 'min_price', 'max_price', 'quantity', 'delivery_charge'];
        $patch = [];
        foreach ($numeric as $col) {
            if ($this->has($col) && is_string($this->input($col)) && trim($this->input($col)) === '') {
                $patch[$col] = null;
            }
        }
        if ($patch) {
            $this->merge($patch);
        }
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
            'name'                         => ['required', 'string', 'max:255'],
            'slug'                         => ['nullable', 'string'],
            'price'                        => ['nullable', 'numeric'],
            'delivery_charge'              => ['nullable', 'numeric'],
            'sale_price'                   => ['nullable', 'lte:price'],
            'type_id'                      => ['required', 'exists:Marvel\Database\Models\Type,id'],
            'shop_id'                      => ['required', 'exists:Marvel\Database\Models\Shop,id'],
            'manufacturer_id'              => ['nullable', 'exists:Marvel\Database\Models\Manufacturer,id'],
            'author_id'                    => ['nullable', 'exists:Marvel\Database\Models\Author,id'],
            'product_type'                 => ['required', Rule::in($productType)],
            'categories'                   => ['array'],
            'tags'                         => ['array'],
            'language'                     => ['nullable', 'string'],
            'dropoff_locations'            => ['array'],
            'pickup_locations'             => ['array'],
            'digital_file'                 => ['array'],
            'variations'                   => ['array'],
            'variation_options'            => ['array'],
            'quantity'                     => ['nullable', 'integer'],
            'unit'                         => ['required', 'string'],
            'description'                  => ['nullable', 'string', 'max:10000'],
            'sku'                          => ['string', 'unique:variation_options,sku'],
            'image'                        => ['array'],
            'size_guide'                   => ['nullable', 'array'],
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
            "variation_options.upsert.*.sku" => ['string', 'unique:variation_options,sku'],
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
            'plant_attribute.scientific_name'        => ['nullable', 'string', 'max:191'],
            'plant_attribute.difficulty_level'       => ['nullable', 'string', 'max:191'],
            'plant_attribute.season'                 => ['nullable', 'string', 'max:191'],
            'plant_attribute.life_cycle'             => ['nullable', 'string', 'max:191'],
            'plant_attribute.soil_type'              => ['nullable', 'string', 'max:191'],
            'plant_attribute.humidity'               => ['nullable', 'string', 'max:191'],
            'plant_attribute.fertilizer_requirement' => ['nullable', 'string', 'max:191'],
            'plant_attribute.width_range'            => ['nullable', 'string', 'max:191'],
            'plant_attribute.is_flowering'           => ['nullable', 'boolean'],
            'plant_attribute.fruit_bearing'          => ['nullable', 'boolean'],
            'plant_attribute.care_guide'             => ['nullable', 'string', 'max:10000'],
            'plant_attribute.planting_guide'         => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
