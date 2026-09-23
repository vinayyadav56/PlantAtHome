<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Marvel\Database\Models\User;
use Marvel\Http\Controllers\IntegrationController;
use Tests\TestCase;

/**
 * "Show credentials" is the one endpoint in this module that returns a secret, so it carries the
 * only tests in the suite whose job is to keep a door SHUT rather than open.
 *
 * Every other integrations test asserts that a value never leaves the server. This one asserts
 * that when a value does leave, it left through all four locks: the separate permission, the
 * caller's own password, the rate limit, and the break-glass flag.
 */
final class IntegrationRevealTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';
    private const MAPS_KEY = 'maps-key-REVEALED-ONLY-ON-PURPOSE';

    private IntegrationController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.environment'        => 'production',
            'integrations.cache_ttl'          => 0,
            'integrations.allow_reveal'       => true,
            'integrations.reveal_max_attempts' => 5,
            'location.google_maps_key'        => self::MAPS_KEY,
        ]);
        $this->controller = new IntegrationController();
        RateLimiter::clear('integration-reveal:1');
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Owner',
            'email'    => 'owner@reveal.test',
            'password' => bcrypt(self::PASSWORD),
        ]);
    }

    private function doReveal(User $user, string $password, string $slug = 'google_maps')
    {
        $req = Request::create("/integrations/{$slug}/reveal", 'POST', ['password' => $password]);
        $req->setUserResolver(fn () => $user);

        return $this->controller->reveal($req, $slug);
    }

    public function test_the_right_password_returns_the_value(): void
    {
        $res = $this->doReveal($this->admin(), self::PASSWORD);
        $body = json_decode($res->getContent(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(self::MAPS_KEY, $body['fields']['server_key']['value'] ?? null);
        $this->assertSame('production', $body['environment']);
    }

    public function test_a_wrong_password_returns_no_value(): void
    {
        $res = $this->doReveal($this->admin(), 'not-the-password');

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringNotContainsString(self::MAPS_KEY, $res->getContent());
    }

    public function test_the_break_glass_flag_beats_everything(): void
    {
        config(['integrations.allow_reveal' => false]);
        $res = $this->doReveal($this->admin(), self::PASSWORD);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringNotContainsString(self::MAPS_KEY, $res->getContent());
    }

    public function test_an_account_with_no_usable_password_cannot_reveal(): void
    {
        // A social-login admin has no password hash to check against. It must fail closed, and
        // must not say WHY -- "this account has no password" is a useful hint to an attacker.
        $user = User::create(['name' => 'Social', 'email' => 'social@reveal.test']);
        $res = $this->doReveal($user, 'any-password-at-all');

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringNotContainsString(self::MAPS_KEY, $res->getContent());
    }

    public function test_repeated_wrong_passwords_lock_the_endpoint(): void
    {
        $user = $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(403, $this->doReveal($user, 'wrong')->getStatusCode());
        }

        // The 6th is refused before the password is even checked -- so a correct one now fails too.
        $res = $this->doReveal($user, self::PASSWORD);
        $this->assertSame(429, $res->getStatusCode());
        $this->assertStringNotContainsString(self::MAPS_KEY, $res->getContent());
    }

    public function test_a_successful_reveal_clears_the_failure_budget(): void
    {
        $user = $this->admin();
        $this->doReveal($user, 'wrong');
        $this->doReveal($user, 'wrong');
        $this->assertSame(200, $this->doReveal($user, self::PASSWORD)->getStatusCode());

        // Budget reset, so four more failures still do not lock it.
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(403, $this->doReveal($user, 'wrong')->getStatusCode());
        }
        $this->assertSame(200, $this->doReveal($user, self::PASSWORD)->getStatusCode());
    }

    public function test_the_audit_records_the_field_names_and_never_the_value(): void
    {
        DB::table('integration_audits')->delete();
        $user = $this->admin();
        $this->doReveal($user, self::PASSWORD);

        $row = DB::table('integration_audits')->where('action', 'revealed')->first();
        $this->assertNotNull($row, 'a reveal must be audited');
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertStringContainsString('server_key', (string) $row->changed_fields);

        $blob = json_encode($row);
        $this->assertStringNotContainsString(self::MAPS_KEY, $blob, 'the audit must never hold the value');
    }

    public function test_a_refused_attempt_is_audited_too(): void
    {
        DB::table('integration_audits')->delete();
        $this->doReveal($this->admin(), 'wrong');

        $this->assertSame(
            1,
            DB::table('integration_audits')->where('action', 'reveal_refused')->count(),
            'a failed reveal is exactly the row an auditor wants'
        );
    }

    public function test_the_response_forbids_caching(): void
    {
        $res = $this->doReveal($this->admin(), self::PASSWORD);

        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_the_route_needs_its_own_permission_which_edit_does_not_imply(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/integrations/{slug}/reveal' && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route, 'the reveal route must be registered');
        $mw = $route->gatherMiddleware();
        $this->assertContains('auth:sanctum', $mw);
        $this->assertContains('permission:settings.integrations.reveal', $mw);
        $this->assertNotContains('permission:settings.integrations.edit', $mw, 'edit must not grant reveal');
        $this->assertContains('throttle:10,1', $mw);
    }

    public function test_the_request_logger_skips_this_path_entirely(): void
    {
        $ref = new \ReflectionClass(\Marvel\Http\Middleware\LogRequests::class);
        $skip = $ref->getConstant('SKIP_CONTAINS');

        $this->assertContains('/reveal', $skip, 'a reveal response must never reach request_logs');
    }
}
