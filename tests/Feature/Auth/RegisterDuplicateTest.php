<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

/**
 * Duplicate detection at sign-up — enforced server-side, before the account exists.
 *
 * This is deliberately NOT a "does this email/phone exist?" lookup endpoint. That is the
 * account-enumeration hole API commit d5f355f closed (`is_contact_exist`). The only place a
 * duplicate may be revealed is as a validation error on an actual registration attempt,
 * which is rate-limited and costs the caller a full form submission.
 *
 * Two things were wrong before:
 *  - /register ignored `contact` entirely, the storefront's follow-up PUT /me/contacts had
 *    no uniqueness rule and swallowed its own errors, and the DB index is not unique — so
 *    two accounts could share a phone, and otpLogin then signed into whichever profile
 *    `first()` returned.
 *  - the custom duplicate-email message was keyed `email.unique:users`; Laravel looks up
 *    `email.unique`, so it never applied and customers saw the framework default.
 */
final class RegisterDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Permission::CUSTOMER, Permission::SUPER_ADMIN, Permission::STORE_OWNER] as $name) {
            SpatiePermission::findOrCreate($name, 'api');
        }
        if (!Settings::first()) {
            Settings::create(['options' => ['currency' => 'INR'], 'language' => 'en']);
        }
    }

    private function existingUser(string $email, string $contactClean): User
    {
        $u = User::create(['name' => 'Existing', 'email' => $email, 'password' => bcrypt('secret123')]);
        Profile::create(['customer_id' => $u->id, 'contact' => '+91' . $contactClean, 'contact_clean' => $contactClean]);
        return $u;
    }

    public function test_a_phone_already_on_another_account_is_rejected_before_the_user_is_created(): void
    {
        $this->existingUser('first@example.com', '9876543210');
        $before = User::count();

        // Same number, different formatting — the comparison is on the last 10 digits.
        $res = $this->postJson('/api/register', [
            'first_name' => 'Second',
            'email'      => 'second@example.com',
            'password'   => 'password123',
            'contact'    => '+91 98765 43210',
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('contact.0', 'This phone number is already linked to another account.');
        $this->assertSame($before, User::count(), 'no account may be created on a duplicate phone');
    }

    public function test_a_new_phone_registers_normally(): void
    {
        $this->existingUser('first@example.com', '9876543210');

        $res = $this->postJson('/api/register', [
            'first_name' => 'Third',
            'email'      => 'third@example.com',
            'password'   => 'password123',
            'contact'    => '+919123456789',
        ]);

        $this->assertNotSame(422, $res->getStatusCode(), 'an unused phone must not be rejected: ' . $res->getContent());
    }

    public function test_a_duplicate_email_gets_the_friendly_message_not_the_framework_default(): void
    {
        $this->existingUser('taken@example.com', '9000000001');

        $res = $this->postJson('/api/register', [
            'first_name' => 'Again',
            'email'      => 'taken@example.com',
            'password'   => 'password123',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('already registered', (string) $res->json('email.0'));
        $this->assertStringNotContainsString('has already been taken', (string) $res->json('email.0'));
    }

    /** The phone rule is a validation error, not a lookup: a blank phone is simply not checked. */
    public function test_an_omitted_phone_does_not_trip_the_rule(): void
    {
        $this->existingUser('first@example.com', '9876543210');

        $res = $this->postJson('/api/register', [
            'first_name' => 'NoPhone',
            'email'      => 'nophone@example.com',
            'password'   => 'password123',
        ]);

        $this->assertNotSame(422, $res->getStatusCode(), $res->getContent());
    }
}
