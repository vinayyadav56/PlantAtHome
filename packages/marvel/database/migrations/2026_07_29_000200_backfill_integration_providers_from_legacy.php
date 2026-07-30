<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\IntegrationProvider;

/**
 * Copy the legacy per-feature config tables into `integration_providers`.
 *
 * The important part is not the copy, it is the ENCRYPTION: ai_chat_settings, plant_doctor_settings
 * and care_plan_settings each store `service_api_key` as a plaintext varchar. Writing them through
 * the model puts them into the `encrypted:array` credentials bag, so they end up encrypted at rest
 * for the first time.
 *
 * Rules this migration follows:
 *  - IDEMPOTENT: re-running never duplicates a row and never clobbers a credential that has already
 *    been set through the admin UI. It fills gaps; it does not overwrite.
 *  - NON-DESTRUCTIVE: the source tables are left completely untouched. Reads still come from them
 *    until the read-flip, so a rollback is a config change rather than a restore.
 *  - SURVIVES MISSING TABLES: an environment that never ran a given feature's migration just skips
 *    that provider instead of failing the whole deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Single-row feature tables → one provider each.
        $this->backfillSingleRow('ai_chat_settings', 'ai_chat', 'ai', ['service_api_key'], [
            'service_url', 'openai_model', 'monthly_budget_inr', 'max_prompts', 'daily_user_cap',
        ]);

        $this->backfillSingleRow('plant_doctor_settings', 'plant_doctor', 'ai', ['service_api_key'], [
            'service_url', 'openai_model', 'monthly_budget_inr', 'plant_id_enabled',
        ]);

        $this->backfillSingleRow('care_plan_settings', 'care_plan', 'ai', ['service_api_key'], [
            'service_url', 'model', 'monthly_budget_inr', 'auto_on_delivery',
        ]);

        $this->backfillTranslationProviders();
    }

    /**
     * Copy one single-row settings table into a provider row.
     *
     * @param  string[]  $secretColumns  columns that belong in the encrypted credentials bag
     * @param  string[]  $configColumns  columns that stay readable in `configuration`
     */
    private function backfillSingleRow(
        string $table,
        string $slug,
        string $category,
        array $secretColumns,
        array $configColumns
    ): void {
        if (!Schema::hasTable($table) || !Schema::hasTable('integration_providers')) {
            return;
        }

        $row = DB::table($table)->first();
        if (!$row) {
            return;
        }

        $credentials = [];
        foreach ($secretColumns as $col) {
            $value = trim((string) ($row->{$col} ?? ''));
            if ($value !== '') {
                $credentials[$col] = $value;
            }
        }

        $configuration = [];
        foreach ($configColumns as $col) {
            if (property_exists($row, $col) && $row->{$col} !== null) {
                $configuration[$col] = $row->{$col};
            }
        }

        $this->upsertProvider($slug, $category, (bool) ($row->enabled ?? false), $credentials, $configuration);
    }

    /** translation_provider_configs holds one row per provider, already encrypted. */
    private function backfillTranslationProviders(): void
    {
        if (!Schema::hasTable('translation_provider_configs') || !Schema::hasTable('integration_providers')) {
            return;
        }

        foreach (DB::table('translation_provider_configs')->get() as $row) {
            $provider = trim((string) ($row->provider ?? ''));
            if ($provider === '') {
                continue;
            }

            // `credentials` is encrypted:array on the model, so decrypt via the model rather than
            // reading the raw column. A row encrypted under a rotated APP_KEY is skipped, not fatal.
            try {
                $legacy = \Marvel\Database\Models\TranslationProviderConfig::find($row->id);
                $credentials = (array) ($legacy?->credentials ?? []);
                $settings    = (array) ($legacy?->settings ?? []);
            } catch (\Throwable) {
                continue;
            }

            $this->upsertProvider(
                'translation_' . $provider,
                'translation',
                (bool) ($row->enabled ?? false),
                $credentials,
                $settings + ['is_active' => (bool) ($row->is_active ?? false)]
            );
        }
    }

    /**
     * Create the provider row, or fill only the gaps on one that already exists.
     *
     * Credentials are merged rather than replaced: a key already entered through the admin UI is
     * newer than whatever the legacy table holds, and must win.
     */
    private function upsertProvider(
        string $slug,
        string $category,
        bool $enabled,
        array $credentials,
        array $configuration
    ): void {
        $environment = (string) (config('integrations.environment') ?: (app()->environment('production') ? 'production' : 'sandbox'));

        $existing = IntegrationProvider::query()
            ->where('provider_slug', $slug)
            ->where('environment', $environment)
            ->first();

        if ($existing) {
            // `+` keeps the LEFT operand on a key collision, so the existing row must be on the
            // left: anything already set through the admin is newer than the legacy table and wins.
            // The legacy values only fill keys that are absent. Getting this order backwards would
            // silently restore a stale credential over a rotated one.
            $existing->credentials   = (array) $existing->credentials + array_filter($credentials);
            $existing->configuration = (array) $existing->configuration + $configuration;
            $existing->save();
            return;
        }

        IntegrationProvider::create([
            'provider_slug' => $slug,
            'category'      => $category,
            'environment'   => $environment,
            'enabled'       => $enabled,
            'credentials'   => $credentials,
            'configuration' => $configuration,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('integration_providers')) {
            return;
        }

        // Only remove what this migration creates. The legacy tables were never modified, so
        // deleting these rows restores the previous state exactly.
        IntegrationProvider::query()
            ->whereIn('provider_slug', ['ai_chat', 'plant_doctor', 'care_plan'])
            ->orWhere('provider_slug', 'like', 'translation_%')
            ->delete();
    }
};
