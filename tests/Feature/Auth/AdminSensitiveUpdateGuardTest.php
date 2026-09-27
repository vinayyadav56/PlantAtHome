<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\EmailOtp;
use Marvel\Database\Models\Profile;
use Marvel\Http\Controllers\UserController;
use Marvel\Http\Requests\UserUpdateRequest;
use Tests\TestCase;

/**
 * Admin edits of a user's LOGIN IDENTIFIERS (email, phone) must prove
 * ownership of the new value: email_code for a new email, SMS OTP for a new
 * phone. Unchanged fields need nothing. Guard: UserController::
 * assertSensitiveChangesVerified (private — exercised via reflection, the
 * same way the courier suite drives controller internals).
 */
final class AdminSensitiveUpdateGuardTest extends TestCase
{
    use RefreshDatabase;

    private function guard(array $body, object $target): void
    {
        $request = UserUpdateRequest::create('/users/9', 'PUT', $body);
        $controller = app(UserController::class);
        $method = new \ReflectionMethod($controller, 'assertSensitiveChangesVerified');
        $method->setAccessible(true);
        $method->invoke($controller, $request, $target);
    }

    private function target(string $email = 'old@plantathome.in', ?string $contactClean = null): object
    {
        return new class($email, $contactClean) {
            public $id = 9;
            public $email;
            public $profile;

            public function __construct($email, $contactClean)
            {
                $this->email = $email;
                $this->profile = $contactClean
                    ? (object) ['contact' => $contactClean, 'contact_clean' => $contactClean]
                    : null;
            }
        };
    }

    private function expect422(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail('Expected 422 HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertArrayHasKey($field, $e->getResponse()->getData(true));
        }
    }

    public function test_unchanged_email_and_absent_contact_need_no_proof(): void
    {
        $this->guard(['email' => 'OLD@plantathome.in'], $this->target());
        $this->assertTrue(true); // no exception
    }

    public function test_changed_email_without_code_is_refused(): void
    {
        $this->expect422(
            fn () => $this->guard(['email' => 'new@plantathome.in'], $this->target()),
            'email',
        );
    }

    public function test_changed_email_with_wrong_code_is_refused(): void
    {
        EmailOtp::create([
            'email' => 'new@plantathome.in',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->expect422(
            fn () => $this->guard(
                ['email' => 'new@plantathome.in', 'email_code' => '999999'],
                $this->target(),
            ),
            'email',
        );
    }

    public function test_changed_email_with_valid_code_passes_and_burns_the_code(): void
    {
        EmailOtp::create([
            'email' => 'new@plantathome.in',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->guard(
            ['email' => 'new@plantathome.in', 'email_code' => '123456'],
            $this->target(),
        );
        $this->assertSame(0, EmailOtp::where('email', 'new@plantathome.in')->count(), 'code is single-use');
    }

    public function test_changed_contact_without_otp_is_refused(): void
    {
        $this->expect422(
            fn () => $this->guard(
                ['profile' => ['contact' => '+919876543210']],
                $this->target(),
            ),
            'profile.contact',
        );
    }

    public function test_unchanged_contact_needs_no_otp(): void
    {
        $this->guard(
            ['profile' => ['contact' => '+919876543210']],
            $this->target('old@plantathome.in', '9876543210'),
        );
        $this->assertTrue(true);
    }

    public function test_contact_taken_by_another_account_is_refused(): void
    {
        $other = \Marvel\Database\Models\User::create([
            'name' => 'Other Person',
            'email' => 'other@plantathome.in',
            'password' => Hash::make('secret-secret'),
        ]);
        Profile::create(['customer_id' => $other->id, 'contact' => '+919876543210']);
        $this->expect422(
            fn () => $this->guard(
                ['profile' => ['contact' => '+919876543210']],
                $this->target(),
            ),
            'profile.contact',
        );
    }
}
