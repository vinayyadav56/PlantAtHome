<?php

namespace App\Modules\Rules\Database\Seeders;

use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleScope;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the five worked examples from Section 6 as pure DATA rows — proof that
 * business behaviour lives in the database, not in PHP. Idempotent (keyed by
 * name); safe on every deploy.
 */
class RulesExampleSeeder extends Seeder
{
    public function run(): void
    {
        $examples = [
            [
                'name' => 'Large plant requires large pot', 'scope' => RuleScope::COMPATIBILITY, 'priority' => 10,
                'combinator' => 'all',
                'conditions' => [['variant.size', Operator::EQ, 'large']],
                'actions'    => [[ActionType::REQUIRE_OPTION, ['group' => 'pot', 'message' => 'A large plant needs a large pot.']]],
            ],
            [
                'name' => 'Pebbles require a pot', 'scope' => RuleScope::COMPATIBILITY, 'priority' => 20,
                'combinator' => 'all',
                'conditions' => [
                    ['selection.options', Operator::CONTAINS, 'pebbles'],
                    ['selection.options', Operator::NOT_CONTAINS, 'pot'],
                ],
                'actions' => [[ActionType::BLOCK_SELECTION, ['reason' => 'Pebbles require a pot to be selected.']]],
            ],
            [
                'name' => 'Gift wrap hidden for fragile plants', 'scope' => RuleScope::VISIBILITY, 'priority' => 10,
                'combinator' => 'all',
                'conditions' => [['plant.is_fragile', Operator::EQ, true]],
                'actions'    => [[ActionType::HIDE_OPTION, ['group' => 'gift', 'code' => 'wrap']]],
            ],
            // NOTE: no SERVICEABILITY/restrict_city example is seeded — no module
            // consumes those scopes/actions yet, so an enabled rule using them would
            // silently do nothing (misleading). Seed only rules that actually fire.
            [
                'name' => 'Free premium packaging over 2000', 'scope' => RuleScope::PRICING, 'priority' => 10,
                'combinator' => 'all',
                'conditions' => [['cart.total', Operator::GTE, 2000]],
                'actions'    => [[ActionType::SET_FREE, ['group' => 'packaging', 'code' => 'premium']]],
            ],
        ];

        foreach ($examples as $ex) {
            if (RuleDefinition::where('name', $ex['name'])->exists()) {
                continue; // idempotent
            }

            $rule = RuleDefinition::create([
                'name'                 => $ex['name'],
                'scope'                => $ex['scope'],
                'priority'             => $ex['priority'],
                'condition_combinator' => $ex['combinator'],
                'is_active'            => true,
            ]);
            foreach ($ex['conditions'] as [$fact, $operator, $value]) {
                $rule->conditions()->create(['fact' => $fact, 'operator' => $operator, 'value' => ['v' => $value]]);
            }
            foreach ($ex['actions'] as $i => [$type, $params]) {
                $rule->actions()->create(['type' => $type, 'params' => $params, 'sort' => $i]);
            }
        }
    }
}
