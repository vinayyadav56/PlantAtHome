<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\IntegrationLog;
use Tests\TestCase;

/**
 * The audit trail records credential changes, and it is NOT encrypted. So the one property that
 * actually matters is that it never contains a credential — otherwise auditing an encrypted column
 * quietly publishes it in plaintext one table over.
 */
final class AuditAndLogTest extends TestCase
{
    use RefreshDatabase;

    private function provider(array $credentials = []): IntegrationProvider
    {
        return IntegrationProvider::create([
            'provider_slug' => 'test_provider',
            'category'      => 'ai',
            'environment'   => (string) (config('integrations.environment') ?: 'sandbox'),
            'enabled'       => true,
            'credentials'   => $credentials,
            'configuration' => ['service_url' => 'https://example.test'],
        ]);
    }

    public function test_an_audit_row_never_contains_the_credential(): void
    {
        DB::table('integration_audits')->delete();
        $secret = 'AUDIT-CANARY-9f8e7d6c';

        $provider = $this->provider(['api_key' => $secret]);
        $provider->credentials = ['api_key' => $secret . '-rotated'];
        $provider->save();

        $rows = DB::table('integration_audits')->where('provider_slug', 'test_provider')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count(), 'create and update should both be audited');

        foreach ($rows as $row) {
            $blob = json_encode($row);
            $this->assertStringNotContainsString($secret, $blob, 'the audit trail leaked a credential value');
            $this->assertStringNotContainsString('rotated', $blob, 'the audit trail leaked a rotated credential');
        }
    }

    public function test_a_credential_change_is_recorded_by_field_name(): void
    {
        DB::table('integration_audits')->delete();

        $provider = $this->provider(['api_key' => 'k1']);
        $provider->credentials = ['api_key' => 'k2'];
        $provider->save();

        $latest = DB::table('integration_audits')->orderByDesc('id')->first();
        $changed = json_decode((string) $latest->changed_fields, true);

        $this->assertContains('credentials.api_key', $changed, 'the changed field name should be recorded');
        $this->assertSame('********', json_decode((string) $latest->after, true)['credentials'] ?? null);
    }

    public function test_a_save_that_changes_nothing_is_not_audited(): void
    {
        $provider = $this->provider(['api_key' => 'k1']);
        DB::table('integration_audits')->delete();

        $provider->save(); // no changes

        $this->assertSame(0, DB::table('integration_audits')->count(), 'a no-op save should not create an audit row');
    }

    public function test_credential_version_bumps_so_the_go_service_reloads(): void
    {
        $provider = $this->provider(['api_key' => 'k1']);
        $before = $provider->credentials_version;

        $provider->credentials = ['api_key' => 'k2'];
        $provider->save();

        $this->assertGreaterThan(
            $before,
            $provider->fresh()->credentials_version,
            'without a version bump the shipping service keeps serving the old key until its cache expires'
        );
    }

    public function test_logs_prune_by_age(): void
    {
        DB::table('integration_logs')->delete();

        IntegrationLog::record('test_provider', IntegrationLog::ACTION_TEST, IntegrationLog::STATUS_OK, ['duration_ms' => 12]);
        DB::table('integration_logs')->insert([
            'provider_slug' => 'test_provider',
            'environment'   => 'sandbox',
            'action'        => IntegrationLog::ACTION_TEST,
            'status'        => IntegrationLog::STATUS_OK,
            'created_at'    => now()->subDays(120),
        ]);

        $this->assertSame(2, DB::table('integration_logs')->count());
        IntegrationLog::prune(90);
        $this->assertSame(1, DB::table('integration_logs')->count(), 'only the entry past the retention window should go');
    }
}
