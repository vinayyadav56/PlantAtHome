<?php

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Exceptions\InvalidTokenException;
use App\Modules\Identity\Infrastructure\Jwt\JwtCodec;
use App\Modules\Identity\Infrastructure\Models\IdentityUser;
use App\Modules\Identity\Infrastructure\Models\RefreshToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mints and rotates the access + refresh token pair.
 *
 * - Access token: stateless HS256 JWT carrying role + nursery scope.
 * - Refresh token: opaque random string; only its SHA-256 hash is persisted.
 *   Refreshing rotates the token (revoke old → issue new) so a stolen refresh
 *   token is single-use; presenting a revoked one is treated as reuse.
 */
class TokenIssuer
{
    public function __construct(
        private readonly JwtCodec $codec,
        private readonly int $refreshTtl,
    ) {
    }

    /**
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int}
     */
    public function issueFor(IdentityUser $user): array
    {
        $access = $this->codec->issue($user->uuid, [
            'role'    => $user->roleName(),
            'nursery' => $user->nursery_id,
            'name'    => $user->name,
        ]);

        $refreshPlain = $this->newRefreshToken($user);

        return [
            'access_token'  => $access['token'],
            'refresh_token' => $refreshPlain,
            'token_type'    => 'Bearer',
            'expires_in'    => $access['expires_in'],
        ];
    }

    /**
     * Validate a presented refresh token and rotate it, returning a fresh pair.
     *
     * @throws InvalidTokenException if unknown, expired, or already revoked.
     */
    public function rotate(string $presentedRefreshToken): array
    {
        $row = RefreshToken::where('token_hash', $this->hash($presentedRefreshToken))->first();

        if (! $row || ! $row->isActive()) {
            throw new InvalidTokenException('Refresh token is invalid, expired, or revoked.', 'REFRESH_INVALID');
        }

        $user = $row->user;
        if (! $user || ! $user->is_active) {
            throw new InvalidTokenException('Account is not active.', 'ACCOUNT_INACTIVE');
        }

        $newPair = $this->issueFor($user);

        // Revoke the presented token and link it to whatever now-active token
        // belongs to this user (the one we just issued).
        $successor = RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        $row->update([
            'revoked_at'  => Carbon::now(),
            'replaced_by' => $successor?->uuid,
        ]);

        return $newPair;
    }

    /** Revoke every active refresh token for a user (logout / logout-all). */
    public function revokeAllFor(IdentityUser $user): int
    {
        return RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    private function newRefreshToken(IdentityUser $user): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'uuid'       => (string) Str::uuid(),
            'user_id'    => $user->id,
            'token_hash' => $this->hash($plain),
            'expires_at' => Carbon::now()->addSeconds($this->refreshTtl),
        ]);

        return $plain;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
