<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The centralized email engine: DB-managed templates (+ immutable version
 * snapshots), an event registry mapping trigger points to templates with
 * per-event queue/retry/kill-switch, and a per-message delivery log that the
 * SendGrid webhook advances. email_logs joins PruneLogTablesCommand (90d).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_templates')) {
            Schema::create('email_templates', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 96)->unique();
                $t->string('name', 191);
                $t->string('category', 32)->index();
                $t->string('subject', 500);
                $t->string('preview_text', 191)->nullable();
                $t->mediumText('html_body');
                $t->text('text_body')->nullable();
                $t->json('variables')->nullable();
                $t->string('status', 12)->default('active'); // active | draft
                $t->string('language', 8)->default('en');
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('email_template_versions')) {
            Schema::create('email_template_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('template_id')->index();
                $t->unsignedInteger('version');
                $t->string('subject', 500);
                $t->mediumText('html_body');
                $t->text('text_body')->nullable();
                $t->json('variables')->nullable();
                $t->unsignedBigInteger('saved_by')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('email_events')) {
            Schema::create('email_events', function (Blueprint $t) {
                $t->id();
                $t->string('event_key', 96)->unique();
                $t->string('name', 191);
                $t->string('module', 32)->index();
                $t->string('description', 500)->nullable();
                $t->string('trigger_point', 191)->nullable(); // display-only code ref
                $t->unsignedBigInteger('template_id')->nullable();
                $t->boolean('enabled')->default(true);
                $t->string('queue', 32)->default('default');
                $t->unsignedTinyInteger('tries')->default(5);
                // false = a roadmap event with no code path yet — listed honestly in admin.
                $t->boolean('wired')->default(true);
                $t->timestamps();
            });
        }

        $seedAfter = !Schema::hasTable('email_logs');
        if (!Schema::hasTable('email_logs')) {
            Schema::create('email_logs', function (Blueprint $t) {
                $t->id();
                $t->string('event_key', 96)->index();
                $t->string('template_slug', 96)->nullable();
                $t->string('recipient', 191)->index();
                $t->string('subject', 500)->nullable();
                // queued | sent | delivered | opened | clicked | bounced | failed | spam | skipped
                $t->string('status', 12)->default('queued');
                $t->string('provider', 24)->nullable();
                $t->string('provider_message_id', 191)->nullable()->index();
                $t->text('error')->nullable();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->json('meta')->nullable();
                $t->timestamps();
                $t->index(['status', 'created_at']);
                $t->index('created_at');
            });
        }

        // Deploys run `migrate --force` but never `db:seed` — so first creation
        // seeds the templates + event registry right here. The seeder is
        // idempotent (existing slugs/keys untouched), guarded so a seed hiccup
        // never fails the migration.
        if ($seedAfter) {
            try {
                (new \Marvel\Database\Seeders\EmailEngineSeeder())->run();
            } catch (\Throwable $e) {
                logger()->warning('EmailEngineSeeder during migration failed: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        // Templates/events are configuration — keep them. Only the log is disposable.
        Schema::dropIfExists('email_logs');
    }
};
