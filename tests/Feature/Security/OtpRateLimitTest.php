<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The OTP routes were `throttle:10,1` — a per-IP count and nothing else. An OTP is a handful
 * of digits, so an attacker rotating source addresses (trivial behind a proxy the app trusts;
 * see TrustProxies) had effectively unlimited guesses at one number's code, and could also
 * SMS-bomb a single number at will.
 *
 * The fix is the same two-axis shape the 'auth' limiter already uses, keyed on the phone
 * number. What these tests pin is precisely the property that was missing: the phone axis
 * must be INDEPENDENT of the client IP.
 */
final class OtpRateLimitTest extends TestCase
{
    private function requestFrom(string $phone, string $ip): Request
    {
        $request = Request::create('/api/verify-otp-code', 'POST', ['phone_number' => $phone]);
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    /** @return array<string, array{0: string}> */
    public static function otpRoutes(): array
    {
        return [
            'send'     => ['api/send-otp-code'],
            'verify'   => ['api/verify-otp-code'],
            'otplogin' => ['api/otp-login'],
        ];
    }

    /** @dataProvider otpRoutes */
    public function test_otp_routes_use_the_named_limiter_not_a_bare_ip_count(string $uri): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);

        $this->assertNotNull($route, "route {$uri} is missing");
        $this->assertContains(
            'throttle:otp',
            $route->gatherMiddleware(),
            "{$uri} is back on a bare per-IP throttle — rotating IPs would defeat it"
        );
    }

    /**
     * The regression that matters: same number, different source addresses, one counter.
     */
    public function test_the_phone_axis_is_independent_of_the_client_ip(): void
    {
        $a = RouteServiceProvider::otpLimits($this->requestFrom('9876543210', '1.1.1.1'));
        $b = RouteServiceProvider::otpLimits($this->requestFrom('9876543210', '203.0.113.9'));

        $this->assertSame(
            $a[0]->key,
            $b[0]->key,
            'the same phone from two IPs must share one counter, or IP rotation bypasses the limit'
        );
        $this->assertNotSame($a[1]->key, $b[1]->key, 'the IP axis should still distinguish sources');
    }

    public function test_reformatting_the_same_number_does_not_buy_a_fresh_counter(): void
    {
        $keys = collect([
            '9876543210',
            '+91 9876543210',
            '+91-98765 43210',
            '919876543210',
            '0091 9876543210',
        ])->map(fn ($p) => RouteServiceProvider::otpPhoneKey($this->requestFrom($p, '1.1.1.1')))->unique();

        $this->assertCount(1, $keys, 'every spelling of one number must normalise to a single key');
        $this->assertSame('9876543210', $keys->first());
    }

    public function test_two_different_numbers_do_not_share_a_counter(): void
    {
        $a = RouteServiceProvider::otpPhoneKey($this->requestFrom('9876543210', '1.1.1.1'));
        $b = RouteServiceProvider::otpPhoneKey($this->requestFrom('9000000001', '1.1.1.1'));

        $this->assertNotSame($a, $b);
    }

    public function test_a_missing_phone_does_not_collapse_every_caller_onto_one_key(): void
    {
        // 'none' is a real bucket, but it must not be reachable by supplying a number.
        $none = RouteServiceProvider::otpLimits($this->requestFrom('', '1.1.1.1'))[0]->key;
        $real = RouteServiceProvider::otpLimits($this->requestFrom('9876543210', '1.1.1.1'))[0]->key;

        $this->assertSame('otp-phone:none', $none);
        $this->assertNotSame($none, $real);
    }
}
