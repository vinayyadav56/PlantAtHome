<?php

namespace App\Modules\Configuration\Http\Resources;

use App\Modules\Configuration\Infrastructure\Models\ConfigGroup;
use App\Modules\Configuration\Infrastructure\Models\ConfigOption;

final class GroupResource
{
    public static function make(ConfigGroup $g): array
    {
        return [
            'uuid'        => $g->uuid,
            'code'        => $g->code,
            'name'        => $g->name,
            'select_type' => $g->select_type,
            'is_required' => (bool) $g->is_required,
            'sort'        => $g->sort,
            'options'     => $g->relationLoaded('options')
                ? $g->options->map(fn (ConfigOption $o) => self::option($o))->all()
                : null,
        ];
    }

    public static function option(ConfigOption $o): array
    {
        return [
            'uuid'         => $o->uuid,
            'sku'          => $o->sku,
            'name'         => $o->name,
            'weight_grams' => $o->weight_grams,
            'base_price'   => (string) $o->base_price,
            'currency'     => $o->currency,
            'status'       => $o->status,
            'sort'         => $o->sort,
        ];
    }
}
