<?php

namespace App\Shared\Http;

use App\Shared\Application\DomainActionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps any exception thrown while serving an /api/v1 route to the standard
 * ApiResponse envelope, so the contract holds for validation failures, 404/405,
 * auth errors, and unexpected 500s alike — not just the happy path. Registered
 * as a renderable callback (SharedKernelServiceProvider) scoped to api/v1, so it
 * runs before Laravel's default (which would 302-redirect validation errors and
 * emit non-envelope bodies). Returns null to defer to default rendering.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e): ?JsonResponse
    {
        // A deliberately-constructed response (e.g. thrown from a controller):
        // respect it rather than re-wrapping.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        // Application-level expected failures map to their declared 4xx. If the
        // exception carries itemized violations, surface them in `data`.
        if ($e instanceof DomainActionException) {
            $data = method_exists($e, 'violations') ? ['violations' => $e->violations()] : null;
            $error = ['code' => $e->errorCode(), 'message' => $e->getMessage()];
            if ($e->field() !== null) {
                $error['field'] = $e->field();
            }

            return ApiResponse::error([$error], $e->httpStatus(), $data);
        }

        if ($e instanceof ValidationException) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = ['code' => 'VALIDATION_ERROR', 'field' => $field, 'message' => $message];
                }
            }
            if ($errors === []) {
                $errors[] = ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()];
            }

            return ApiResponse::error($errors, 422);
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::message('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return ApiResponse::message('FORBIDDEN', 'You do not have permission to access this resource.', 403);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiResponse::message('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            // Preserve the Allow header the HTTP spec requires on a 405.
            return ApiResponse::message('METHOD_NOT_ALLOWED', 'The HTTP method is not allowed for this endpoint.', 405, null, $e->getHeaders());
        }

        if ($e instanceof TooManyRequestsHttpException) {
            // Preserve Retry-After / X-RateLimit-* headers.
            return ApiResponse::message('TOO_MANY_REQUESTS', 'Too many requests. Please slow down.', 429, null, $e->getHeaders());
        }

        // Any other HTTP exception keeps its status + headers but gains the envelope.
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Request could not be processed.';

            return ApiResponse::message('HTTP_'.$status, $message, $status, null, $e->getHeaders());
        }

        // Unexpected failure: never leak internals in a non-debug environment.
        $message = config('app.debug')
            ? $e->getMessage()
            : 'An unexpected error occurred.';

        return ApiResponse::message('SERVER_ERROR', $message, 500);
    }
}
