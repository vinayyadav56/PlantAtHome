<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Marvel\Enums\ProductType;

class RelatedProductResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'slug'                 => $this->slug,
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            'product_type'         => $this->product_type,
            'city_based'           => $this->city_based,
            'in_stock'             => $this->in_stock,
            'sale_price'           => $this->sale_price,
            'max_price'            => $this->max_price,
            'min_price'            => $this->min_price,
            'image'                => $this->image,
            'video'                => $this->video,
            'price'                => $this->price,
            'unit'                 => $this->unit
        ];
        // A variable product is only ever added with a variant: its cheapest enabled one
        // (ProductRepository::fetchRelated), or null when it has none to sell.
        if ($this->product_type === ProductType::VARIABLE) {
            $v = $this->default_variation;
            $data['default_variation'] = $v ? [
                'id'         => $v->id,
                'title'      => $v->title,
                'price'      => $v->price,
                'sale_price' => $v->sale_price,
                'options'    => $v->options,
            ] : null;
        }

        return $data;
    }
}