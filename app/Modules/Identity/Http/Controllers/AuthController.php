<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\Exceptions\AuthException;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RefreshRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v1 authentication endpoints. All responses use the standard envelope; auth
 * failures are translated from the module's AuthException into a 401/403 error
 * body with a stable machine code.
 */
final class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    /** POST /api/v1/auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->auth->login(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request->ip(),
            );
        } catch (AuthException $e) {
            return $this->fail($e->errorCode(), $e->getMessage(), $e->httpStatus());
        }

        return $this->ok($this->tokenPayload($result['tokens'], $result['user']));
    }

    /** POST /api/v1/auth/refresh */
    public function refresh(RefreshRequest $request): JsonResponse
    {
        try {
            $tokens = $this->auth->refresh((string) $request->input('refresh_token'));
        } catch (AuthException $e) {
            return $this->fail($e->errorCode(), $e->getMessage(), $e->httpStatus());
        }

        return $this->ok($this->tokenPayload($tokens));
    }

    /** POST /api/v1/auth/logout (auth) */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return $this->ok(['logged_out' => true]);
    }

    /** GET /api/v1/auth/me (auth) */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(['user' => UserResource::make($request->user())]);
    }

    private function tokenPayload(array $tokens, $user = null): array
    {
        $payload = ['tokens' => $tokens];
        if ($user) {
            $payload['user'] = UserResource::make($user);
        }

        return $payload;
    }
}
