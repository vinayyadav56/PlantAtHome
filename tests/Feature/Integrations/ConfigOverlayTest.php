<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConfigOverlay;
use Marvel\Integrations\IntegrationService;
use Marvel\Integrations\ProviderRegistry;
use Tests\TestCase;

/**
 * The overlay is what makes a saved credential real: Razorpay, MSG91, SendGrid, S3 and the AI
 * clients all read `config()`, so without it the admin page stores keys nothing ever reads.
 *
 * These cases pin the properties that make it safe to run on every boot — enabled-only,
 * environment-scoped, never blanking a working env var, and never throwing.
 */
final class ConfigOverlayTest extends TestCase
{
    use RefreshDatabase;

    private function environment(): string
    {
        return (new IntegrationService())->environment();
    }

    private function seedRazorpay(array $attributes = []): IntegrationProvider
    {
        return IntegrationProvider::create(array_merge([
            'provider_slug' => 'razorpay',
            'category'      => 'payment',
            'environment'   => $this->environment(),
            'enabled'       => true,
            'credentials'   => ['key_secret' => 'db_secret'],
            'configuration' => ['key_id' => 'rzp_live_db'],
        ], $attributes));
    }

    protected function setUp(): void
    {
        parent::setUp();
        IntegrationProvider::query()->delete();
        Cache::forget(ConfigOverlay::cacheKey($this->environment()));
        config(['shop.razorpay.key_secret' => 'env_secret', 'shop.razorpay.key_id' => 'rzp_test_env']);
    }

    public function test_an_enabled_row_overrides_the_deployed_env_value(): void
    {
        $this->seedRazorpay();

        ConfigOverlay::apply();

        // This is the whole feature: Payment/Razorpay.php reads exactly these two keys, so a key
        // rotated in the admin now reaches the gateway with no redeploy.
        $this->assertSame('db_secret', config('shop.razorpay.key_secret'));
        $this->assertSame('rzp_live_db', config('shop.razorpay.key_id'));
    }

    public function test_a_disabled_row_leaves_the_env_value_alone(): void
    {
        $this->seedRazorpay(['enabled' => false]);

        ConfigOverlay::apply();

        // Toggling a provider Off is the documented way back to the deployed value without
        // deleting the credential. A card showing "Off" must never still be injecting its key.
        $this->assertSame('env_secret', config('shop.razorpay.key_secret'));
    }

    public function test_a_row_for_another_environment_is_ignored(): void
    {
        $other = $this->environment() === 'production' ? 'sandbox' : 'production';
        $this->seedRazorpay(['environment' => $other, 'credentials' => ['key_secret' => 'other_env_secret']]);

        ConfigOverlay::apply();

        // Unlike IntegrationService::provider() there is deliberately no cross-environment
        // fallback here: this mutates config process-wide, so a sandbox key must never be able to
        // overwrite production's.
        $this->assertSame('env_secret', config('shop.razorpay.key_secret'));
    }

    public function test_a_blank_value_does_not_wipe_a_working_env_var(): void
    {
        $this->seedRazorpay(['credentials' => ['key_secret' => ''], 'configuration' => ['key_id' => '   ']]);

        ConfigOverlay::apply();

        $this->assertSame('env_secret', config('shop.razorpay.key_secret'));
        $this->assertSame('rzp_test_env', config('shop.razorpay.key_id'));
    }

    public function test_a_row_for_an_unknown_provider_is_skipped_without_throwing(): void
    {
        IntegrationProvider::create([
            'provider_slug' => 'provider_that_was_removed',
            'category'      => 'payment',
            'environment'   => $this->environment(),
            'enabled'       => true,
            'credentials'   => ['key_secret' => 'x'],
            'configuration' => [],
        ]);

        ConfigOverlay::apply();

        $this->assertSame('env_secret', config('shop.razorpay.key_secret'));
    }

    public function test_saving_through_the_service_busts_the_overlay_cache(): void
    {
        $this->seedRazorpay();
        ConfigOverlay::apply(); // populates the cached row set

        $service = new IntegrationService();
        $service->put('razorpay', [], ['key_secret' => 'rotated_secret']);

        ConfigOverlay::apply();

        // Without the forget() in IntegrationService, a rotation would take up to a full TTL to
        // reach the gateway — which presents as "the save did nothing".
        $this->assertSame('rotated_secret', config('shop.razorpay.key_secret'));
    }

    public function test_every_declared_config_key_resolves_to_a_real_config_path(): void
    {
        // A typo in a config_key is invisible at runtime: the overlay writes a key nothing reads
        // and the provider silently keeps using its env value. Every declared key must already
        // exist in config, which is what proves it is the same key the caller reads.
        foreach (ProviderRegistry::all() as $slug => $def) {
            // Checked as two separate bags: a field name can legitimately appear in both, and
            // merging would silently drop one of the keys this is here to check.
            foreach ([$def->credentialConfigKeys(), $def->configurationConfigKeys()] as $keys) {
                foreach ($keys as $field => $key) {
                    $this->assertTrue(
                        config()->has($key),
                        "{$slug}.{$field} declares config_key '{$key}', which no config file defines"
                    );
                }
            }
        }
    }
}
