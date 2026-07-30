<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\IntegrationProvider;
use Tests\TestCase;

/**
 * The legacy backfill has one job that actually matters: three feature tables store their service
 * key as a PLAINTEXT varchar, and moving them into `integration_providers` is what finally encrypts
 * them. These tests pin that, plus the two properties that make the migration safe to run on a live
 * database — it is idempotent, and it never overwrites a newer credential.
 */
final class LegacyBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        (include __DIR__ . '/../../../packages/marvel/database/migrations/2026_07_29_000200_backfill_integration_providers_from_legacy.php')->up();
    }

    /**
     * Start from a known state.
     *
     * The backfill is itself a migration, so RefreshDatabase has ALREADY run it — and an existing
     * migration seeds ai_chat_settings. Every test therefore begins with rows this suite did not
     * create. Clearing both tables makes each case set up exactly the situation it describes.
     */
    private function reset(): void
    {
        IntegrationProvider::query()->delete();
        DB::table('ai_chat_settings')->delete();
    }

    public function test_a_plaintext_service_key_becomes_encrypted_at_rest(): void
    {
        $this->reset();

        DB::table('ai_chat_settings')->insert([
            'enabled'         => true,
            'service_url'     => 'https://chatbot.example',
            'service_api_key' => 'super-secret-key-123',
            'openai_model'    => 'gpt-4o-mini',
        ]);

        $this->runBackfill();

        $row = IntegrationProvider::where('provider_slug', 'ai_chat')->firstOrFail();

        // Decrypted through the cast, the value survives the move intact.
        $this->assertSame('super-secret-key-123', $row->credentials['service_api_key']);

        // The raw column must NOT contain the plaintext — that is the entire point of the exercise.
        $raw = (string) DB::table('integration_providers')->where('id', $row->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-key-123', $raw, 'credential was stored unencrypted');

        // Non-secret settings stay readable.
        $this->assertSame('https://chatbot.example', $row->configuration['service_url']);
        $this->assertTrue((bool) $row->enabled);

        // The source table is untouched, so a rollback needs no restore.
        $this->assertSame('super-secret-key-123', DB::table('ai_chat_settings')->value('service_api_key'));
    }

    public function test_running_it_twice_does_not_duplicate_the_provider(): void
    {
        $this->reset();
        DB::table('ai_chat_settings')->insert(['enabled' => true, 'service_api_key' => 'k1']);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, IntegrationProvider::where('provider_slug', 'ai_chat')->count());
    }

    public function test_it_never_overwrites_a_credential_already_set_in_the_admin(): void
    {
        $this->reset();
        DB::table('ai_chat_settings')->insert(['enabled' => true, 'service_api_key' => 'stale-legacy-key']);

        // Operator rotated the key through the Integrations UI before the migration ran.
        IntegrationProvider::create([
            'provider_slug' => 'ai_chat',
            'category'      => 'ai',
            'environment'   => (string) (config('integrations.environment') ?: 'sandbox'),
            'enabled'       => true,
            'credentials'   => ['service_api_key' => 'rotated-newer-key'],
            'configuration' => [],
        ]);

        $this->runBackfill();

        $row = IntegrationProvider::where('provider_slug', 'ai_chat')->firstOrFail();
        $this->assertSame(
            'rotated-newer-key',
            $row->credentials['service_api_key'],
            'the backfill clobbered a newer credential with a stale legacy value'
        );
    }

    // NOTE: there is deliberately no test for "legacy table absent".
    // Proving it would mean DROP TABLE, and DDL is not transactional in MySQL — the drop escapes
    // RefreshDatabase's rollback and breaks every later test in the suite. The guard it would cover
    // is a single `Schema::hasTable()` check, which is not worth poisoning the shared schema for.

    public function test_an_empty_legacy_table_creates_no_provider_row(): void
    {
        $this->reset();
        // No row in ai_chat_settings ⇒ nothing to migrate; a blank provider would show up in the
        // admin as a configured-looking integration that has never been set up.
        DB::table('ai_chat_settings')->delete();

        $this->runBackfill();

        $this->assertSame(0, IntegrationProvider::where('provider_slug', 'ai_chat')->count());
    }
}
