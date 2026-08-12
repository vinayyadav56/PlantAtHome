<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Meta WhatsApp webhook: the subscription handshake and the signed event feed.
 *
 * Pins the two properties that matter operationally: an unverified caller can
 * neither subscribe nor inject events, and a legitimate event batch NEVER gets
 * a non-2xx (Meta retries the whole batch on any error response).
 */
final class WhatsappWebhookTest extends TestCase
{
    private const SECRET = 'test-app-secret';
    private const VERIFY = 'test-verify-token';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.webhook_verify_token' => self::VERIFY,
            'services.whatsapp.app_secret' => self::SECRET,
        ]);
    }

    private function sign(string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
    }

    public function test_verify_echoes_the_challenge_for_the_right_token(): void
    {
        $this->getJson('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=' . self::VERIFY . '&hub_challenge=1158201444')
            ->assertOk()
            ->assertSee('1158201444');
    }

    public function test_verify_rejects_a_wrong_token(): void
    {
        $this->getJson('/api/webhooks/whatsapp?hub_verify_token=nope&hub_challenge=123')
            ->assertStatus(401);
    }

    public function test_verify_fails_closed_when_no_token_is_configured(): void
    {
        config(['services.whatsapp.webhook_verify_token' => '']);

        $this->getJson('/api/webhooks/whatsapp?hub_verify_token=&hub_challenge=123')
            ->assertStatus(404);
    }

    public function test_events_require_a_valid_signature(): void
    {
        $payload = ['entry' => []];
        $body = json_encode($payload);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
        ], $body)->assertStatus(401);
    }

    public function test_signed_delivery_status_batch_is_accepted(): void
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [
                            ['id' => 'wamid.A', 'status' => 'sent', 'recipient_id' => '919876543210'],
                            ['id' => 'wamid.A', 'status' => 'delivered', 'recipient_id' => '919876543210'],
                            ['id' => 'wamid.B', 'status' => 'failed', 'recipient_id' => '919876543211',
                             'errors' => [['code' => 131047, 'title' => 'Re-engagement message']]],
                        ],
                    ],
                ]],
            ]],
        ]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ], $body)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_malformed_event_payload_still_returns_2xx(): void
    {
        // Meta retries the WHOLE batch on any non-2xx — a junk shape must not
        // turn one bad event into a retry storm.
        $body = json_encode(['entry' => 'not-an-array']);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($body),
        ], $body)->assertOk();
    }
}
