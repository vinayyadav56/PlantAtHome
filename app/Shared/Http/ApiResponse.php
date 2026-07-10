<?php

namespace App\Shared\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The single response envelope every /api/v1 endpoint returns (Section 11):
 *   { "success": bool, "data": mixed|null, "meta": {...}, "errors": [...] }
 * Errors are objects: { "code": "...", "field": "...", "message": "..." }.
 * Centralizing this keeps the contract consistent and the OpenAPI stable.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data'    => $data,
            'meta'    => (object) $meta,
            'errors'  => [],
        ], $status);
    }

    /**
     * @param array<int, array{code?: string, field?: string, message: string}> $errors
     * @param array<string, string|array<string>> $headers extra response headers (e.g. Allow, Retry-After)
     */
    public static function error(array $errors, int $status = 400, mixed $data = null, array $meta = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'data'    => $data,
            'meta'    => (object) $meta,
            'errors'  => array_values($errors),
        ], $status, $headers);
    }

    /**
     * @param array<string, string|array<string>> $headers extra response headers
     */
    public static function message(string $code, string $message, int $status = 400, ?string $field = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($field !== null) {
            $error['field'] = $field;
        }

        return self::error([$error], $status, null, [], $headers);
    }

    /** Wrap a Laravel paginator, moving pagination into meta.pagination. */
    public static function paginated(LengthAwarePaginator $paginator, ?callable $transform = null): JsonResponse
    {
        $items = collect($paginator->items());
        if ($transform) {
            $items = $items->map($transform);
        }

        return self::success($items->values()->all(), [
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
}
