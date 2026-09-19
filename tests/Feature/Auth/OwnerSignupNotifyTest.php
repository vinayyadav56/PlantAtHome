<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\EventType;
use Marvel\Jobs\NotifyOwnerOfSignup;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

/**
 * Owner alerts: a real customer signup reaches the owner's WhatsApp, and
 * nothing else does.
 *
 * Every request is faked, so no test can send a live message. The four things
 * worth pinning are the ones that were actually easy to get wrong:
 *
 *  1. the configured number overrides the "every super-admin" fallback
 *  2. an admin/vendor row created by a seeder does NOT look like a signup
 *  3. the toggle is honoured
 *  4. a queue retry does not send the message twice
 */
final class OwnerSignupNotifyTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPH = 'graph.facebook.com/*';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            // Route notifications through WhatsApp regardless of the OTP gateway.
            'auth.notify_gateway' => 'whatsapp',
            'services.whatsapp' => [
                'phone_number_id' => '1234567890',
                'access_token' => 'test-token',
                'api_version' => 'v21.0',
                'otp_template' => 'plantathome_otp',
                'otp_lang' => 'en',
                'otp_has_button' => true,
                'notify_template' => 'plantathome_update',
                'notify_lang' => 'en',
                'otp_ttl_minutes' => 5,
                'otp_max_attempts' => 5,
            ],
        ]);
        Http::fake([self::GRAPH => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        // RefreshDatabase gives us an empty permissions table; the model's guard
        // is 'api', so the rows have to exist before givePermissionTo() works.
        foreach ([Permission::CUSTOMER, Permission::SUPER_ADMIN, Permission::STORE_OWNER] as $name) {
            SpatiePermission::findOrCreate($name, 'api');
        }
    }

    private function settings(array $options): void
    {
        Settings::query()->delete();
        Settings::create(['language' => DEFAULT_LANGUAGE, 'options' => $options]);
    }

    private function alertsOn(array $extra = []): void
    {
        $this->settings(array_merge([
            'smsEvent' => ['admin' => [EventType::CUSTOMER_REGISTERED => true]],
        ], $extra));
    }

    private function customer(string $name = 'Asha Rao', string $email = 'asha@example.com'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret-secret'),
        ]);
        $user->givePermissionTo(Permission::CUSTOMER);

        return $user->fresh();
    }

    /** The configured number wins outright over the super-admin fallback. */
    public function test_configured_owner_number_receives_the_signup_alert(): void
    {
        $this->alertsOn(['ownerNotify' => ['number' => '919996469046']]);
        $user = $this->customer();

        (new NotifyOwnerOfSignup($user->id))->handle();

        Http::assertSent(function ($request) {
            $body = $request->data();
            $text = $body['template']['components'][0]['parameters'][0]['text'] ?? '';

            return $body['to'] === '919996469046'
                && str_contains($text, 'Asha Rao')
                && str_contains($text, 'asha@example.com')
                // Meta rejects newlines in body parameters; the gateway flattens
                // them, so the owner alert must arrive as one clean line.
                && ! str_contains($text, "\n");
        });
    }

    /** With nothing configured, behaviour is exactly what it was before. */
    public function test_falls_back_to_super_admin_profile_contacts_when_unset(): void
    {
        $this->alertsOn();

        $admin = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('secret-secret'),
        ]);
        $admin->givePermissionTo(Permission::SUPER_ADMIN);
        $admin->profile()->create(['contact' => '9812345678']);

        $user = $this->customer('Bo Singh', 'bo@example.com');
        (new NotifyOwnerOfSignup($user->id))->handle();

        // Profile::saving canonicalises a bare 10-digit number to +91…, and the
        // gateway then strips the +.
        Http::assertSent(fn ($request) => $request->data()['to'] === '919812345678');
    }

    /** Seeders, admins and vendors create User rows too. They are not signups. */
    public function test_a_non_customer_user_produces_no_alert(): void
    {
        $this->alertsOn(['ownerNotify' => ['number' => '919996469046']]);

        $vendor = User::create([
            'name' => 'Vendor Co',
            'email' => 'vendor@example.com',
            'password' => bcrypt('secret-secret'),
        ]);
        $vendor->givePermissionTo(Permission::STORE_OWNER);

        (new NotifyOwnerOfSignup($vendor->id))->handle();

        Http::assertNothingSent();
    }

    /** The settings toggle actually gates it. */
    public function test_alert_is_silent_when_the_toggle_is_off(): void
    {
        $this->settings([
            'smsEvent' => ['admin' => [EventType::CUSTOMER_REGISTERED => false]],
            'ownerNotify' => ['number' => '919996469046'],
        ]);
        $user = $this->customer();

        (new NotifyOwnerOfSignup($user->id))->handle();

        Http::assertNothingSent();
    }

    /**
     * Nothing else on this path de-duplicates, and a failed job is retried —
     * which re-runs handle() and would re-send. One message per user.
     */
    public function test_a_retry_does_not_send_the_alert_twice(): void
    {
        $this->alertsOn(['ownerNotify' => ['number' => '919996469046']]);
        $user = $this->customer();

        (new NotifyOwnerOfSignup($user->id))->handle();
        (new NotifyOwnerOfSignup($user->id))->handle();

        Http::assertSentCount(1);
    }
}
