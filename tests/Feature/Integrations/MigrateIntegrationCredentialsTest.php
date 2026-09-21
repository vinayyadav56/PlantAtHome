<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\Store\CredentialStore;
use Marvel\Integrations\Store\DatabaseCredentialStore;
use Tests\TestCase;

/**
 * integrations:migrate-credentials — the cutover from the encrypted column to the store.
 *
 * Pinned: a dry run writes nothing; a copy is verified by reading it back before the row is
 * touched; a mismatch leaves the column intact and fails the command; purge is opt-in, nulls the
 * column WITHOUT bumping the Go-sync version, and leaves an audit row; re-running is a no-op;
 * relabelling refuses to collide with an existing row; a provider with no credential fields
 * gets no secret.
 */
final class MigrateIntegrationCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.environment' => 'production']);
        Cache::flush();

        $this->fake = new class implements CredentialStore {
            public array $bags = [];
            public bool $corruptReads = false;

            public function driver(): string { return CredentialStore::DRIVER_SECRETS_MANAGER; }
            private function key(IntegrationProvider $row): string { return $row->provider_slug . '@' . $row->environment; }
            public function get(IntegrationProvider $row): ?array
            {
                $bag = $this->bags[$this->key($row)] ?? null;
                return $bag !== null && $this->corruptReads ? ['garbled' => 'x'] : $bag;
            }
            public function getMany(iterable $rows): array { return []; }
            public function put(IntegrationProvider $row, array $bag, ?int $userId = null): string
            {
                $this->bags[$this->key($row)] = $bag;
                $row->secret_name = $this->secretName($row);
                $row->secret_version_id = 'v' . count($this->bags);
                return $row->secret_version_id;
            }
            public function delete(IntegrationProvider $row): void { unset($this->bags[$this->key($row)]); }
            public function secretName(IntegrationProvider $row): ?string { return 'plantathome/' . $row->environment . '/' . $row->provider_slug; }
        };
        $this->app->instance(CredentialStore::class, $this->fake);
    }

    private function seedRow(string $slug, array $bag, string $environment = 'production'): IntegrationProvider
    {
        return IntegrationProvider::create([
            'provider_slug' => $slug, 'category' => 'payment', 'display_name' => $slug,
            'environment' => $environment, 'enabled' => true, 'credentials' => $bag,
        ]);
    }

    private function column(int $id): ?string
    {
        return DB::table('integration_providers')->where('id', $id)->value('credentials');
    }

    public function test_a_dry_run_writes_nothing_anywhere(): void
    {
        $row = $this->seedRow('razorpay', ['key_secret' => 'rzp']);

        $this->artisan('integrations:migrate-credentials', ['--dry-run' => true, '--purge' => true])->assertSuccessful();

        $this->assertSame([], $this->fake->bags);
        $this->assertNotNull($this->column($row->id));
        $this->assertNull($row->fresh()->secret_name);
    }

    public function test_it_copies_verifies_and_keeps_the_column_by_default(): void
    {
        $row = $this->seedRow('razorpay', ['key_secret' => 'rzp', 'webhook_secret' => 'wh']);
        $version = (int) $row->credentials_version;

        $this->artisan('integrations:migrate-credentials')->assertSuccessful();

        $this->assertSame(['key_secret' => 'rzp', 'webhook_secret' => 'wh'], $this->fake->bags['razorpay@production']);
        $this->assertSame('plantathome/production/razorpay', $row->fresh()->secret_name);
        $this->assertNotNull($this->column($row->id), 'the column is the rollback until --purge');
        $this->assertSame($version, (int) $row->fresh()->credentials_version, 'content did not change, so the Go sync has nothing new');
    }

    public function test_purge_empties_the_column_and_leaves_an_audit_row(): void
    {
        $row = $this->seedRow('razorpay', ['key_secret' => 'rzp']);
        $version = (int) $row->credentials_version;

        $this->artisan('integrations:migrate-credentials', ['--purge' => true])->assertSuccessful();

        $this->assertNull($this->column($row->id));
        $this->assertSame($version, (int) $row->fresh()->credentials_version);
        $audit = DB::table('integration_audits')->where('provider_slug', 'razorpay')->where('action', 'migrated')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('rzp', $audit->before . $audit->after);
    }

    public function test_a_readback_mismatch_leaves_the_column_intact_and_fails(): void
    {
        $row = $this->seedRow('razorpay', ['key_secret' => 'rzp']);
        $this->fake->corruptReads = true;

        $this->artisan('integrations:migrate-credentials', ['--purge' => true])->assertFailed();

        $this->assertNotNull($this->column($row->id));
        $this->assertNull($row->fresh()->secret_name);
    }

    public function test_running_it_again_is_a_no_op(): void
    {
        $this->seedRow('razorpay', ['key_secret' => 'rzp']);
        $this->artisan('integrations:migrate-credentials', ['--purge' => true])->assertSuccessful();
        $writes = count($this->fake->bags);

        $this->artisan('integrations:migrate-credentials', ['--purge' => true])
            ->expectsOutputToContain('already migrated')
            ->assertSuccessful();

        $this->assertCount($writes, $this->fake->bags);
    }

    public function test_only_the_target_environment_is_touched(): void
    {
        $prod = $this->seedRow('razorpay', ['key_secret' => 'prod']);
        $sandbox = $this->seedRow('razorpay', ['key_secret' => 'sandbox'], 'sandbox');

        $this->artisan('integrations:migrate-credentials', ['--environment' => 'production', '--purge' => true])->assertSuccessful();

        $this->assertNull($this->column($prod->id));
        $this->assertNotNull($this->column($sandbox->id));
        $this->assertArrayNotHasKey('razorpay@sandbox', $this->fake->bags);
    }

    public function test_relabel_renames_rows_but_never_collides(): void
    {
        $this->seedRow('razorpay', ['key_secret' => 'a'], 'sandbox');
        $this->seedRow('msg91', ['auth_key' => 'b'], 'sandbox');
        $this->seedRow('msg91', ['auth_key' => 'c'], 'staging'); // already has a staging row

        $this->artisan('integrations:migrate-credentials', ['--environment' => 'staging', '--relabel' => 'sandbox:staging'])->assertSuccessful();

        $this->assertSame('staging', IntegrationProvider::where('provider_slug', 'razorpay')->value('environment'));
        $this->assertSame(1, IntegrationProvider::where('provider_slug', 'msg91')->where('environment', 'sandbox')->count(), 'the colliding row is left alone');
        $this->assertArrayHasKey('razorpay@staging', $this->fake->bags);
    }

    public function test_a_provider_with_no_credential_fields_gets_no_secret(): void
    {
        // aws_s3: identity is the IAM role now; a stored key is only something to drop.
        $row = $this->seedRow('aws_s3', ['secret_access_key' => 'AKIA-old']);

        $this->artisan('integrations:migrate-credentials', ['--purge' => true])->assertSuccessful();

        $this->assertArrayNotHasKey('aws_s3@production', $this->fake->bags);
        $this->assertNull($this->column($row->id));
    }

    public function test_it_refuses_when_the_store_is_the_database(): void
    {
        $this->app->instance(CredentialStore::class, new DatabaseCredentialStore());
        $this->seedRow('razorpay', ['key_secret' => 'rzp']);

        $this->artisan('integrations:migrate-credentials')->assertFailed();
    }
}
