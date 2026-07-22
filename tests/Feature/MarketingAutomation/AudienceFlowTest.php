<?php

namespace Tests\Feature\MarketingAutomation;

/**
 * Audience Builder: SELECT-only preview, save-as-snapshot, and refresh
 * versioning through the /api/v1/marketing/audiences endpoints.
 */
class AudienceFlowTest extends MarketingAutomationTestCase
{
    public function test_preview_runs_a_select_and_returns_rows_count_columns(): void
    {
        $res = $this->postJson('/api/v1/marketing/audiences/preview', [
            'sql_query' => "SELECT id, name, email, phone, city FROM demo_people WHERE city = 'Delhi'",
        ], $this->adminHeaders());

        $res->assertStatus(200);
        $this->assertSame(3, $res->json('data.total_count'));
        $this->assertContains('email', $res->json('data.columns'));
        $this->assertGreaterThanOrEqual(3, count($res->json('data.rows')));
    }

    public function test_preview_rejects_non_select_sql(): void
    {
        $res = $this->postJson('/api/v1/marketing/audiences/preview', [
            'sql_query' => 'DELETE FROM demo_people',
        ], $this->adminHeaders());

        $res->assertStatus(422);
        $this->assertStringStartsWith('AUDIENCE_SQL_', $res->json('errors.0.code'));
    }

    public function test_requires_marketing_permission(): void
    {
        $customer = $this->bearer($this->accessToken('customer@plantathome.test'));
        $this->postJson('/api/v1/marketing/audiences/preview', [
            'sql_query' => 'SELECT id FROM demo_people',
        ], $customer)->assertStatus(403);
    }

    public function test_save_freezes_v1_and_refresh_freezes_v2(): void
    {
        $create = $this->postJson('/api/v1/marketing/audiences', [
            'name'      => 'Delhi newsletter',
            'sql_query' => "SELECT id, name, email, phone, city FROM demo_people WHERE city = 'Delhi'",
        ], $this->adminHeaders());

        $create->assertStatus(201);
        $uuid = $create->json('data.uuid');
        $this->assertSame(1, $create->json('data.current_version'));
        $this->assertSame(3, $create->json('data.last_result_count'));
        $this->assertCount(1, $create->json('data.versions'));

        // A new match should appear in V2 but the pinned V1 keeps 3.
        \Illuminate\Support\Facades\DB::table('demo_people')->insert([
            'name' => 'Deepak', 'email' => 'deepak@example.com', 'phone' => '9000000005', 'city' => 'Delhi', 'newsletter' => true,
        ]);

        $refresh = $this->postJson("/api/v1/marketing/audiences/{$uuid}/refresh", [], $this->adminHeaders());
        $refresh->assertStatus(200);
        $this->assertSame(2, $refresh->json('data.current_version'));
        $this->assertSame(4, $refresh->json('data.last_result_count'));
        $this->assertCount(2, $refresh->json('data.versions'));
    }
}
