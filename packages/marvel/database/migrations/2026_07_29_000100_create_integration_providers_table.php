<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized third-party provider configuration — the single source of truth for every external
 * service credential (delivery partners, payment gateways, comms, AI, maps, storage, analytics).
 *
 * Two columns, deliberately, rather than the one `configuration` JSON blob with "the secret fields
 * encrypted": a mixed blob means any key added later silently ships UNENCRYPTED, and nobody notices
 * until it is in a backup. Splitting them makes the encrypted boundary structural instead of a
 * convention someone has to remember.
 *
 *   configuration — non-secret only (base_url, model, timeout, budgets, feature flags)
 *   credentials   — cast `encrypted:array` on the model, $hidden, never serialized
 *
 * Naming `credentials` is also load-bearing: LogRequests::REDACT_SUBTREE already contains that key,
 * so request payloads carrying it are redacted with no change to the logger.
 *
 * UNIQUE is (provider_slug, environment), NOT provider_slug alone. Onboarding a partner means
 * holding their sandbox AND production credentials at the same time — Porter UAT alongside Porter
 * production — which a single row per slug forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_providers', function (Blueprint $table) {
            $table->id();

            $table->string('provider_slug', 64);
            $table->string('category', 32)->index();   // delivery|payment|communication|ai|maps|storage|analytics|notification|translation
            $table->string('display_name', 128);
            $table->string('environment', 16)->default('production'); // sandbox | production

            $table->boolean('enabled')->default(false);
            // Routing preference within a category. LOWER IS PREFERRED, matching Descriptor.Priority
            // in the Go shipping-service so the two never disagree about direction.
            $table->unsignedSmallInteger('priority')->default(100);

            $table->json('configuration')->nullable();  // NON-SECRET ONLY
            $table->text('credentials')->nullable();    // encrypted:array on the model

            // Bumped by the model on every credential write. The Go service rebuilds a cached
            // adapter only when this changes, so without the bump a rotated key would keep being
            // served from cache for a full TTL.
            $table->unsignedBigInteger('credentials_version')->default(0);

            // Health, written by Test Connection and the scheduled checker.
            $table->string('health_status', 32)->default('unknown'); // connected|auth_failed|token_expired|webhook_error|disabled|maintenance|unknown
            $table->timestamp('health_checked_at')->nullable();
            $table->json('health_detail')->nullable();  // latency, account name, api version — never secrets

            // Push-to-Go sync state. A failed sync never blocks the save (this table is canonical),
            // so the row records that it is out of step and a job retries.
            $table->string('sync_status', 16)->default('n/a'); // n/a|pending|synced|failed
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['provider_slug', 'environment'], 'integration_providers_slug_env_unique');
            $table->index(['category', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_providers');
    }
};
