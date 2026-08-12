<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Marvel\Otp\Gateways\WhatsappGateway;
use Marvel\Otp\OtpAbuseGuard;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * WhatsApp (Meta Cloud API) login OTP — the gateway contract and its security
 * properties. The Meta Graph API is faked; nothing here talks to the network.
 *
 * Pins: the code is never stored in plaintext, is single-use, cannot be
 * brute-forced, cannot outlive its window, a resend kills its predecessor, and
 * a send failure surfaces as an invalid Result rather than an exception.
 */
final class WhatsappOtpTest extends TestCase
{
    private const GRAPH = 'graph.facebook.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.whatsapp' => [
                'phone_number_id' => '1234567890',
                'access_token' => 'test-token',
                'api_version' => 'v21.0',
                'otp_template' => 'plantathome_login',
                'otp_lang' => 'en',
                'otp_has_button' => false,
                'notify_template' => 'plantathome_order_update',
                'notify_lang' => 'en',
                'otp_ttl_minutes' => 5,
                'otp_max_attempts' => 5,
            ],
        ]);
        Cache::flush();
    }

    /** Fake a successful Meta send and return the code it was asked to deliver. */
    private function sendAndCaptureCode(string $phone = '9876543210'): string
    {
        $captured = null;
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);

        $result = (new WhatsappGateway())->startVerification($phone);
        $this->assertTrue($result->isValid(), 'send must succeed against a healthy Meta');

        foreach (Http::recorded() as [$request, $response]) {
            $body = $request->data();
            $captured = $body['template']['components'][0]['parameters'][0]['text'] ?? null;
        }
        $this->assertNotNull($captured, 'the template must carry the code as its body parameter');

        return (string) $captured;
    }

    public function test_send_uses_the_authentication_template_and_bearer_token(): void
    {
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        (new WhatsappGateway())->startVerification('9876543210');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '/v21.0/1234567890/messages')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $body['type'] === 'template'
                && $body['template']['name'] === 'plantathome_login'
                && $body['to'] === '919876543210'; // bare 10 digits → India
        });
    }

    public function test_code_is_never_stored_in_plaintext(): void
    {
        $code = $this->sendAndCaptureCode();

        $entry = Cache::get('wa_otp:919876543210');
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('hash', $entry);
        $this->assertStringNotContainsString($code, json_encode($entry), 'the code must not be recoverable from the cache');
        $this->assertTrue(password_verify($code, $entry['hash']) || \Illuminate\Support\Facades\Hash::check($code, $entry['hash']));
    }

    public function test_correct_code_verifies_and_is_single_use(): void
    {
        $code = $this->sendAndCaptureCode();
        $gw = new WhatsappGateway();

        $this->assertTrue($gw->checkVerification(null, $code, '9876543210')->isValid());
        // Replay of the SAME code must fail — the entry is consumed.
        $this->assertFalse($gw->checkVerification(null, $code, '9876543210')->isValid());
    }

    public function test_wrong_code_fails_and_burns_the_entry_at_the_attempt_cap(): void
    {
        $code = $this->sendAndCaptureCode();
        $gw = new WhatsappGateway();
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($gw->checkVerification(null, $wrong, '9876543210')->isValid());
        }
        // Cap reached — even the CORRECT code is now dead.
        $this->assertNull(Cache::get('wa_otp:919876543210'));
        $this->assertFalse($gw->checkVerification(null, $code, '9876543210')->isValid());
    }

    public function test_wrong_code_does_not_extend_the_expiry_window(): void
    {
        $code = $this->sendAndCaptureCode();
        $before = Cache::get('wa_otp:919876543210')['expires_at'];

        (new WhatsappGateway())->checkVerification(null, '000000', '9876543210');

        $this->assertSame($before, Cache::get('wa_otp:919876543210')['expires_at']);
    }

    public function test_resend_invalidates_the_previous_code(): void
    {
        $first = $this->sendAndCaptureCode();
        $second = $this->sendAndCaptureCode();
        $this->assertNotSame($first, $second, 'each send must mint a fresh code');

        $gw = new WhatsappGateway();
        $this->assertFalse($gw->checkVerification(null, $first, '9876543210')->isValid());
        $this->assertTrue($gw->checkVerification(null, $second, '9876543210')->isValid());
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->sendAndCaptureCode();
        $this->travel(6)->minutes(); // TTL is 5

        $this->assertFalse((new WhatsappGateway())->checkVerification(null, $code, '9876543210')->isValid());
    }

    public function test_phone_formats_share_one_code(): void
    {
        $code = $this->sendAndCaptureCode('9876543210');

        // The same person typing +91… / 91… / spaced must hit the same entry.
        $this->assertTrue((new WhatsappGateway())->checkVerification(null, $code, '+91 98765 43210')->isValid());
    }

    public function test_meta_failure_returns_an_invalid_result_not_an_exception(): void
    {
        Http::fake([self::GRAPH => Http::response([
            'error' => ['message' => 'Template name does not exist'],
        ], 400)]);

        $result = (new WhatsappGateway())->startVerification('9876543210');

        $this->assertFalse($result->isValid());
        $this->assertNull(Cache::get('wa_otp:919876543210'), 'a failed send must not leave a usable code');
    }

    public function test_malformed_meta_response_is_treated_as_failure(): void
    {
        Http::fake([self::GRAPH => Http::response(['unexpected' => true], 200)]);

        $this->assertFalse((new WhatsappGateway())->startVerification('9876543210')->isValid());
    }

    public function test_unconfigured_gateway_refuses_without_calling_meta(): void
    {
        config(['services.whatsapp.access_token' => null]);
        Http::fake();

        $this->assertFalse((new WhatsappGateway())->startVerification('9876543210')->isValid());
        Http::assertNothingSent();
    }

    public function test_abuse_guard_counters_survive_reformatting_the_number(): void
    {
        $guard = new OtpAbuseGuard();
        $guard->guardSend('9876543210'); // starts the cooldown

        // The SAME number in E.164 must hit the same cooldown, not a fresh one.
        $this->expectException(HttpException::class);
        $guard->guardSend('+91 98765 43210');
    }
}
