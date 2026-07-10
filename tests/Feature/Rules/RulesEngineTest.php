<?php

namespace Tests\Feature\Rules;

use App\Modules\Rules\Application\RulesEngine;
use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleContext;
use App\Modules\Rules\Domain\RuleScope;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;

/**
 * Phase 4 acceptance: the five worked examples (Section 6) evaluate correctly as
 * pure DATA rows, operators behave, and combinator/priority are honored — with
 * ZERO business logic in PHP.
 */
class RulesEngineTest extends RulesTestCase
{
    private function engine(): RulesEngine
    {
        return $this->app->make(RulesEngine::class);
    }

    /** Create a rule as data. $conditions/$actions are [[fact,op,val]] / [[type,params]]. */
    private function makeRule(string $scope, string $combinator, array $conditions, array $actions, int $priority = 0): RuleDefinition
    {
        $rule = RuleDefinition::create([
            'name' => 'r-'.$scope.'-'.$priority.'-'.count($conditions), 'scope' => $scope,
            'priority' => $priority, 'condition_combinator' => $combinator, 'is_active' => true,
        ]);
        foreach ($conditions as [$fact, $op, $val]) {
            $rule->conditions()->create(['fact' => $fact, 'operator' => $op, 'value' => ['v' => $val]]);
        }
        foreach ($actions as $i => [$type, $params]) {
            $rule->actions()->create(['type' => $type, 'params' => $params, 'sort' => $i]);
        }

        return $rule;
    }

    /* ── the five worked examples ──────────────────────────────────────────── */

    public function test_example_large_plant_requires_large_pot(): void
    {
        $this->makeRule(RuleScope::COMPATIBILITY, 'all',
            [['variant.size', Operator::EQ, 'large']],
            [[ActionType::REQUIRE_OPTION, ['group' => 'pot']]]);

        $fires = $this->engine()->evaluate(RuleScope::COMPATIBILITY, new RuleContext(['variant' => ['size' => 'large']]));
        $skips = $this->engine()->evaluate(RuleScope::COMPATIBILITY, new RuleContext(['variant' => ['size' => 'small']]));

        $this->assertTrue($fires->hasType(ActionType::REQUIRE_OPTION));
        $this->assertTrue($skips->isEmpty());
    }

    public function test_example_pebbles_require_a_pot(): void
    {
        $this->makeRule(RuleScope::COMPATIBILITY, 'all',
            [['selection.options', Operator::CONTAINS, 'pebbles'], ['selection.options', Operator::NOT_CONTAINS, 'pot']],
            [[ActionType::BLOCK_SELECTION, ['reason' => 'need pot']]]);

        $blocked = $this->engine()->evaluate(RuleScope::COMPATIBILITY, new RuleContext(['selection' => ['options' => ['pebbles']]]));
        $ok = $this->engine()->evaluate(RuleScope::COMPATIBILITY, new RuleContext(['selection' => ['options' => ['pebbles', 'pot']]]));

        $this->assertTrue($blocked->hasType(ActionType::BLOCK_SELECTION));
        $this->assertTrue($ok->isEmpty());
    }

    public function test_example_gift_wrap_hidden_for_fragile(): void
    {
        $this->makeRule(RuleScope::VISIBILITY, 'all',
            [['plant.is_fragile', Operator::EQ, true]],
            [[ActionType::HIDE_OPTION, ['group' => 'gift', 'code' => 'wrap']]]);

        $fragile = $this->engine()->evaluate(RuleScope::VISIBILITY, new RuleContext(['plant' => ['is_fragile' => true]]));
        $hardy = $this->engine()->evaluate(RuleScope::VISIBILITY, new RuleContext(['plant' => ['is_fragile' => false]]));

        $this->assertTrue($fragile->hasType(ActionType::HIDE_OPTION));
        $this->assertTrue($hardy->isEmpty());
    }

    public function test_example_repotting_only_in_serviced_cities(): void
    {
        $this->makeRule(RuleScope::SERVICEABILITY, 'all',
            [['service.code', Operator::EQ, 'repotting'], ['city.id', Operator::NOT_IN, ['delhi', 'mumbai']]],
            [[ActionType::RESTRICT_CITY, []]]);

        $blocked = $this->engine()->evaluate(RuleScope::SERVICEABILITY, new RuleContext(['service' => ['code' => 'repotting'], 'city' => ['id' => 'jaipur']]));
        $allowed = $this->engine()->evaluate(RuleScope::SERVICEABILITY, new RuleContext(['service' => ['code' => 'repotting'], 'city' => ['id' => 'delhi']]));

        $this->assertTrue($blocked->hasType(ActionType::RESTRICT_CITY));
        $this->assertTrue($allowed->isEmpty());
    }

    public function test_example_free_premium_packaging_over_threshold(): void
    {
        $this->makeRule(RuleScope::PRICING, 'all',
            [['cart.total', Operator::GTE, 2000]],
            [[ActionType::SET_FREE, ['group' => 'packaging', 'code' => 'premium']]]);

        $free = $this->engine()->evaluate(RuleScope::PRICING, new RuleContext(['cart' => ['total' => 2500]]));
        $paid = $this->engine()->evaluate(RuleScope::PRICING, new RuleContext(['cart' => ['total' => 1500]]));

        $this->assertTrue($free->hasType(ActionType::SET_FREE));
        $this->assertTrue($paid->isEmpty());
    }

    /* ── operator + combinator coverage ────────────────────────────────────── */

    public function test_operators_behave(): void
    {
        // Each case lives in its OWN scope so rules never interfere.
        $cases = [
            ['neq-t', Operator::NEQ, 'a', 'b', true],
            ['neq-f', Operator::NEQ, 'a', 'a', false],
            ['in-t', Operator::IN, 'a', ['a', 'b'], true],
            ['in-f', Operator::IN, 'c', ['a', 'b'], false],
            ['gt-t', Operator::GT, 5, 3, true],
            ['lte-t', Operator::LTE, 3, 3, true],
            ['lt-f', Operator::LT, 5, 3, false],
            ['contains-t', Operator::CONTAINS, ['x', 'y'], 'x', true],
            ['not_contains-t', Operator::NOT_CONTAINS, ['x'], 'z', true],
            ['exists-t', Operator::EXISTS, 'anything', null, true],
        ];
        foreach ($cases as [$scope, $op, $factVal, $val, $expected]) {
            $this->makeRule($scope, 'all', [['f', $op, $val]], [[ActionType::ADD_SURCHARGE, []]]);
        }
        foreach ($cases as [$scope, $op, $factVal, $val, $expected]) {
            $out = $this->engine()->evaluate($scope, new RuleContext(['f' => $factVal]));
            $this->assertSame($expected, $out->hasType(ActionType::ADD_SURCHARGE), "operator {$op} ({$scope})");
        }
    }

    public function test_any_combinator_matches_on_first_true(): void
    {
        $this->makeRule(RuleScope::VENDOR, 'any',
            [['a', Operator::EQ, '1'], ['b', Operator::EQ, '2']],
            [[ActionType::ADD_SURCHARGE, []]]);

        $this->assertTrue($this->engine()->evaluate(RuleScope::VENDOR, new RuleContext(['a' => '1', 'b' => 'x']))->hasType(ActionType::ADD_SURCHARGE));
        $this->assertTrue($this->engine()->evaluate(RuleScope::VENDOR, new RuleContext(['a' => 'x', 'b' => '2']))->hasType(ActionType::ADD_SURCHARGE));
        $this->assertTrue($this->engine()->evaluate(RuleScope::VENDOR, new RuleContext(['a' => 'x', 'b' => 'y']))->isEmpty());
    }

    public function test_actions_are_priority_ordered(): void
    {
        $this->makeRule(RuleScope::PRICING, 'all', [], [[ActionType::ADD_SURCHARGE, ['n' => 2]]], 20);
        $this->makeRule(RuleScope::PRICING, 'all', [], [[ActionType::APPLY_DISCOUNT, ['n' => 1]]], 10);

        $actions = $this->engine()->evaluate(RuleScope::PRICING, new RuleContext([]))->actions();
        $this->assertSame(ActionType::APPLY_DISCOUNT, $actions[0]['type']); // priority 10 first
        $this->assertSame(ActionType::ADD_SURCHARGE, $actions[1]['type']);
    }

    public function test_inactive_rules_do_not_fire(): void
    {
        $rule = $this->makeRule(RuleScope::VISIBILITY, 'all', [], [[ActionType::HIDE_OPTION, []]]);
        $rule->update(['is_active' => false]);

        $this->assertTrue($this->engine()->evaluate(RuleScope::VISIBILITY, new RuleContext([]))->isEmpty());
    }
}
