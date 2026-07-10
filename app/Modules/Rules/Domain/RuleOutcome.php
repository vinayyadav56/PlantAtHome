<?php

namespace App\Modules\Rules\Domain;

/**
 * The result of evaluating a scope: the priority-ordered list of actions from
 * every rule whose conditions matched. Consumers apply these in order.
 */
final class RuleOutcome
{
    /** @var array<int,array{type:string,params:array,rule_uuid:string,priority:int}> */
    private array $actions = [];

    public function add(string $type, array $params, string $ruleUuid, int $priority): void
    {
        $this->actions[] = [
            'type'      => $type,
            'params'    => $params,
            'rule_uuid' => $ruleUuid,
            'priority'  => $priority,
        ];
    }

    /** @return array<int,array{type:string,params:array,rule_uuid:string,priority:int}> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return array<int,array> actions of a given type */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->actions, fn ($a) => $a['type'] === $type));
    }

    public function hasType(string $type): bool
    {
        return $this->ofType($type) !== [];
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    public function toArray(): array
    {
        return $this->actions;
    }
}
