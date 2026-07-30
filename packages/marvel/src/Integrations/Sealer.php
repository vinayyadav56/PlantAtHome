<?php

namespace Marvel\Integrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * AES-256-GCM sealing, matching internal/cryptox in the Go shipping-service byte for byte.
 *
 * The two implementations must agree exactly or credential sync silently fails at the far end, so
 * the wire format is spelled out here:
 *
 *   { "alg": "A256GCM", "n": base64(12-byte nonce), "ct": base64(ciphertext || 16-byte tag) }
 *
 * PHP's openssl_encrypt returns the tag SEPARATELY, whereas Go's cipher.AEAD.Seal APPENDS it to the
 * ciphertext. Concatenating on this side is what makes the two compatible — it is the single most
 * likely thing to get wrong, and it fails as an authentication error rather than anything readable.
 */
class Sealer
{
    private const ALG = 'A256GCM';
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /**
     * Parse a 32-byte key given as 64 hex characters or base64.
     *
     * A short key is REJECTED, never padded: silently accepting one would weaken every secret in
     * the system while appearing to work.
     */
    public static function parseKey(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException('Encryption key is not configured.');
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
            return (string) hex2bin($raw);
        }

        foreach ([true, false] as $strict) {
            $decoded = base64_decode(strtr($raw, '-_', '+/'), $strict);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        throw new InvalidArgumentException('Encryption key must be 32 bytes as hex (64 chars) or base64.');
    }

    /**
     * Seal an array as an envelope the Go service can open.
     *
     * @param  array<string,string>  $payload
     * @return array{alg:string,n:string,ct:string}
     */
    public static function seal(string $key, array $payload): array
    {
        $binKey = self::parseKey($key);
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($plain === false) {
            throw new RuntimeException('Could not encode the credential payload.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $binKey, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return [
            'alg' => self::ALG,
            'n'   => base64_encode($nonce),
            // Go expects tag-appended; PHP hands it back separately.
            'ct'  => base64_encode($cipher . $tag),
        ];
    }

    /**
     * Open an envelope produced by seal() (or by the Go service). Used by tests to prove the two
     * implementations agree.
     *
     * @param  array{alg?:string,n:string,ct:string}  $envelope
     * @return array<string,string>
     */
    public static function open(string $key, array $envelope): array
    {
        $binKey = self::parseKey($key);
        $alg = (string) ($envelope['alg'] ?? self::ALG);
        if (strcasecmp($alg, self::ALG) !== 0) {
            throw new InvalidArgumentException("Unsupported algorithm: {$alg}");
        }

        $nonce = base64_decode((string) ($envelope['n'] ?? ''), true);
        $blob = base64_decode((string) ($envelope['ct'] ?? ''), true);
        if ($nonce === false || $blob === false || strlen($nonce) !== self::NONCE_BYTES || strlen($blob) <= self::TAG_BYTES) {
            throw new InvalidArgumentException('Malformed sealed payload.');
        }

        $cipher = substr($blob, 0, -self::TAG_BYTES);
        $tag = substr($blob, -self::TAG_BYTES);

        $plain = openssl_decrypt($cipher, self::CIPHER, $binKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered payload).');
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : [];
    }
}
