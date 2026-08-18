<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Http\Controllers\DeviceTokenController;
use App\Models\DeviceToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Device-token registration: who owns a physical device's push token, and when they stop owning it.
 *
 * The invariant under test is that ONE token is ONE device belonging to ONE user at a time. It is
 * enforced by the unique index on `token` plus a re-pointing upsert, NOT by a (user_id, token)
 * composite — a composite would leave the previous user's row alive on a handed-over phone and keep
 * delivering their notifications to whoever holds it now.
 */
final class DeviceTokenRegistrationTest extends TestCase
{
    private const TOKEN_A = 'ExponentPushToken[device-one]';
    private const TOKEN_B = 'ExponentPushToken[device-two]';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('device_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->index();
            $t->string('token')->unique();
            $t->string('platform')->nullable();
            $t->timestamps();
        });
    }

    /** A Request already authenticated as $userId — the routes sit behind auth:sanctum. */
    private function asUser(int $userId, array $body): Request
    {
        $request = new Request($body);
        $request->setUserResolver(fn () => (object) ['id' => $userId]);

        return $request;
    }

    private function controller(): DeviceTokenController
    {
        return new DeviceTokenController();
    }

    private function ownerOf(string $token): ?int
    {
        $row = DeviceToken::where('token', $token)->first();

        return $row ? (int) $row->user_id : null;
    }

    /* ── registration ────────────────────────────────────────────────────── */

    public function test_registers_a_token_to_the_authenticated_user(): void
    {
        $res = $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A, 'platform' => 'ios']));

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(1, $this->ownerOf(self::TOKEN_A));
        $this->assertSame('ios', DeviceToken::where('token', self::TOKEN_A)->first()->platform);
    }

    /** Scenario E — the app re-registers on every sign-in and cold start. */
    public function test_re_registering_the_same_token_creates_no_duplicate(): void
    {
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A, 'platform' => 'ios']));
        $res = $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A, 'platform' => 'ios']));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('unchanged', $res->getData()->status);
        $this->assertSame(1, DeviceToken::where('token', self::TOKEN_A)->count(), 'duplicate registration');
    }

    /* ── validation (Scenario D) ─────────────────────────────────────────── */

    public function test_rejects_a_token_that_is_not_an_expo_token(): void
    {
        foreach (['not-a-token', 'fcm:APA91bExample', 'ExponentPushToken[]', '', 'ExponentPushToken[a b]'] as $bad) {
            try {
                $this->controller()->store($this->asUser(1, ['token' => $bad]));
                $this->fail("accepted an invalid Expo token: {$bad}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('token', $e->errors());
            }
        }
        $this->assertSame(0, DeviceToken::count(), 'an invalid token must not create a row');
    }

    public function test_rejects_an_unknown_platform(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A, 'platform' => 'symbian']));
    }

    /* ── reassignment (Scenario B, first half) ───────────────────────────── */

    public function test_a_shared_device_moves_to_the_new_user_without_leaving_the_old_one(): void
    {
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A, 'platform' => 'android']));
        $res = $this->controller()->store($this->asUser(2, ['token' => self::TOKEN_A, 'platform' => 'android']));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('reassigned', $res->getData()->status);
        $this->assertSame(1, DeviceToken::where('token', self::TOKEN_A)->count(), 'the old row must not survive');
        $this->assertSame(2, $this->ownerOf(self::TOKEN_A));
        $this->assertSame(
            0,
            DeviceToken::where('user_id', 1)->count(),
            'user 1 must have no registration left on this device',
        );
    }

    /* ── release on sign-out (Scenario B + C) ────────────────────────────── */

    public function test_signing_out_releases_only_this_device(): void
    {
        // Scenario C: one user, two devices.
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A]));
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_B]));

        $res = $this->controller()->destroy($this->asUser(1, ['token' => self::TOKEN_A]));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, $res->getData()->released);
        $this->assertNull($this->ownerOf(self::TOKEN_A), 'the signed-out device must be released');
        $this->assertSame(1, $this->ownerOf(self::TOKEN_B), 'the user other device must keep receiving');
    }

    /** Scenario B end to end: A signs out, B signs in, A is not reachable here any more. */
    public function test_the_previous_user_cannot_be_reached_after_a_handover(): void
    {
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A]));
        $this->controller()->destroy($this->asUser(1, ['token' => self::TOKEN_A]));
        $this->controller()->store($this->asUser(2, ['token' => self::TOKEN_A]));

        $this->assertSame(2, $this->ownerOf(self::TOKEN_A));
        $this->assertSame(0, DeviceToken::where('user_id', 1)->count());
    }

    /** Releasing is idempotent — sign-out must never fail on a cleanup step. */
    public function test_releasing_a_token_twice_is_a_success(): void
    {
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A]));
        $this->controller()->destroy($this->asUser(1, ['token' => self::TOKEN_A]));
        $res = $this->controller()->destroy($this->asUser(1, ['token' => self::TOKEN_A]));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $res->getData()->released);
    }

    /** Ownership manipulation in reverse: releasing by token alone would be an IDOR. */
    public function test_a_user_cannot_release_someone_elses_device(): void
    {
        $this->controller()->store($this->asUser(1, ['token' => self::TOKEN_A]));

        $res = $this->controller()->destroy($this->asUser(2, ['token' => self::TOKEN_A]));

        $this->assertSame(0, $res->getData()->released);
        $this->assertSame(1, $this->ownerOf(self::TOKEN_A), 'user 2 detached user 1 device');
    }

    /* ── the routes themselves ───────────────────────────────────────────── */

    public function test_both_routes_require_auth_and_stay_throttled(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_ends_with($r->uri(), 'device-tokens'));

        $this->assertGreaterThanOrEqual(2, $routes->count(), 'expected a POST and a DELETE route');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $route->methods()[0] . ' must require auth');
            $this->assertContains('throttle:30,1', $middleware, $route->methods()[0] . ' must stay throttled');
        }
    }
}
