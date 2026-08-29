<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Marvel\Otp\Gateways\WhatsappGateway;
use Tests\TestCase;

/**
 * Pins the notify (utility template) payload shape and the parameter
 * sanitization the gateway applies before hitting Meta — Meta rejects body
 * parameters containing newlines/tabs or 4+ consecutive spaces (#132000).
 */
class WhatsappNotifySendTest extends TestCase
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
                'otp_template' => 'plantathome_otp',
                'otp_lang' => 'en',
                'otp_has_button' => true,
                'notify_template' => 'plantathome_update',
                'notify_lang' => 'en',
                'otp_ttl_minutes' => 5,
                'otp_max_attempts' => 5,
            ],
        ]);
    }

    public function test_notify_sends_the_utility_template_with_one_body_parameter(): void
    {
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.N']]], 200)]);

        $result = (new WhatsappGateway())->sendSms('9876543210', 'Your order PAH-1 has been placed successfully.');

        $this->assertTrue($result->isValid());
        Http::assertSent(function ($request) {
            $body = $request->data();
            $params = $body['template']['components'][0]['parameters'] ?? [];
            return $body['messaging_product'] === 'whatsapp'
                && $body['template']['name'] === 'plantathome_update'
                && $body['template']['language']['code'] === 'en'
                && $body['template']['components'][0]['type'] === 'body'
                && count($params) === 1
                && $params[0]['text'] === 'Your order PAH-1 has been placed successfully.';
        });
    }

    public function test_newlines_tabs_and_space_runs_are_flattened_in_parameters(): void
    {
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.N']]], 200)]);

        (new WhatsappGateway())->sendSms('9876543210', "Line one\nLine two\t  spaced    out");

        Http::assertSent(function ($request) {
            $text = $request->data()['template']['components'][0]['parameters'][0]['text'];
            return $text === 'Line one Line two spaced out';
        });
    }

    public function test_empty_message_is_refused_before_reaching_meta(): void
    {
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.N']]], 200)]);

        $result = (new WhatsappGateway())->sendSms('9876543210', "  \n  ");

        $this->assertFalse($result->isValid());
        Http::assertNothingSent();
    }

    public function test_otp_button_component_carries_the_code_when_enabled(): void
    {
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.O']]], 200)]);

        (new WhatsappGateway())->startVerification('9876543210');

        Http::assertSent(function ($request) {
            $components = $request->data()['template']['components'];
            if (count($components) !== 2) {
                return false;
            }
            $button = $components[1];
            $code = $components[0]['parameters'][0]['text'];
            return $button['type'] === 'button'
                && $button['sub_type'] === 'url'
                && $button['index'] === '0'
                && $button['parameters'][0]['text'] === $code;
        });
    }

    public function test_meta_error_details_are_logged_and_surfaced(): void
    {
        Http::fake([self::GRAPH => Http::response([
            'error' => [
                'message' => 'Recipient phone number not in allowed list',
                'code' => 131030,
                'error_data' => ['details' => 'Add recipient to the allowed list in the app dashboard.'],
                'fbtrace_id' => 'AbCdEf',
            ],
        ], 400)]);
        Log::spy();

        $result = (new WhatsappGateway())->sendSms('9876543210', 'hello there friend');

        $this->assertFalse($result->isValid());
        $error = implode(' ', array_map('strval', $result->getErrors()));
        $this->assertStringContainsString('131030', $error);
        $this->assertStringContainsString('allowed list', $error);
        Log::shouldHaveReceived('warning')->withArgs(function ($event, $ctx = []) {
            return $event === 'whatsapp.send.failed'
                && ($ctx['error_code'] ?? null) === 131030
                && ($ctx['fbtrace_id'] ?? null) === 'AbCdEf';
        })->once();
    }
}
