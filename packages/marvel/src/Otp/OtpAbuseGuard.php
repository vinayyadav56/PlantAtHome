<?php

namespace Marvel\Otp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Layered OTP abuse protection, on top of the per-minute `throttle:otp` limiter
 * (RouteServiceProvider — 5/min per phone + 20/min per IP). The rate limiter blunts bursts;
 * this adds the three things a bare rate limiter cannot express:
 *
 *   1. COOLDOWN between sends per phone — an OTP has cost (SMS) and legitimate flows never
 *      need a new code every few seconds. Blocks rapid re-request even under the per-minute cap.
 *   2. DAILY CAP per phone — the real SMS-bombing defence: a hard ceiling on codes sent to one
 *      number in 24h, independent of how the requests are spread out or across IPs.
 *   3. FAILED-VERIFY LOCKOUT per phone — after N wrong codes, lock the number for a window so a
 *      short numeric OTP can't be brute-forced. (MSG91 verifies the code server-side; we count
 *      the failures it reports.)
 *
 * All counters live in the shared cache (Redis on prod), so they are atomic and hold across
 * every app instance — correct under horizontal scaling.
 *
 * FAIL-OPEN by design: if the cache backend errors, every check ALLOWS and logs. A Redis blip
 * must never lock every customer out of login; the per-minute `throttle:otp` limiter remains
 * as the floor. The trade is deliberate — availability of a login path over a brief, logged
 * weakening of the secondary guard.
 *
 * Never reveals whether a phone is registered — all limits key on the raw number and the
 * responses are generic.
 */
class OtpAbuseGuard
{
    /** Minimum gap between OTP sends to one number. */
    private const COOLDOWN_SECONDS = 45;

    /** Max OTP sends to one number per rolling 24h. */
    private const DAILY_SEND_CAP = 8;
    private const DAY_SECONDS = 86400;

    /** Wrong-code attempts before a number is locked, and for how long. */
    private const MAX_FAILED_VERIFY = 5;
    private const LOCKOUT_SECONDS = 900; // 15 min

    private function key(string $kind, string $phone): string
    {
        return 'otp:' . $kind . ':' . preg_replace('/\D+/', '', $phone);
    }

    /**
     * Gate an OTP SEND. Throws 429 if the number is cooling down or over its daily cap;
     * otherwise records the send (starts the cooldown, bumps the daily counter).
     */
    public function guardSend(?string $phone): void
    {
        $phone = (string) $phone;
        if ($phone === '') {
            return; // validation elsewhere; nothing to key on
        }
        try {
            // Cooldown: Cache::add is atomic set-if-absent — false means the key is still alive.
            if (!Cache::add($this->key('cooldown', $phone), 1, self::COOLDOWN_SECONDS)) {
                $this->tooMany('Please wait a few seconds before requesting another code.', self::COOLDOWN_SECONDS);
            }

            $dailyKey = $this->key('daily', $phone);
            Cache::add($dailyKey, 0, self::DAY_SECONDS); // init with a 24h TTL if absent
            $sent = (int) Cache::increment($dailyKey);
            if ($sent > self::DAILY_SEND_CAP) {
                $this->tooMany('Too many codes requested for this number today. Try again later.', self::DAY_SECONDS);
            }
        } catch (HttpException $e) {
            throw $e; // a real 429 — propagate
        } catch (\Throwable $e) {
            Log::warning('OTP send guard degraded (cache error) — allowing', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Gate an OTP VERIFY attempt. Throws 429 if the number is currently locked out from too
     * many wrong codes. Call BEFORE attempting verification.
     */
    public function guardVerify(?string $phone): void
    {
        $phone = (string) $phone;
        if ($phone === '') {
            return;
        }
        try {
            $fails = (int) (Cache::get($this->key('fails', $phone), 0));
            if ($fails >= self::MAX_FAILED_VERIFY) {
                $this->tooMany('Too many incorrect attempts. Please try again later.', self::LOCKOUT_SECONDS);
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('OTP verify guard degraded (cache error) — allowing', ['error' => $e->getMessage()]);
        }
    }

    /** Record a wrong code. After MAX_FAILED_VERIFY within the window the number is locked. */
    public function registerFailure(?string $phone): void
    {
        $phone = (string) $phone;
        if ($phone === '') {
            return;
        }
        try {
            $key = $this->key('fails', $phone);
            Cache::add($key, 0, self::LOCKOUT_SECONDS);
            Cache::increment($key);
        } catch (\Throwable $e) {
            Log::warning('OTP failure counter degraded (cache error)', ['error' => $e->getMessage()]);
        }
    }

    /** Clear the failure counter after a correct code. */
    public function registerSuccess(?string $phone): void
    {
        $phone = (string) $phone;
        if ($phone === '') {
            return;
        }
        try {
            Cache::forget($this->key('fails', $phone));
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    private function tooMany(string $message, int $retryAfter): void
    {
        throw new HttpException(429, $message, null, ['Retry-After' => (string) $retryAfter]);
    }
}
