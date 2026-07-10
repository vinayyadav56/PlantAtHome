<?php

namespace App\Modules\Rules\Application;

use App\Modules\Rules\Domain\RuleContext;
use App\Modules\Rules\Domain\RuleOutcome;
use App\Modules\Rules\Infrastructure\Models\RuleCondition;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;

/**
 * The data-driven Rules Engine (Section 6). Loads the active rules for a scope,
 * evaluates each rule's conditions (ALL/ANY combinator) against the context, and
 * returns the priority-ordered actions of every matching rule. NO business
 * condition is hardcoded — a rule is data, so changing behaviour is a row edit.
 */
class RulesEngine
{
    public function __construct(private readonly ConditionEvaluator $evaluator)
    {
    }

    public function evaluate(string $scope, RuleContext $ctx): RuleOutcome
    {
        $outcome = new RuleOutcome();

        $rules = RuleDefinition::with(['conditions', 'actions'])
            ->where('scope', $scope)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $ctx)) {
                continue;
            }
            foreach ($rule->actions as $action) {
                $outcome->add($action->type, $action->params ?? [], $rule->uuid, $rule->priority);
            }
        }

        return $outcome;
    }

    private function matches(RuleDefinition $rule, RuleContext $ctx): bool
    {
        $conditions = $rule->conditions;

        // A rule with no conditions always fires.
        if ($conditions->isEmpty()) {
            return true;
        }

        $any = strtolower($rule->condition_combinator) === 'any';

        foreach ($conditions as $condition) {
            /** @var RuleCondition $condition */
            $result = $this->evaluator->matches(
                $condition->fact,
                $condition->operator,
                $condition->rawValue(),
                $ctx,
            );

            if ($any && $result) {
                return true;   // ANY: first match wins
            }
            if (! $any && ! $result) {
                return false;  // ALL: first miss fails
            }
        }

        // ALL with no misses ⇒ true; ANY with no matches ⇒ false.
        return ! $any;
    }
}
