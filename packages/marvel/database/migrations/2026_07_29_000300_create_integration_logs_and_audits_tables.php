<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observability for the Integration module: what happened (logs) and who changed it (audits).
 *
 * Deliberately absent from BOTH tables: request and response bodies. A partner exchange carries
 * customer names, phone numbers and delivery addresses, and an auth header carries the credential
 * itself. Storing them here would quietly turn an operational log into the most sensitive table in
 * the database — and the Go service's exchange viewer already covers on-demand debugging with
 * masking applied. Status, timing and an error code answer "is it broken and since when", which is
 * what a log is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_slug', 64);
            $table->string('environment', 16)->default('production');
            $table->string('action', 32);                 // test_connection|credential_sync|health_check|webhook
            $table->string('status', 16);                 // ok|failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();     // vendor message only — never a payload
            $table->unsignedBigInteger('user_id')->nullable(); // null = system (scheduler)
            $table->timestamp('created_at')->nullable();

            $table->index(['provider_slug', 'created_at']);
            $table->index('created_at');                   // pruning
        });

        Schema::create('integration_audits', function (Blueprint $table) {
            $table->id();
            $table->string('provider_slug', 64);
            $table->string('environment', 16)->default('production');
            $table->string('action', 32);                 // created|updated|enabled|disabled|deleted
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();          // 45 = INET6_ADDRSTRLEN
            $table->string('user_agent', 255)->nullable();

            // Field NAMES only for secrets. The whole point of encrypting credentials is defeated
            // if an unencrypted audit row carries the before/after values beside them.
            $table->json('changed_fields')->nullable();
            $table->json('before')->nullable();            // non-secret values; secrets → "********"
            $table->json('after')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['provider_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('integration_audits');
    }
};
