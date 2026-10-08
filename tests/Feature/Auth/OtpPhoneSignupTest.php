<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/**
 * No phone signup ever completed on production: a new number verified its code with MSG91
 * (which accepts a code once), was told "required info missing" with a 200 the storefront
 * did not recognise, and re-submitting the same code with name + email failed verification.
 */
final class OtpPhoneSignupTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+919876500001';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Permission::CUSTOMER, Permission::SUPER_ADMIN, Permission::STORE_OWNER] as $name) {
            SpatiePermission::findOrCreate($name, 'api');
        }
        SpatieRole::findOrCreate(\Marvel\Enums\Role::CUSTOMER, 'api');
        if (!Settings::first()) {
            Settings::create(['options' => ['currency' => 'INR'], 'language' => 'en']);
        }
        config(['services.msg91.auth_key' => 'test-key']);
        // MSG91 accepts a code exactly once.
        Http::fake(['*/otp/verify*' => Http::sequence()
            ->push(['type' => 'success'])
            ->whenEmpty(Http::response(['type' => 'error', 'message' => 'OTP already verified']))]);
    }

    private function login(array $extra = [], string $code = '123456')
    {
        return $this->postJson('/api/otp-login', array_merge([
            'phone_number' => self::PHONE,
            'otp_id'       => 'otp-abc',
            'code'         => $code,
            'channel'      => 'msg91',
        ], $extra));
    }

    public function test_a_new_number_verifies_once_then_signs_up_with_name_and_email(): void
    {
        $this->login()->assertStatus(422)->assertJsonStructure(['name', 'email']);

        $res = $this->login(['name' => 'New Customer', 'first_name' => 'New', 'email' => 'new@example.com']);

        $res->assertOk()->assertJsonStructure(['token']);
        Http::assertSentCount(1);
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame(self::PHONE, Profile::where('customer_id', $user->id)->value('contact'));
    }

    public function test_the_pass_needs_the_same_code_and_is_single_use(): void
    {
        $this->login()->assertStatus(422);

        $this->login(['name' => 'X', 'email' => 'x@example.com'], '654321')
            ->assertOk()->assertJsonPath('success', false);

        $this->login(['name' => 'New', 'email' => 'new@example.com'])->assertOk()->assertJsonStructure(['token']);
        $this->login(['name' => 'New', 'email' => 'new@example.com'])->assertOk()->assertJsonPath('success', false);
    }
}
