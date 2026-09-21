<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Marvel\Http\Middleware\LogRequests;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Request-log redaction covers the credential field names the integrations module uses.
 *
 * The list was exact-match only, so `aws_secret_access_key`, `auth_key` (MSG91), `app_secret`
 * (WhatsApp) and friends were logged in the clear. A suffix rule now catches the shape of a
 * secret whatever its prefix — and stays suffix-only so `secret_name`, the Secrets Manager
 * reference the integrations API returns, is still readable.
 */
final class LogRequestsRedactionTest extends TestCase
{
    private function redact(array $payload): array
    {
        $m = new ReflectionMethod(LogRequests::class, 'redact');
        $m->setAccessible(true);

        return $m->invoke(app(LogRequests::class), $payload);
    }

    public function test_integration_credential_names_are_redacted(): void
    {
        $out = $this->redact([
            'aws_secret_access_key' => 'AKIA-SECRET',
            'auth_key'              => 'msg91-SECRET',
            'app_secret'            => 'meta-SECRET',
            'webhook_verify_token'  => 'verify-SECRET',
            'server_key'            => 'maps-SECRET',
            'sync_key'              => 'sync-SECRET',
        ]);

        foreach ($out as $key => $value) {
            $this->assertSame('***redacted***', $value, "$key must be redacted");
        }
    }

    public function test_any_key_shaped_like_a_secret_is_redacted_whatever_its_prefix(): void
    {
        $out = $this->redact([
            'razorpay_key_secret' => 'a',
            'partner_api_token'   => 'b',
            'service_api_key'     => 'c',
            'nested'              => ['porter_api_key' => 'd'],
        ]);

        $this->assertSame('***redacted***', $out['razorpay_key_secret']);
        $this->assertSame('***redacted***', $out['partner_api_token']);
        $this->assertSame('***redacted***', $out['service_api_key']);
        $this->assertSame('***redacted***', $out['nested']['porter_api_key']);
    }

    public function test_references_and_public_identifiers_stay_readable(): void
    {
        $out = $this->redact([
            'secret_name'    => 'plantathome/production/razorpay',
            'key_id'         => 'rzp_live_public',
            'secret_version' => 'v-12',
            'bucket'         => 'plantathome-media-prod',
        ]);

        $this->assertSame('plantathome/production/razorpay', $out['secret_name']);
        $this->assertSame('rzp_live_public', $out['key_id']);
        $this->assertSame('v-12', $out['secret_version']);
        $this->assertSame('plantathome-media-prod', $out['bucket']);
    }

    public function test_the_whole_credentials_bag_is_still_one_redacted_value(): void
    {
        $out = $this->redact(['credentials' => ['anything' => 'x', 'else' => 'y']]);

        $this->assertSame('***redacted***', $out['credentials']);
    }
}
