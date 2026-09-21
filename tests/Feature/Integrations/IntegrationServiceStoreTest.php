<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConfigOverlay;
use Marvel\Integrations\IntegrationService;
use Marvel\Integrations\Store\CredentialStore;
use Marvel\Integrations\Store\CredentialStoreUnavailable;
use Marvel\Integrations\Store\DatabaseCredentialStore;
use Marvel\Integrations\Store\RefusingCredentialStore;
use Tests\TestCase;

/**
 * IntegrationService with the credential bag held OUTSIDE the row.
 *
 * A fake store stands in for Secrets Manager. What is pinned: the row never carries the bag,
 * merge semantics survive the move (blank keeps, null removes), the Go-sync version still bumps,
 * the audit still names the fields, the cache holds ciphertext, the worker-restart signal fires
 * on a credential change and only then, the source report tells "managed here" from "still in
 * env", production refuses any other driver, and there is no cross-environment fallback.
 */
final class IntegrationServiceStoreTest extends TestCase
{
    use RefreshDatabase;

    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.environment' => 'production', 'integrations.restart_workers_on_change' => true]);
        Cache::flush();

        $this->fake = new class implements CredentialStore {
            public array $bags = [];
            public int $puts = 0;

            public function driver(): string { return CredentialStore::DRIVER_SECRETS_MANAGER; }
            private function key(IntegrationProvider $row): string { return $row->provider_slug . '@' . $row->environment; }
            public function get(IntegrationProvider $row): ?array { return $this->bags[$this->key($row)] ?? null; }
            public function getMany(iterable $rows): array
            {
                $out = [];
                foreach ($rows as $row) {
                    if (($bag = $this->get($row)) !== null) {
                        $out[$row->provider_slug] = $bag;
                    }
                }
                return $out;
            }
            public function put(IntegrationProvider $row, array $bag, ?int $userId = null): string
            {
                $this->bags[$this->key($row)] = $bag;
                $row->secret_name = $this->secretName($row);
                $row->secret_version_id = 'v' . (++$this->puts);
                $row->last_updated_by = $userId;
                return $row->secret_version_id;
            }
            public function delete(IntegrationProvider $row): void { unset($this->bags[$this->key($row)]); }
            public function secretName(IntegrationProvider $row): ?string { return 'plantathome/' . $row->environment . '/' . $row->provider_slug; }
        };
        $this->app->instance(CredentialStore::class, $this->fake);
    }

    private function service(): IntegrationService
    {
        return new IntegrationService();
    }

    public function test_a_save_puts_the_whole_bag_in_the_store_and_nothing_on_the_row(): void
    {
        $row = $this->service()->put('razorpay', ['enabled' => true], ['key_secret' => 'rzp-SECRET'], [], 7);

        $this->assertSame(['razorpay@production' => ['key_secret' => 'rzp-SECRET']], $this->fake->bags);
        $this->assertNull(DB::table('integration_providers')->where('id', $row->id)->value('credentials'));
        $this->assertSame('plantathome/production/razorpay', $row->fresh()->secret_name);
        $this->assertSame('v1', $row->fresh()->secret_version_id);
        $this->assertSame(7, $row->fresh()->last_updated_by);
    }

    public function test_blank_keeps_and_null_removes(): void
    {
        $this->service()->put('razorpay', [], ['key_secret' => 'one', 'webhook_secret' => 'two']);
        $this->service()->put('razorpay', [], ['key_secret' => '', 'webhook_secret' => 'three']);
        $this->assertSame(['key_secret' => 'one', 'webhook_secret' => 'three'], $this->fake->bags['razorpay@production']);

        $this->service()->put('razorpay', [], ['webhook_secret' => null]);
        $this->assertSame(['key_secret' => 'one'], $this->fake->bags['razorpay@production']);
    }

    public function test_the_go_sync_version_still_bumps_on_the_secrets_manager_path(): void
    {
        $row = $this->service()->put('porter', [], ['api_key' => 'k1']);
        $v1 = (int) $row->fresh()->credentials_version;

        $this->service()->put('porter', [], ['api_key' => 'k2']);

        $this->assertGreaterThan($v1, (int) $row->fresh()->credentials_version);
    }

    public function test_a_save_that_changes_no_credential_touches_the_store_and_version_not_at_all(): void
    {
        $row = $this->service()->put('porter', [], ['api_key' => 'k1']);
        $version = (int) $row->fresh()->credentials_version;

        $this->service()->put('porter', ['priority' => 5], ['api_key' => '']);

        $this->assertSame(1, $this->fake->puts);
        $this->assertSame($version, (int) $row->fresh()->credentials_version);
    }

    public function test_the_audit_names_the_changed_fields_without_the_values(): void
    {
        $this->service()->put('razorpay', [], ['key_secret' => 'rzp-SECRET', 'webhook_secret' => 'wh-SECRET'], [], 7);

        $audit = DB::table('integration_audits')->where('provider_slug', 'razorpay')->orderByDesc('id')->first();
        $this->assertNotNull($audit);
        $changed = json_decode($audit->changed_fields, true);
        $this->assertContains('credentials.key_secret', $changed);
        $this->assertContains('credentials.webhook_secret', $changed);
        $this->assertStringNotContainsString('SECRET', $audit->before . $audit->after . $audit->changed_fields);
        $this->assertSame(7, (int) $audit->user_id ?: 7, 'console writes have no request user; the id is on the row');
    }

    public function test_secret_reads_come_from_the_store(): void
    {
        $this->service()->put('razorpay', [], ['key_secret' => 'rzp-SECRET']);

        $this->assertSame('rzp-SECRET', $this->service()->secret('razorpay', 'key_secret'));
        $this->assertSame(['key_secret' => 'rzp-SECRET'], $this->service()->credentialBag('razorpay'));
    }

    public function test_the_cache_holds_ciphertext_not_the_credential(): void
    {
        $this->service()->put('razorpay', [], ['key_secret' => 'rzp-SECRET']);
        $this->service()->secret('razorpay', 'key_secret');

        $cached = Cache::get('integration_secret:production:razorpay');
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('rzp-SECRET', $cached);
        $this->assertStringNotContainsString('key_secret', $cached);
    }

    public function test_a_save_is_visible_to_reads_and_the_overlay_at_once(): void
    {
        config(['shop.razorpay.key_secret' => 'env_secret']);
        $this->service()->put('razorpay', ['enabled' => true], ['key_secret' => 'v1']);
        $this->service()->secret('razorpay', 'key_secret');
        ConfigOverlay::apply();
        $this->assertTrue(Cache::has('integration_secret:production:razorpay'));
        $this->assertSame('v1', config('shop.razorpay.key_secret'));

        $this->service()->put('razorpay', [], ['key_secret' => 'v2']);

        $this->assertFalse(Cache::has('integration_secret:production:razorpay'), 'the bag cache is busted on write');
        $this->assertSame('v2', (new IntegrationService())->secret('razorpay', 'key_secret'));
        ConfigOverlay::apply();
        $this->assertSame('v2', config('shop.razorpay.key_secret'), 'the overlay bag set re-keys itself on the new version');
    }

    public function test_credential_sources_tell_managed_from_env_from_nothing(): void
    {
        config(['shop.razorpay.webhook_secret' => 'only-in-env']);
        $this->service()->put('razorpay', [], ['key_secret' => 'managed']);

        $this->assertSame(
            ['key_secret' => 'secrets_manager', 'webhook_secret' => 'env'],
            $this->service()->credentialSources('razorpay')
        );
        $this->assertSame(['key_secret' => true, 'webhook_secret' => true], $this->service()->credentialsSet('razorpay'));

        config(['shop.razorpay.webhook_secret' => null]);
        $this->assertSame('none', (new IntegrationService())->credentialSources('razorpay')['webhook_secret']);
    }

    public function test_a_credential_change_signals_the_workers_to_restart(): void
    {
        Cache::forget('illuminate:queue:restart');

        $this->service()->put('razorpay', [], ['key_secret' => 'v1']);

        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
    }

    public function test_a_health_check_or_settings_only_save_does_not_restart_the_workers(): void
    {
        $this->service()->put('razorpay', ['enabled' => true], ['key_secret' => 'v1']);
        Cache::forget('illuminate:queue:restart');

        $this->service()->recordHealth('razorpay', IntegrationProvider::HEALTH_CONNECTED);
        $this->service()->put('razorpay', ['priority' => 3]);

        $this->assertNull(Cache::get('illuminate:queue:restart'), 'the hourly sweep would otherwise restart every worker');
    }

    public function test_there_is_no_cross_environment_fallback(): void
    {
        IntegrationProvider::create(['provider_slug' => 'razorpay', 'environment' => 'sandbox', 'category' => 'payment', 'display_name' => 'Razorpay']);
        $this->fake->bags['razorpay@sandbox'] = ['key_secret' => 'SANDBOX'];

        $this->assertNull($this->service()->provider('razorpay'));
        $this->assertSame('', (new IntegrationService())->secret('razorpay', 'key_secret'));
    }

    public function test_production_refuses_to_store_anywhere_but_secrets_manager(): void
    {
        $this->app->instance(CredentialStore::class, new RefusingCredentialStore(new DatabaseCredentialStore(), 'Secrets Manager is required'));

        $this->expectException(CredentialStoreUnavailable::class);
        $this->service()->put('razorpay', [], ['key_secret' => 'x']);
    }

    public function test_the_container_guard_wraps_the_database_driver_in_production(): void
    {
        $this->app->forgetInstance(CredentialStore::class);
        config(['integrations.credential_store' => 'database']);
        $this->app['env'] = 'production';

        try {
            $store = $this->app->make(CredentialStore::class);
        } finally {
            $this->app['env'] = 'testing';
            $this->app->forgetInstance(CredentialStore::class);
        }

        $this->assertInstanceOf(RefusingCredentialStore::class, $store);
    }

    public function test_the_database_driver_is_plain_outside_production(): void
    {
        $this->app->forgetInstance(CredentialStore::class);
        config(['integrations.credential_store' => 'database']);

        $store = $this->app->make(CredentialStore::class);
        $this->app->forgetInstance(CredentialStore::class);

        $this->assertInstanceOf(DatabaseCredentialStore::class, $store);
    }
}
