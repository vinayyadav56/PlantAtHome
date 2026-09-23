<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Otp\Gateways\Msg91Gateway;
use Tests\TestCase;

/**
 * Phone-OTP sign-in was dead on production and the log only said
 * "MSG91 is not configured (auth_key / template_id missing)".
 *
 * Two separate faults behind that one line:
 *   - the DLT template id had never been set (config), which is data, not code; and
 *   - the guard read the env value BEFORE consulting the DLT registry, so an admin registering
 *     an approved template could never switch OTP back on. That is the bug this pins.
 *
 * The message also named both fields when only one was missing, which is why the real cause took
 * so long to find: the auth key was present and correct the whole time.
 */
final class Msg91TemplateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.msg91.auth_key'    => 'AUTHKEY-PRESENT-AND-FINE',
            'services.msg91.template_id' => null,   // exactly production's state
            'services.msg91.sender'      => null,
        ]);
    }

    private function registerTemplate(string $providerId, string $status = 'active'): void
    {
        if (!Schema::hasTable('email_templates') || !Schema::hasColumn('email_templates', 'template_code')) {
            $this->markTestSkipped('email_templates/template_code not in this schema');
        }
        // updateOrInsert, not insert: the migrations already seed this template_code and the
        // column is unique, so every one of these tests has to write over the seeded row rather
        // than add a second one.
        DB::table('email_templates')->updateOrInsert(
            ['template_code' => 'PlantAtHome_Login_OTP'],
            [
                'channel'              => 'sms',
                'status'               => $status,
                'provider_template_id' => $providerId,
                'updated_at'           => now(),
            ]
        );
    }

    public function test_an_approved_template_in_the_registry_is_enough_to_send(): void
    {
        $this->registerTemplate('68c0registryTemplate');
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'request_id' => 'req-1'], 200)]);

        $result = (new Msg91Gateway())->startVerification('9876543210');

        // Before the fix this returned "not configured" without ever looking at the registry.
        // isValid(), not getErrors(): Result holds EITHER an id or an errors array, and
        // getErrors() returns null on the success branch.
        $this->assertTrue($result->isValid(), 'a registered template must be usable on its own');
        $this->assertSame('req-1', $result->getId());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/otp'));
    }

    public function test_a_draft_template_is_not_treated_as_configured(): void
    {
        // Production's actual state: the row exists but is a draft with no provider id.
        $this->registerTemplate('', 'draft');

        $result = (new Msg91Gateway())->startVerification('9876543210');

        $this->assertFalse($result->isValid());
    }

    public function test_the_error_names_only_the_piece_that_is_missing(): void
    {
        $this->registerTemplate('', 'draft');
        $result = (new Msg91Gateway())->startVerification('9876543210');
        $this->assertFalse($result->isValid());
        $error = $result->getErrors()[0] ?? '';

        $this->assertStringContainsString('DLT template id', $error);
        // The auth key is present, so blaming it is what sent someone hunting for a credential
        // problem that did not exist.
        $this->assertStringNotContainsString('auth key', $error);
    }

    public function test_nothing_is_sent_when_it_is_not_configured(): void
    {
        Http::fake();
        $this->registerTemplate('', 'draft');

        (new Msg91Gateway())->startVerification('9876543210');

        Http::assertNothingSent();
    }
}
