<?php

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Exceptions\InvalidTokenException;
use App\Modules\Identity\Infrastructure\Jwt\JwtCodec;
use App\Modules\Identity\Infrastructure\Models\IdentityUser;
use App\Modules\Identity\Infrastructure\Models\RefreshToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Mints and rotates the access + refresh token pair.
 *
 * - Access token: stateless HS256 JWT carrying role + nursery scope.
 * - Refresh token: opaque random string; only its SHA-256 hash is persisted.
 *   Rotation is atomic single-use — the presented token is claimed with a
 *   conditional UPDATE (…WHERE revoked_at IS NULL), so two concurrent requests
 *   with the same token can never both succeed. Presenting an already-revoked
 *   token is treated as reuse (theft) and revokes the whole token family for
 *   that user, per OAuth 2.0 Security BCP §4.14.2 / RFC 6819.
 */
class TokenIssuer
{
    public function __construct(
        private readonly JwtCodec $codec,
        private readonly int $refreshTtl,
        private readonly LoggerInterface $logger,
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
     * Manages its own atomicity — do NOT wrap this in a caller transaction, or
     * the reuse-response family revocation would be rolled back on the throw.
     *
     * @throws InvalidTokenException if unknown, expired, already used, or reused.
     */
    public function rotate(string $presentedRefreshToken): array
    {
        $hash = $this->hash($presentedRefreshToken);
        $row = RefreshToken::where('token_hash', $hash)->first();

        if (! $row) {
            throw new InvalidTokenException('Refresh token is invalid.', 'REFRESH_INVALID');
        }

        // Reuse detection: the token exists but was already revoked (rotated
        // earlier or logged out). Presenting it now is a reuse/theft signal —
        // revoke the entire family so a stolen lineage can't continue. This
        // revocation runs OUTSIDE a transaction so it commits despite the throw.
        if (! $row->isActive()) {
            $revoked = $this->revokeAllFor($row->user);
            $this->logger->warning('[identity] refresh token reuse detected — family revoked', [
                'user_id'          => $row->user_id,
                'token_uuid'       => $row->uuid,
                'sessions_revoked' => $revoked,
            ]);

            throw new InvalidTokenException('Refresh token has already been used or revoked.', 'REFRESH_REUSED');
        }

        // Happy path: atomically claim (single-use) and mint the successor. If
        // anything here fails, the whole rotation rolls back cleanly.
        return DB::transaction(function () use ($row) {
            $claimed = RefreshToken::where('id', $row->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);

            if ($claimed === 0) {
                // Lost a concurrent race for the SAME token — reject this request
                // without nuking the successor the winner just minted.
                throw new InvalidTokenException('Refresh token has already been used.', 'REFRESH_REUSED');
            }

            $user = $row->user;
            if (! $user || ! $user->is_active) {
                throw new InvalidTokenException('Account is not active.', 'ACCOUNT_INACTIVE');
            }

            $newPair = $this->issueFor($user);

            // Link the just-revoked token to its successor for the audit trail.
            $successor = RefreshToken::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->first();
            RefreshToken::where('id', $row->id)->update(['replaced_by' => $successor?->uuid]);

            return $newPair;
        });
    }

    /** Revoke every active refresh token for a user (logout / reuse response). */
    public function revokeAllFor(?IdentityUser $user): int
    {
        if (! $user) {
            return 0;
        }

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
