<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConnectionTester;
use Tests\TestCase;

/**
 * MSG91 has no "who am I" endpoint and almost everything it offers SENDS something, which a
 * settings-screen button must never do. The v5 balance report is the read-only call that still
 * exercises the key.
 *
 * The first version of this probe used the legacy balance.php and was wrong in the way that
 * matters most for a health check: it could not go red. That endpoint answers a bogus key with
 * the body "0", is_numeric() accepted it, and an entirely invalid credential reported
 * "Connected". The tests below exist mostly to keep that from coming back.
 */
final class Msg91ProbeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'LIVE-AUTHKEY-CANARY-4a1b2c';
    private const ENDPOINT = 'control.msg91.com/api/v5/report/balance';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.msg91.auth_key' => self::KEY]);
    }

    private function probe(): array
    {
        return (new ConnectionTester())->test('msg91');
    }

    public function test_a_valid_key_with_credit_is_connected(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['balance' => 4821]], 200)]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_CONNECTED, $res['status']);
        $this->assertTrue($res['ok']);
        $this->assertSame(4821.0, $res['detail']['route_balance']);
    }

    public function test_a_rejected_key_is_auth_failed(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['status' => 'fail', 'errors' => 'Unauthorized', 'code' => '401'],
            401
        )]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_AUTH_FAILED, $res['status']);
        $this->assertFalse($res['ok']);
    }

    /**
     * The regression this file exists for. The legacy endpoint returned "0" for a bogus key, so a
     * numeric body could never be trusted as proof of authentication. Now a zero balance is only
     * ever reachable AFTER a 200, and it still must not read as a flat green.
     */
    public function test_a_valid_key_with_no_credit_does_not_report_a_flat_green(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['balance' => 0]], 200)]);

        $res = $this->probe();

        $this->assertFalse($res['ok'], 'zero balance means every send fails; it is not "connected"');
        $this->assertSame(IntegrationProvider::HEALTH_MAINTENANCE, $res['status']);
        $this->assertStringContainsString('0', $res['message']);
    }

    public function test_the_key_travels_in_a_header_and_never_in_the_url(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['balance' => 10], 200)]);

        $this->probe();

        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method(), 'a probe must never write');
            // The whole reason for moving off balance.php: a transport error quotes the URL, and
            // that message goes to the application log.
            $this->assertStringNotContainsString(self::KEY, $request->url(), 'the key must not be in the URL');
            $this->assertSame(self::KEY, $request->header('authkey')[0] ?? null);
            $this->assertStringNotContainsString('/otp', $request->url(), 'must not touch a send endpoint');

            return true;
        });
    }

    public function test_an_outage_is_maintenance_not_a_bad_credential(): void
    {
        Http::fake([self::ENDPOINT => Http::response('gateway down', 503)]);

        $this->assertSame(IntegrationProvider::HEALTH_MAINTENANCE, $this->probe()['status']);
    }

    public function test_an_unrecognised_reply_is_never_echoed_back(): void
    {
        // MSG91 has historically echoed the request back on malformed calls.
        Http::fake([self::ENDPOINT => Http::response('authkey=' . self::KEY, 418)]);

        $res = $this->probe();

        $this->assertFalse($res['ok']);
        $this->assertStringNotContainsString(self::KEY, json_encode($res));
    }

    public function test_an_undocumented_balance_shape_still_connects(): void
    {
        // The payload is undocumented and has changed shape before; a rename must not turn a
        // healthy integration red.
        Http::fake([self::ENDPOINT => Http::response(['ok' => true], 200)]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_CONNECTED, $res['status']);
        $this->assertArrayNotHasKey('route_balance', $res['detail']);
    }
}
