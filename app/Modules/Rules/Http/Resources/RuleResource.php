<?php

namespace App\Modules\Rules\Http\Resources;

use App\Modules\Rules\Infrastructure\Models\RuleCondition;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;

final class RuleResource
{
    public static function make(RuleDefinition $r): array
    {
        return [
            'uuid'                 => $r->uuid,
            'name'                 => $r->name,
            'scope'                => $r->scope,
            'priority'             => $r->priority,
            'condition_combinator' => $r->condition_combinator,
            'is_active'            => (bool) $r->is_active,
            'conditions'           => $r->relationLoaded('conditions')
                ? $r->conditions->map(fn (RuleCondition $c) => [
                    'fact' => $c->fact, 'operator' => $c->operator, 'value' => $c->rawValue(),
                ])->all()
                : null,
            'actions'              => $r->relationLoaded('actions')
                ? $r->actions->map(fn ($a) => ['type' => $a->type, 'params' => $a->params, 'sort' => $a->sort])->all()
                : null,
        ];
    }
}
