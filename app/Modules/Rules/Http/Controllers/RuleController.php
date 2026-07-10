<?php

namespace App\Modules\Rules\Http\Controllers;

use App\Modules\Rules\Application\RuleAdminService;
use App\Modules\Rules\Http\Requests\CreateRuleRequest;
use App\Modules\Rules\Http\Resources\RuleResource;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin CRUD for data-driven rules. Adding/removing a rule row changes system
 * behaviour with zero deploys — the whole point of the Rules Engine.
 */
final class RuleController extends ApiController
{
    public function __construct(private readonly RuleAdminService $rules)
    {
    }

    /** GET /api/v1/rules?scope= */
    public function index(Request $request): JsonResponse
    {
        $query = RuleDefinition::with(['conditions', 'actions'])->orderBy('scope')->orderBy('priority');
        if ($scope = $request->query('scope')) {
            $query->where('scope', $scope);
        }

        return $this->ok($query->get()->map(fn (RuleDefinition $r) => RuleResource::make($r))->all());
    }

    /** POST /api/v1/rules */
    public function store(CreateRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->create($request->validated(), $request->user()?->uuid);

        return $this->created(RuleResource::make($rule));
    }

    /** GET /api/v1/rules/{rule} */
    public function show(RuleDefinition $rule): JsonResponse
    {
        return $this->ok(RuleResource::make($rule->load(['conditions', 'actions'])));
    }

    /** PATCH /api/v1/rules/{rule} — toggle active. */
    public function update(Request $request, RuleDefinition $rule): JsonResponse
    {
        $updated = $this->rules->setActive($rule, $request->boolean('is_active'));

        return $this->ok(RuleResource::make($updated->load(['conditions', 'actions'])));
    }

    /** DELETE /api/v1/rules/{rule} */
    public function destroy(RuleDefinition $rule): JsonResponse
    {
        $this->rules->delete($rule);

        return $this->ok(['deleted' => true]);
    }
}
