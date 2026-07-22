<?php

namespace Tests\Feature\MarketingAutomation;

/**
 * End-to-end campaign: audience → template → campaign → send. With the queue set
 * to sync and dispatch disabled, the whole async pipeline (materialize → batch →
 * Send*Job → delivery log) runs inline so we can assert the materialized result.
 */
class CampaignFlowTest extends MarketingAutomationTestCase
{
    /** @return array{audience:string, template:string} */
    private function seedAudienceAndTemplate(): array
    {
        $audience = $this->postJson('/api/v1/marketing/audiences', [
            'name'      => 'Delhi people',
            'sql_query' => "SELECT id, name, email, phone, city FROM demo_people WHERE city = 'Delhi'",
        ], $this->adminHeaders())->json('data.uuid');

        $template = $this->postJson('/api/v1/marketing/templates', [
            'name'    => 'Welcome email',
            'channel' => 'email',
            'content' => [
                'subject' => 'Hi {{name}}',
                'html'    => '<p>Hello {{name}} from {{city}} 🌱</p>',
            ],
        ], $this->adminHeaders())->json('data');

        $this->assertContains('name', $template['variables']);

        return ['audience' => $audience, 'template' => $template['uuid']];
    }

    public function test_send_materializes_notifications_and_completes_run(): void
    {
        ['audience' => $audience, 'template' => $template] = $this->seedAudienceAndTemplate();

        $campaign = $this->postJson('/api/v1/marketing/campaigns', [
            'name'          => 'Delhi welcome',
            'audience_uuid' => $audience,
            'channels'      => ['email'],
            'templates'     => [['channel' => 'email', 'template_uuid' => $template]],
            'schedule_type' => 'now',
        ], $this->adminHeaders());
        $campaign->assertStatus(201);
        $campaignUuid = $campaign->json('data.uuid');

        // Send (sync + dry-run → runs the whole pipeline inline).
        $this->postJson("/api/v1/marketing/campaigns/{$campaignUuid}/send", [], $this->adminHeaders())
            ->assertStatus(201);

        // Run finished; 3 Delhi recipients but only 2 have an email address.
        $runs = $this->getJson("/api/v1/marketing/campaigns/{$campaignUuid}/runs", $this->adminHeaders());
        $runs->assertStatus(200);
        $this->assertSame('completed', $runs->json('data.0.status'));
        $this->assertSame(3, $runs->json('data.0.total_recipients'));
        $this->assertSame(2, $runs->json('data.0.total_messages'));

        // Notifications materialized + marked sent (dry-run counts as sent).
        $notifs = $this->getJson("/api/v1/marketing/notifications?campaign={$campaignUuid}&status=sent", $this->adminHeaders());
        $notifs->assertStatus(200);
        $this->assertSame(2, $notifs->json('meta.pagination.total'));

        // Analytics reflect it.
        $analytics = $this->getJson("/api/v1/marketing/campaigns/{$campaignUuid}/analytics", $this->adminHeaders());
        $analytics->assertStatus(200);
        $this->assertSame(2, $analytics->json('data.totals.total'));
        $this->assertSame(2, $analytics->json('data.totals.sent'));
    }

    public function test_retry_with_nothing_to_retry_is_rejected(): void
    {
        ['audience' => $audience, 'template' => $template] = $this->seedAudienceAndTemplate();
        $campaignUuid = $this->postJson('/api/v1/marketing/campaigns', [
            'name'          => 'Retry test',
            'audience_uuid' => $audience,
            'channels'      => ['email'],
            'templates'     => [['channel' => 'email', 'template_uuid' => $template]],
            'schedule_type' => 'now',
        ], $this->adminHeaders())->json('data.uuid');

        $this->postJson("/api/v1/marketing/campaigns/{$campaignUuid}/send", [], $this->adminHeaders());

        $this->postJson("/api/v1/marketing/campaigns/{$campaignUuid}/retry", ['scope' => 'failed'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'RETRY_NOTHING_TO_RETRY');
    }

    public function test_retry_clones_failed_notifications_and_leaves_the_original_run_intact(): void
    {
        ['audience' => $audience, 'template' => $template] = $this->seedAudienceAndTemplate();
        $campaignUuid = $this->postJson('/api/v1/marketing/campaigns', [
            'name'          => 'Clone test',
            'audience_uuid' => $audience,
            'channels'      => ['email'],
            'templates'     => [['channel' => 'email', 'template_uuid' => $template]],
            'schedule_type' => 'now',
        ], $this->adminHeaders())->json('data.uuid');

        $campaign = \App\Modules\Marketing\Infrastructure\Models\Campaign::where('uuid', $campaignUuid)->first();

        // Simulate a prior run with one FAILED message.
        $oldRun = \App\Modules\Marketing\Infrastructure\Models\CampaignRun::create([
            'campaign_id' => $campaign->id, 'status' => 'completed', 'trigger' => 'manual',
            'total_recipients' => 1, 'total_messages' => 1, 'counts' => ['failed' => 1],
        ]);
        $failed = \App\Modules\Marketing\Infrastructure\Models\MarketingNotification::create([
            'run_id' => $oldRun->id, 'campaign_id' => $campaign->id, 'channel' => 'email',
            'recipient' => 'asha@example.com', 'rendered_body' => '<p>hi</p>', 'status' => 'failed',
            'template_id' => \App\Modules\Marketing\Infrastructure\Models\Template::where('uuid', $template)->value('id'),
        ]);

        app(\App\Modules\Marketing\Application\DeliveryService::class)
            ->retry($campaign, 'failed', [], null);

        // Original untouched; old run's tally unchanged.
        $failed->refresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame($oldRun->id, $failed->run_id);
        $this->assertSame(1, (int) $oldRun->fresh()->total_messages);

        // A brand-new run with a fresh cloned message (sent inline via dry-run).
        $newRun = \App\Modules\Marketing\Infrastructure\Models\CampaignRun::where('campaign_id', $campaign->id)
            ->where('trigger', 'retry')->first();
        $this->assertNotNull($newRun);
        $this->assertSame(2, \App\Modules\Marketing\Infrastructure\Models\MarketingNotification::where('campaign_id', $campaign->id)->count());
        $this->assertSame(1, \App\Modules\Marketing\Infrastructure\Models\MarketingNotification::where('run_id', $newRun->id)->count());
    }

    public function test_scheduled_campaign_gets_a_next_run_at(): void
    {
        ['audience' => $audience, 'template' => $template] = $this->seedAudienceAndTemplate();

        $res = $this->postJson('/api/v1/marketing/campaigns', [
            'name'          => 'Daily digest',
            'audience_uuid' => $audience,
            'channels'      => ['email'],
            'templates'     => [['channel' => 'email', 'template_uuid' => $template]],
            'schedule_type' => 'daily',
        ], $this->adminHeaders());

        $res->assertStatus(201);
        $this->assertSame('scheduled', $res->json('data.status'));
        $this->assertNotNull($res->json('data.next_run_at'));
    }
}
