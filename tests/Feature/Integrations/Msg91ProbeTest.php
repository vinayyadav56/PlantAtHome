<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConnectionTester;
use Tests\TestCase;

/**
 * MSG91 was the last provider with no read-only probe, because every other call it
 * offers sends an OTP -- and a settings-screen button must never message a customer.
 *
 * The balance API is the one read-only call, but it has two traps a probe has to
 * survive: a bad key comes back as HTTP 200, and the key travels in the query
 * string, so a transport failure quotes it straight into the log.
 */
final class Msg91ProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.msg91.auth_key' => 'LIVE-AUTHKEY-CANARY-4a1b2c']);
    }

    private function probe(): array
    {
        return (new ConnectionTester())->test('msg91');
    }

    public function test_a_balance_reads_as_connected_and_reports_the_credit_left(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response('4821', 200)]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_CONNECTED, $res['status']);
        $this->assertTrue($res['ok']);
        $this->assertSame(4821.0, $res['detail']['route_balance']);
    }

    public function test_the_probe_only_ever_reads(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response('12', 200)]);

        $this->probe();

        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('balance.php', $request->url());
            // /otp and /flow are the send endpoints -- a probe must never reach them.
            $this->assertStringNotContainsString('/otp', $request->url());

            return true;
        });
    }

    public function test_a_rejected_key_is_auth_failed_even_though_msg91_answers_200(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response('authkey is invalid', 200)]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_AUTH_FAILED, $res['status']);
        $this->assertFalse($res['ok']);
    }

    public function test_an_unrecognised_reply_is_never_echoed_back(): void
    {
        // MSG91 has historically echoed the request back on malformed calls, which would
        // put the auth key straight into the admin screen and integration_logs.
        Http::fake(['api.msg91.com/*' => Http::response('authkey=LIVE-AUTHKEY-CANARY-4a1b2c&type=4', 200)]);

        $res = $this->probe();

        $this->assertFalse($res['ok']);
        $this->assertStringNotContainsString('LIVE-AUTHKEY-CANARY-4a1b2c', json_encode($res));
    }

    public function test_an_outage_is_maintenance_not_a_bad_credential(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response('gateway down', 503)]);

        $res = $this->probe();

        $this->assertSame(IntegrationProvider::HEALTH_MAINTENANCE, $res['status']);
    }

    public function test_the_log_scrubber_strips_every_credential_a_probe_url_can_carry(): void
    {
        $m = (new \ReflectionClass(ConnectionTester::class))->getMethod('scrubSecrets');
        $m->setAccessible(true);

        $url = 'cURL error 28: connect timeout for '
            . 'https://api.msg91.com/api/balance.php?authkey=LIVE-AUTHKEY-CANARY-4a1b2c&type=4';
        $this->assertStringNotContainsString('LIVE-AUTHKEY-CANARY-4a1b2c', $m->invoke(null, $url));

        $maps = 'https://maps.googleapis.com/maps/api/geocode/json?address=Bengaluru&key=AIza-CANARY-9z8y';
        $scrubbed = $m->invoke(null, $maps);
        $this->assertStringNotContainsString('AIza-CANARY-9z8y', $scrubbed);
        // The part that makes the error actionable has to survive.
        $this->assertStringContainsString('maps.googleapis.com', $scrubbed);
    }
}
