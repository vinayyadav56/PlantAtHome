<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Application\Exceptions\InvalidTokenException;
use App\Modules\Identity\Domain\Events\UserLoggedIn;
use App\Modules\Identity\Infrastructure\Jwt\JwtCodec;
use App\Modules\Identity\Infrastructure\Models\IdentityUser;
use App\Modules\Identity\Infrastructure\TokenIssuer;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * The Identity use-cases: authenticate, refresh, logout, and resolve the user
 * behind an access token. All token minting lives in TokenIssuer; all signing
 * in JwtCodec — this service is the transactional orchestration + the domain
 * event emission (through the outbox, so a login audit consumer runs off the
 * request path).
 */
class AuthService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly JwtCodec $codec,
        private readonly TokenIssuer $tokens,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * @return array{user:IdentityUser, tokens:array}
     *
     * @throws InvalidCredentialsException
     */
    public function login(string $email, string $password, ?string $ip = null): array
    {
        $user = IdentityUser::where('email', $email)->first();

        // Verify a hash even when the user is missing to blunt timing/enumeration.
        $hash = $user?->password ?? '$2y$10$usesomesillystringforsalttoblockenum0000000000000000000000';
        $passwordOk = Hash::check($password, $hash);

        if (! $user || ! $user->is_active || ! $passwordOk) {
            throw new InvalidCredentialsException();
        }

        $tokens = $this->db->transaction(function () use ($user, $ip) {
            $pair = $this->tokens->issueFor($user);

            $user->forceFill(['last_login_at' => Carbon::now()])->save();

            $this->events->publish(new UserLoggedIn(
                userUuid: $user->uuid,
                role: $user->roleName(),
                nurseryId: $user->nursery_id,
                ip: $ip,
            ));

            return $pair;
        });

        return ['user' => $user, 'tokens' => $tokens];
    }

    /**
     * @throws InvalidTokenException
     */
    public function refresh(string $refreshToken): array
    {
        return $this->db->transaction(fn () => $this->tokens->rotate($refreshToken));
    }

    public function logout(IdentityUser $user): void
    {
        $this->tokens->revokeAllFor($user);
    }

    /**
     * Resolve the user behind a bearer access token (used by auth middleware).
     *
     * @throws InvalidTokenException
     */
    public function userFromAccessToken(string $jwt): IdentityUser
    {
        $claims = $this->codec->decode($jwt);
        $uuid = $claims['sub'] ?? null;

        $user = $uuid ? IdentityUser::where('uuid', $uuid)->first() : null;

        if (! $user || ! $user->is_active) {
            throw new InvalidTokenException('Token does not resolve to an active account.', 'TOKEN_SUBJECT_INVALID');
        }

        return $user;
    }
}
