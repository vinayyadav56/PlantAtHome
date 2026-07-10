<?php

namespace App\Modules\Rules\Application;

use App\Modules\Configuration\Application\ConfigurationService;
use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\Events\RuleChanged;
use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleScope;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Rule authoring (admin). A rule + its conditions + actions are created as data
 * in one transaction; a RuleChanged event is emitted through the outbox and the
 * Configuration resolution cache is invalidated (rules feed that pipeline).
 */
class RuleAdminService
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly ConfigurationService $config,
    ) {
    }

    /**
     * @param array{
     *   name:string, scope:string, priority?:int, condition_combinator?:string,
     *   is_active?:bool,
     *   conditions?:array<int,array{fact:string,operator:string,value?:mixed}>,
     *   actions?:array<int,array{type:string,params?:array,sort?:int}>
     * } $data
     */
    public function create(array $data, ?string $actorUuid = null): RuleDefinition
    {
        if (! RuleScope::isValid($data['scope'])) {
            throw DomainActionException::unprocessable("Invalid rule scope: {$data['scope']}", 'INVALID_SCOPE', 'scope');
        }

        $rule = DB::transaction(function () use ($data, $actorUuid) {
            $rule = RuleDefinition::create([
                'name'                 => $data['name'],
                'scope'                => $data['scope'],
                'priority'             => $data['priority'] ?? 0,
                'condition_combinator' => strtolower($data['condition_combinator'] ?? 'all'),
                'is_active'            => $data['is_active'] ?? true,
                'created_by'           => $actorUuid,
                'updated_by'           => $actorUuid,
            ]);

            foreach ($data['conditions'] ?? [] as $c) {
                if (! Operator::isValid($c['operator'])) {
                    throw DomainActionException::unprocessable("Invalid operator: {$c['operator']}", 'INVALID_OPERATOR', 'conditions');
                }
                $rule->conditions()->create([
                    'fact'     => $c['fact'],
                    'operator' => $c['operator'],
                    'value'    => ['v' => $c['value'] ?? null], // wrap so scalars survive the json cast
                ]);
            }

            foreach ($data['actions'] ?? [] as $i => $a) {
                if (! ActionType::isValid($a['type'])) {
                    throw DomainActionException::unprocessable("Invalid action type: {$a['type']}", 'INVALID_ACTION', 'actions');
                }
                $rule->actions()->create([
                    'type'   => $a['type'],
                    'params' => $a['params'] ?? [],
                    'sort'   => $a['sort'] ?? $i,
                ]);
            }

            $this->events->publish(new RuleChanged($rule->uuid, $rule->scope));

            return $rule;
        });

        $this->config->invalidate();

        return $rule->load(['conditions', 'actions']);
    }

    public function setActive(RuleDefinition $rule, bool $active): RuleDefinition
    {
        $rule->update(['is_active' => $active]);
        $this->config->invalidate();
        $this->events->publish(new RuleChanged($rule->uuid, $rule->scope));

        return $rule;
    }

    public function delete(RuleDefinition $rule): void
    {
        $uuid = $rule->uuid;
        $scope = $rule->scope;
        $rule->delete();
        $this->config->invalidate();
        $this->events->publish(new RuleChanged($uuid, $scope));
    }
}
