<?php

namespace App\Modules\Rules\Application;

use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleContext;

/**
 * Evaluates a single { fact, operator, value } condition against a RuleContext.
 * Pure, data-driven — no business fact is special-cased; the fact path and the
 * operator come entirely from the rule row.
 */
class ConditionEvaluator
{
    public function matches(string $fact, string $operator, mixed $value, RuleContext $ctx): bool
    {
        $actual = $ctx->get($fact);

        return match ($operator) {
            Operator::EQ           => $this->looseEq($actual, $value),
            Operator::NEQ          => ! $this->looseEq($actual, $value),
            Operator::IN           => is_array($value) && $this->inArray($actual, $value),
            Operator::NOT_IN       => is_array($value) && ! $this->inArray($actual, $value),
            Operator::GT           => $this->isNumeric($actual, $value) && $actual > $value,
            Operator::GTE          => $this->isNumeric($actual, $value) && $actual >= $value,
            Operator::LT           => $this->isNumeric($actual, $value) && $actual < $value,
            Operator::LTE          => $this->isNumeric($actual, $value) && $actual <= $value,
            Operator::EXISTS       => $ctx->has($fact),
            Operator::CONTAINS     => $this->contains($actual, $value),
            Operator::NOT_CONTAINS => ! $this->contains($actual, $value),
            default                => false, // unknown operator never matches
        };
    }

    /** Loose equality that also treats booleans/numbers written as strings sanely. */
    private function looseEq(mixed $actual, mixed $value): bool
    {
        if (is_bool($value) || is_bool($actual)) {
            return $this->toBool($actual) === $this->toBool($value);
        }

        return (string) $actual === (string) $value;
    }

    /** The fact (array or string) contains the value. */
    private function contains(mixed $actual, mixed $value): bool
    {
        if (is_array($actual)) {
            return $this->inArray($value, $actual);
        }
        if (is_string($actual) && is_scalar($value)) {
            return $value !== '' && str_contains($actual, (string) $value);
        }

        return false;
    }

    /** @param array<int,mixed> $haystack */
    private function inArray(mixed $needle, array $haystack): bool
    {
        foreach ($haystack as $item) {
            if ($this->looseEq($needle, $item)) {
                return true;
            }
        }

        return false;
    }

    private function isNumeric(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b);
    }

    private function toBool(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
