<?php

namespace App\Modules\Identity\Infrastructure\Jwt;

use App\Modules\Identity\Application\Exceptions\InvalidTokenException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Encodes/decodes the short-lived access token. HS256 signed with the Identity
 * secret (config/identity.php, falls back to APP_KEY). Wraps firebase/php-jwt
 * so the rest of the module never touches the library directly and never has to
 * reason about clock skew or exception types.
 */
class JwtCodec
{
    public function __construct(private readonly array $config)
    {
        JWT::$leeway = (int) ($config['leeway'] ?? 0);
    }

    /**
     * @param  array<string,mixed>  $claims  domain claims (role, nursery, …)
     * @return array{token:string, jti:string, expires_at:\Illuminate\Support\Carbon, expires_in:int}
     */
    public function issue(string $subject, array $claims): array
    {
        $now = Carbon::now();
        $ttl = (int) $this->config['access_ttl'];
        $exp = (clone $now)->addSeconds($ttl);
        $jti = (string) Str::uuid();

        $payload = array_merge($claims, [
            'iss' => $this->config['issuer'],
            'sub' => $subject,
            'jti' => $jti,
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $exp->getTimestamp(),
        ]);

        return [
            'token'      => JWT::encode($payload, $this->secret(), $this->config['algo']),
            'jti'        => $jti,
            'expires_at' => $exp,
            'expires_in' => $ttl,
        ];
    }

    /**
     * @return array<string,mixed> the decoded claims
     *
     * @throws InvalidTokenException on any signature/expiry/format failure
     */
    public function decode(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), $this->config['algo']));

            return (array) $decoded;
        } catch (ExpiredException $e) {
            throw new InvalidTokenException('Access token has expired.', 'TOKEN_EXPIRED', $e);
        } catch (SignatureInvalidException $e) {
            throw new InvalidTokenException('Access token signature is invalid.', 'TOKEN_INVALID', $e);
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Access token is malformed or invalid.', 'TOKEN_INVALID', $e);
        }
    }

    private function secret(): string
    {
        $secret = (string) ($this->config['secret'] ?? '');
        if ($secret === '') {
            // Never sign with an empty key — fail loud rather than issue forgeable tokens.
            throw new \RuntimeException('Identity JWT secret is not configured (set IDENTITY_JWT_SECRET or APP_KEY).');
        }

        return $secret;
    }
}
