<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Marvel\Enums\ProductType;
use Marvel\Helper\ResourceHelpers;

class GetSingleProductResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                           => $this->id,
            'name'                         => $this->name,
            'slug'                         => $this->slug,
            'type'                         => getResourceData($this->type, []), // if you need extra data then pass key in array by second parameter
            'language'                     => $this->language,
            'translated_languages'         => $this->translated_languages,
            'product_type'                 => $this->product_type,
            'categories'                   => getResourceCollection($this->categories, []), // if you need extra data then pass key in array by second parameter
            'tags'                         => getResourceCollection($this->tags, []), // if you need extra data then pass key in array by second parameter
            'metas'                        => $this->metas,
            'digital_file'                 => $this->digital_file,
            'variations'                   => getVariations($this->variations),
            'variation_options'            => $this->variation_options,
            'shop_id'                      => $this->shop_id,
            'shop'                         => getResourceData($this->shop, []), // if you need extra data then pass key in array by second parameter
            'author'                       => getResourceData($this->author, []),  // if you need extra data then pass key in array by second parameter
            'manufacturer'                 => getResourceData($this->manufacturer, []),  // if you need extra data then pass key in array by second parameter
            'related_products'             => RelatedProductResource::collection($this->related_products),
            'description'                  => $this->description,
            'in_stock'                     => $this->in_stock,
            'is_taxable'                   => $this->is_taxable,
            'is_digital'                   => $this->is_digital,
            'is_external'                  => $this->is_external,
            'external_product_url'         => $this->external_product_url,
            'external_product_button_text' => $this->external_product_button_text,
            'sale_price'                   => $this->sale_price,
            'max_price'                    => $this->max_price,
            'min_price'                    => $this->min_price,
            'ratings'                      => $this->ratings,
            'total_reviews'                => $this->total_reviews,
            'rating_count'                 => $this->rating_count,
            'my_review'                    => $this->my_review,
            'in_wishlist'                  => $this->in_wishlist,
            'sku'                          => $this->sku,
            'gallery'                      => $this->gallery,
            'image'                        => $this->image,
            'plant_attribute'              => $this->plantAttribute,
            'images'                       => $this->images,
            // PlantAtHome — bundle items + buy-together add-ons
            'bundle_items'                 => $this->relationLoaded('bundleItems') ? ProductResource::mapInclusions($this->bundleItems) : [],
            'bundle_total_value'           => $this->relationLoaded('bundleItems') ? $this->bundle_total_value : null,
            'addons'                       => $this->relationLoaded('addons') ? ProductResource::mapInclusions($this->addons) : [],
            'video'                        => $this->video,
            'status'                       => $this->status,
            'height'                       => $this->height,
            'length'                       => $this->length,
            'width'                        => $this->width,
            'price'                        => $this->price,
            'delivery_charge'              => $this->delivery_charge,
            // Bundle stock is derived (MIN over components); surface it as the
            // public `quantity` so storefront stock gates work. Cost/margin and
            // pricing internals are NEVER exposed here (public PDP) — the admin
            // reads those from the dedicated /bundles/{id} endpoint.
            'quantity'                     => $this->product_type === ProductType::BUNDLE
                ? $this->available_bundle_inventory
                : $this->quantity,
            'is_bundle'                    => $this->product_type === ProductType::BUNDLE,
            'bundle_type'                  => $this->bundle_type,
            'available_inventory'          => $this->product_type === ProductType::BUNDLE
                ? $this->available_bundle_inventory
                : $this->quantity,
            'display_priority'             => $this->display_priority,
            'unit'                         => $this->unit,
            'in_flash_sale'                => $this->in_flash_sale
        ];
    }
}
