<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Domain\AttributeType;
use App\Modules\Catalog\Http\Requests\CreateAttributeRequest;
use App\Modules\Catalog\Infrastructure\Models\Attribute;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

final class AttributeController extends ApiController
{
    /** GET /api/v1/catalog/attributes */
    public function index(): JsonResponse
    {
        return $this->ok(
            Attribute::query()->orderBy('name')->get()->map(fn (Attribute $a) => $this->present($a))->all(),
        );
    }

    /** POST /api/v1/catalog/attributes (admin) */
    public function store(CreateAttributeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $scope = $data['scope'] ?? AttributeType::SCOPE_UNIVERSAL;

        $categoryId = null;
        if ($scope === AttributeType::SCOPE_CATEGORY && ! empty($data['category_uuid'])) {
            $categoryId = Category::byUuid($data['category_uuid'])->value('id');
        }

        $attribute = Attribute::create([
            'code'        => $data['code'],
            'name'        => $data['name'],
            'type'        => $data['type'],
            'scope'       => $scope,
            'category_id' => $categoryId,
            'options'     => $data['options'] ?? null,
            'created_by'  => $request->user()?->uuid,
            'updated_by'  => $request->user()?->uuid,
        ]);

        return $this->created($this->present($attribute));
    }

    private function present(Attribute $a): array
    {
        return [
            'uuid'    => $a->uuid,
            'code'    => $a->code,
            'name'    => $a->name,
            'type'    => $a->type,
            'scope'   => $a->scope,
            'options' => $a->options,
        ];
    }
}
