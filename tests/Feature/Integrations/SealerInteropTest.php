<?php

namespace Tests\Feature\Integrations;

use Marvel\Integrations\Sealer;
use Tests\TestCase;

/**
 * The PHP↔Go crypto contract for credential sync.
 *
 * These two implementations must agree byte for byte or credential pushes fail at the far end as an
 * opaque "sealed payload could not be opened" — which looks like a wrong key and sends whoever is
 * debugging it in entirely the wrong direction.
 *
 * The trap this pins down: PHP's openssl_encrypt returns the GCM tag SEPARATELY, while Go's
 * cipher.AEAD.Seal APPENDS it to the ciphertext. Sealer concatenates to match Go. The fixture below
 * was produced by the Go implementation (internal/cryptox) and verified to open here, so a future
 * change to either side that breaks the format fails this test instead of failing in production.
 */
final class SealerInteropTest extends TestCase
{
    /** 32 bytes as 64 hex chars — the documented key format on both sides. */
    private const KEY = 'abababababababababababababababababababababababababababababababab';

    /** Produced by Go: cryptox.Seal(key, []byte(`{"api_key":"go-sealed-SECRET-789"}`)). */
    private const GO_ENVELOPE = [
        'alg' => 'A256GCM',
        'n'   => 'Y1SyaETqrShYr0DI',
        'ct'  => 'PpEbPiOysK+ut7sT6pDncbtPji2A1Mv5pEuFRRkuxA6YItR4pUuun+2KWtywHwlAy7c=',
    ];

    public function test_php_opens_an_envelope_sealed_by_go(): void
    {
        $out = Sealer::open(self::KEY, self::GO_ENVELOPE);

        $this->assertSame(['api_key' => 'go-sealed-SECRET-789'], $out);
    }

    public function test_round_trip_preserves_the_credential_bag(): void
    {
        $bag = ['api_key' => 'porter-live-abc', 'webhook_token' => 'wh-1', 'cod_supported' => 'false'];

        $env = Sealer::seal(self::KEY, $bag);

        $this->assertSame('A256GCM', $env['alg']);
        $this->assertSame($bag, Sealer::open(self::KEY, $env));
    }

    public function test_the_secret_is_not_recoverable_from_the_envelope(): void
    {
        $env = Sealer::seal(self::KEY, ['api_key' => 'SUPERSECRET']);

        $this->assertStringNotContainsString('SUPERSECRET', json_encode($env));
        $this->assertStringNotContainsString('SUPERSECRET', base64_decode($env['ct']));
    }

    public function test_each_seal_uses_a_fresh_nonce(): void
    {
        $a = Sealer::seal(self::KEY, ['k' => 'same']);
        $b = Sealer::seal(self::KEY, ['k' => 'same']);

        $this->assertNotSame($a['n'], $b['n'], 'nonce reuse is catastrophic for GCM');
        $this->assertNotSame($a['ct'], $b['ct']);
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        $env = Sealer::seal(self::KEY, ['api_key' => 'k']);
        $raw = base64_decode($env['ct']);
        $raw[0] = chr(ord($raw[0]) ^ 0xFF);
        $env['ct'] = base64_encode($raw);

        $this->expectExceptionMessageMatches('/Decryption failed/');
        Sealer::open(self::KEY, $env);
    }

    public function test_the_wrong_key_is_rejected(): void
    {
        $env = Sealer::seal(self::KEY, ['api_key' => 'k']);

        $this->expectExceptionMessageMatches('/Decryption failed/');
        Sealer::open(str_repeat('cd', 32), $env);
    }

    /**
     * A short key must be REJECTED, never padded. Silently accepting one would weaken every secret
     * in the system while looking like it worked.
     */
    public function test_a_short_key_is_rejected_not_padded(): void
    {
        $this->expectExceptionMessageMatches('/32 bytes/');
        Sealer::parseKey('too-short');
    }

    public function test_key_accepts_hex_and_base64(): void
    {
        $raw = random_bytes(32);

        $this->assertSame($raw, Sealer::parseKey(bin2hex($raw)));
        $this->assertSame($raw, Sealer::parseKey(base64_encode($raw)));
    }
}
