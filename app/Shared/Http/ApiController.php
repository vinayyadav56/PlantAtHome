<?php

namespace App\Shared\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base controller for /api/v1 modules. Thin — its only job is to give
 * controllers convenient, uniform access to the ApiResponse envelope so no
 * endpoint hand-rolls its own JSON shape.
 */
abstract class ApiController extends BaseController
{
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, $meta, $status);
    }

    protected function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return ApiResponse::success($data, $meta, 201);
    }

    protected function paginated(LengthAwarePaginator $paginator, ?callable $transform = null): JsonResponse
    {
        return ApiResponse::paginated($paginator, $transform);
    }

    protected function fail(string $code, string $message, int $status = 400, ?string $field = null): JsonResponse
    {
        return ApiResponse::message($code, $message, $status, $field);
    }
}
