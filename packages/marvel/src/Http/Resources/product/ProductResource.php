<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class ProductResource extends Resource
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
            'type'                 => getResourceData($this->type, ['settings']), // if you need extra data then pass key in array by second parameter
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            'product_type'         => $this->product_type,
            'shop'                 => getResourceData($this->shop, []), // if you need extra data then pass key in array by second parameter
            'sale_price'           => $this->sale_price,
            'max_price'            => $this->max_price,
            'min_price'            => $this->min_price,
            'image'                => $this->image,
            'status'               => $this->status,
            'price'                => $this->price,
            'quantity'             => $this->quantity,
            'unit'                 => $this->unit,
            'sku'                  => $this->sku,
            'sold_quantity'        => $this->sold_quantity,
            'in_flash_sale'        => $this->in_flash_sale,
            'visibility'           => $this->visibility,
            // PlantAtHome — botanical name + short care chips for the storefront card
            'scientific_name'      => optional($this->plantAttribute)->scientific_name,
            'care'                 => $this->plantCareChips(),
        ];
    }

    /** Up to three short care chips (Light · Water · Ease) from plant_attribute. */
    protected function plantCareChips(): array
    {
        $pa = $this->plantAttribute;
        if (!$pa) {
            return [];
        }

        $chips = [];

        $sun = strtolower((string) $pa->sunlight);
        if ($sun !== '') {
            if (preg_match('/low|shade/', $sun))                   $chips[] = 'Low light';
            elseif (preg_match('/full|direct/', $sun))             $chips[] = 'Full sun';
            elseif (preg_match('/bright|indirect|partial/', $sun)) $chips[] = 'Bright';
            else                                                   $chips[] = ucfirst(strtok($sun, ' '));
        }

        $water = strtolower((string) $pa->water_requirement);
        if ($water !== '') {
            if (preg_match('/low|drought|month/', $water))   $chips[] = 'Monthly';
            elseif (preg_match('/high|daily|moist/', $water)) $chips[] = 'Frequent';
            else                                              $chips[] = 'Weekly';
        }

        $chips[] = 'Easy';

        return array_values(array_filter(array_slice($chips, 0, 3)));
    }
}
