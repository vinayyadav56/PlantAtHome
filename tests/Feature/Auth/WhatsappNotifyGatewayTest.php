<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Marvel\Otp\Gateways\Msg91Gateway;
use Marvel\Otp\Gateways\WhatsappGateway;
use Marvel\Traits\SmsTrait;
use Tests\TestCase;

/**
 * Order updates and login OTP are separate jobs. Before this, both read
 * `auth.active_otp_gateway`, so sending order updates on WhatsApp forced login codes onto
 * WhatsApp too (and vice versa) — the reason WhatsApp order updates could not be enabled
 * independently.
 */
final class WhatsappNotifyGatewayTest extends TestCase
{
    /** Expose the protected resolver. */
    private function resolver(): object
    {
        return new class {
            use SmsTrait;
            public function resolve()
            {
                return $this->getOtpGateway();
            }
        };
    }

    public function test_notify_gateway_is_independent_of_the_login_otp_gateway(): void
    {
        config(['auth.active_otp_gateway' => 'msg91', 'auth.notify_gateway' => 'whatsapp']);

        $gateway = $this->resolver()->resolve();

        $this->assertInstanceOf(
            WhatsappGateway::class,
            (fn () => $this->gateway)->call($gateway),
            'order updates must follow notify_gateway, not the login OTP gateway',
        );
    }

    public function test_it_falls_back_to_the_login_gateway_when_notify_is_unset(): void
    {
        config(['auth.active_otp_gateway' => 'msg91', 'auth.notify_gateway' => null]);

        $gateway = $this->resolver()->resolve();

        $this->assertInstanceOf(
            Msg91Gateway::class,
            (fn () => $this->gateway)->call($gateway),
            'unset notify_gateway must preserve the previous behaviour',
        );
    }

    public function test_a_misconfigured_gateway_never_breaks_order_processing(): void
    {
        config(['auth.notify_gateway' => 'not_a_real_provider']);

        $this->assertNull($this->resolver()->resolve(), 'an unknown provider resolves to null, not a fatal');
    }
}
