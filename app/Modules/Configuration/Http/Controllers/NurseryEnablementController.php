<?php

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Configuration\Application\ConfigAdminService;
use App\Modules\Configuration\Http\Requests\SetEnablementRequest;
use App\Modules\Configuration\Infrastructure\Models\ConfigOption;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * A nursery toggles whether it offers a given configuration option. The route is
 * nursery-scoped (v1.nursery), so a vendor can only ever change its own
 * enablement; platform admins may act on any nursery.
 */
final class NurseryEnablementController extends ApiController
{
    public function __construct(private readonly ConfigAdminService $admin)
    {
    }

    /** PUT /api/v1/config/nursery/{nursery}/options/{option}/enablement */
    public function set(SetEnablementRequest $request, string $nursery, ConfigOption $option): JsonResponse
    {
        $row = $this->admin->setEnablement($nursery, $option, (bool) $request->boolean('is_enabled'));

        return $this->ok([
            'nursery_id'  => $nursery,
            'option_uuid' => $option->uuid,
            'is_enabled'  => (bool) $row->is_enabled,
        ]);
    }
}
