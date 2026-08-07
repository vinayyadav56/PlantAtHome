<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /api/webhooks/sendgrid had no authentication at all. The handler advances email_logs
 * by id, and its terminal states (bounced/failed/spam) deliberately override any existing
 * status — so an anonymous caller who guessed an id could mark real, delivered mail as
 * bounced, corrupting both the log and the deliverability picture built on top of it.
 *
 * Rollout is tolerant by design (no token configured → accept + warn) so that switching this
 * on cannot break an already-pointed integration. These tests pin BOTH halves: tolerant when
 * unset, and genuinely closed once the secret exists.
 */
final class SendgridWebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    private function seedLog(): int
    {
        return (int) DB::table('email_logs')->insertGetId([
            'status'     => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bouncePayload(int $id): array
    {
        return [['email_log_id' => $id, 'event' => 'bounce', 'reason' => 'forged']];
    }

    public function test_a_configured_token_is_required(): void
    {
        config(['services.sendgrid.webhook_token' => 's3cret-token']);
        $id = $this->seedLog();

        $this->postJson('/api/webhooks/sendgrid', $this->bouncePayload($id))
            ->assertStatus(401);

        $this->assertSame(
            'delivered',
            DB::table('email_logs')->where('id', $id)->value('status'),
            'an unauthenticated event must not be able to mark delivered mail as bounced'
        );
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        config(['services.sendgrid.webhook_token' => 's3cret-token']);
        $id = $this->seedLog();

        $this->postJson('/api/webhooks/sendgrid', $this->bouncePayload($id), ['X-Webhook-Token' => 'wrong'])
            ->assertStatus(401);

        $this->assertSame('delivered', DB::table('email_logs')->where('id', $id)->value('status'));
    }

    public function test_the_right_token_is_accepted_and_still_advances_the_log(): void
    {
        config(['services.sendgrid.webhook_token' => 's3cret-token']);
        $id = $this->seedLog();

        $this->postJson('/api/webhooks/sendgrid', $this->bouncePayload($id), ['X-Webhook-Token' => 's3cret-token'])
            ->assertOk();

        $this->assertSame(
            'bounced',
            DB::table('email_logs')->where('id', $id)->value('status'),
            'a verified bounce must still be recorded — the fix must not break delivery tracking'
        );
    }

    public function test_the_token_may_ride_in_the_query_string(): void
    {
        // SendGrid calls a URL we choose, so the secret can live in it.
        config(['services.sendgrid.webhook_token' => 's3cret-token']);
        $id = $this->seedLog();

        $this->postJson('/api/webhooks/sendgrid?token=s3cret-token', $this->bouncePayload($id))
            ->assertOk();

        $this->assertSame('bounced', DB::table('email_logs')->where('id', $id)->value('status'));
    }

    public function test_an_unset_token_stays_tolerant_so_rollout_cannot_break_tracking(): void
    {
        config(['services.sendgrid.webhook_token' => '']);
        $id = $this->seedLog();

        $this->postJson('/api/webhooks/sendgrid', $this->bouncePayload($id))->assertOk();

        $this->assertSame('bounced', DB::table('email_logs')->where('id', $id)->value('status'));
    }

    /** Whatever else happens, this route must never 5xx — SendGrid retries on non-2xx. */
    public function test_a_rejected_event_returns_401_not_500(): void
    {
        config(['services.sendgrid.webhook_token' => 's3cret-token']);

        $this->postJson('/api/webhooks/sendgrid', [['garbage' => true]])
            ->assertStatus(401);
    }
}
