<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class CategoryResource extends Resource
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
            'id'                   => $this->id,
            'name'                 => $this->name,
            'slug'                 => $this->slug,
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            'parent'               => ['name' => $this->parentCategory->name ?? null],
            'children'             => ChildrenCategoryResource::collection($this->children),
            'products_count'       => $this->products_count,
            'details'              => $this->details,
            'seo_title'            => $this->seo_title ?? null,
            'seo_description'      => $this->seo_description ?? null,
            'noindex'              => (bool) ($this->noindex ?? false),
            'image'                => $this->image,
            'icon'                 => $this->icon,
            'type_id'              => $this->type_id,
            'banner_image'         => $this->banner_image,
            // Homepage placement lives on the category so the person creating one
            // decides it there, instead of it depending on a separate settings edit.
            // Cast explicitly: these arrive from MySQL as 0/1 and the storefront
            // treats them as booleans.
            'show_on_homepage'     => (bool) $this->show_on_homepage,
            'homepage_sort_order'  => (int) $this->homepage_sort_order,
            'is_active'            => (bool) $this->is_active,
            // GST rate inherited by this category's products unless a product
            // sets its own. GstService::categoryTaxRates reads the column
            // directly; exposing it here is what lets the admin form show and
            // edit the current value.
            'tax_rate_id'          => $this->tax_rate_id,
            'type'                 => getResourceData($this->type, []) // if you need extra data then pass key in array by second parameter
        ];
    }
}
