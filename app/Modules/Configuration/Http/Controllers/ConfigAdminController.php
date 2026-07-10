<?php

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Configuration\Application\ConfigAdminService;
use App\Modules\Configuration\Http\Requests\AssignGroupRequest;
use App\Modules\Configuration\Http\Requests\CreateGroupRequest;
use App\Modules\Configuration\Http\Requests\CreateOptionRequest;
use App\Modules\Configuration\Http\Requests\SetCompatibilityRequest;
use App\Modules\Configuration\Http\Resources\GroupResource;
use App\Modules\Configuration\Infrastructure\Models\ConfigGroup;
use App\Modules\Configuration\Infrastructure\Models\ConfigOption;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin management of configuration groups, options, assignments, compatibility. */
final class ConfigAdminController extends ApiController
{
    public function __construct(private readonly ConfigAdminService $admin)
    {
    }

    /** GET /api/v1/config/groups */
    public function indexGroups(): JsonResponse
    {
        $groups = ConfigGroup::with('options')->orderBy('sort')->get();

        return $this->ok($groups->map(fn (ConfigGroup $g) => GroupResource::make($g))->all());
    }

    /** POST /api/v1/config/groups */
    public function storeGroup(CreateGroupRequest $request): JsonResponse
    {
        $group = $this->admin->createGroup($request->validated(), $request->user()?->uuid);

        return $this->created(GroupResource::make($group));
    }

    /** POST /api/v1/config/groups/{group}/options */
    public function storeOption(CreateOptionRequest $request, ConfigGroup $group): JsonResponse
    {
        $option = $this->admin->createOption($group, $request->validated(), $request->user()?->uuid);

        return $this->created(GroupResource::option($option));
    }

    /** POST /api/v1/config/products/{product}/assignments */
    public function assign(AssignGroupRequest $request, CatalogProduct $product): JsonResponse
    {
        $group = ConfigGroup::where('uuid', $request->input('group_uuid'))->firstOrFail();
        $assignment = $this->admin->assignGroup($product, $group, $request->validated());

        return $this->created([
            'product_uuid' => $product->uuid,
            'group_uuid'   => $group->uuid,
            'required'     => $assignment->is_required_override ?? $group->is_required,
            'sort'         => $assignment->sort,
        ]);
    }

    /** POST /api/v1/config/options/{option}/compatibility */
    public function setCompatibility(SetCompatibilityRequest $request, ConfigOption $option): JsonResponse
    {
        $this->admin->setCompatibility($option, $request->input('rules', []));

        return $this->ok(['option_uuid' => $option->uuid, 'rules' => count($request->input('rules', []))]);
    }
}
