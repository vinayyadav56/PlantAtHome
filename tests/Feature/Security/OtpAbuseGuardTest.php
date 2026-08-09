<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Cache;
use Marvel\Otp\OtpAbuseGuard;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The layered OTP abuse guard that sits on top of the per-minute `throttle:otp` limiter:
 * a send cooldown, a per-number daily cap (SMS-bomb defence), and a failed-verify lockout
 * (brute-force defence). Counters live in the shared cache so they hold across instances.
 */
final class OtpAbuseGuardTest extends TestCase
{
    private OtpAbuseGuard $guard;
    private string $phone = '+91 90000 11122';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->guard = new OtpAbuseGuard();
    }

    private function statusOf(callable $fn): int
    {
        try {
            $fn();
            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_a_second_send_within_the_cooldown_is_429(): void
    {
        $this->assertSame(200, $this->statusOf(fn () => $this->guard->guardSend($this->phone)));
        $this->assertSame(429, $this->statusOf(fn () => $this->guard->guardSend($this->phone)), 'rapid re-request must cool down');
    }

    public function test_daily_cap_blocks_after_the_limit_regardless_of_spacing(): void
    {
        // Clear the cooldown between sends so ONLY the daily counter accumulates.
        $sent = 0;
        for ($i = 0; $i < 12; $i++) {
            $code = $this->statusOf(fn () => $this->guard->guardSend($this->phone));
            if ($code === 200) {
                $sent++;
            }
            // drop the cooldown key so the next send isn't blocked by it
            Cache::forget('otp:cooldown:' . preg_replace('/\D+/', '', $this->phone));
        }
        $this->assertSame(8, $sent, 'the daily cap is 8 sends per number');
    }

    public function test_number_is_locked_out_after_repeated_wrong_codes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->statusOf(fn () => $this->guard->guardVerify($this->phone)), "attempt {$i} should be allowed");
            $this->guard->registerFailure($this->phone);
        }
        // 6th verify is now locked.
        $this->assertSame(429, $this->statusOf(fn () => $this->guard->guardVerify($this->phone)), 'brute force must lock the number');
    }

    public function test_a_correct_code_clears_the_failure_counter(): void
    {
        $this->guard->registerFailure($this->phone);
        $this->guard->registerFailure($this->phone);
        $this->guard->registerSuccess($this->phone); // successful login resets

        // Fresh budget again — 5 more failures allowed before lockout.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->statusOf(fn () => $this->guard->guardVerify($this->phone)));
            $this->guard->registerFailure($this->phone);
        }
        $this->assertSame(429, $this->statusOf(fn () => $this->guard->guardVerify($this->phone)));
    }

    /** Different numbers have independent budgets. */
    public function test_limits_are_per_number(): void
    {
        $this->guard->guardSend($this->phone); // uses phone A's cooldown
        $this->assertSame(200, $this->statusOf(fn () => $this->guard->guardSend('+91 98888 22233')), 'a different number is unaffected');
    }

    /** Fail-OPEN: if the cache backend errors, checks allow rather than lock everyone out. */
    public function test_guard_fails_open_when_cache_throws(): void
    {
        // Point the cache at a store whose operations throw, simulating Redis down.
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('redis down'));

        $this->assertSame(200, $this->statusOf(fn () => $this->guard->guardSend($this->phone)), 'send must not be blocked by a cache outage');
        $this->assertSame(200, $this->statusOf(fn () => $this->guard->guardVerify($this->phone)), 'verify must not be blocked by a cache outage');
    }
}
