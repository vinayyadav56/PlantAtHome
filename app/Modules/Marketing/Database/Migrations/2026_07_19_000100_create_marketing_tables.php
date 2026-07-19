<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Marketing Automation (App\Modules\Marketing). All tables prefixed
 * marketing_*. Every mutation is async (queues + scheduler) — the HTTP request
 * only ever writes campaign/audience/template definitions, never a send.
 *
 * Ownership rules (v2): bigIncrements internal id + uuid wire id; money/decimals
 * never double; JSON columns for structured payloads; intra-module FKs only
 * (cross-module refs — the acting admin — are stored as uuid with no FK).
 * Guarded with hasTable so re-runs on a partially-migrated env are safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Audience Builder ────────────────────────────────────────────────
        if (! Schema::hasTable('marketing_audiences')) {
            Schema::create('marketing_audiences', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('description')->nullable();
                $table->longText('sql_query');                          // SELECT-only, validated
                $table->string('status', 20)->default('draft')->index(); // draft | ready | archived
                $table->unsignedInteger('current_version')->default(0);  // highest saved version
                $table->unsignedBigInteger('last_result_count')->nullable();
                $table->unsignedInteger('last_execution_ms')->nullable();
                $table->json('columns')->nullable();                     // last returned column names
                $table->uuid('created_by_uuid')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('marketing_audience_versions')) {
            Schema::create('marketing_audience_versions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('audience_id');
                $table->unsignedInteger('version');                      // 1, 2, 3 …
                $table->longText('sql_query');
                $table->longText('snapshot')->nullable();                // frozen result rows (JSON)
                $table->json('sample')->nullable();                      // first N rows for preview
                $table->json('columns')->nullable();
                $table->unsignedBigInteger('result_count')->default(0);
                $table->unsignedInteger('execution_ms')->nullable();
                $table->boolean('truncated')->default(false);            // capped at max_rows
                $table->uuid('created_by_uuid')->nullable();
                $table->timestamps();

                $table->unique(['audience_id', 'version']);
                $table->index('audience_id');
                $table->foreign('audience_id')->references('id')->on('marketing_audiences')->cascadeOnDelete();
            });
        }

        // ── Template Management ─────────────────────────────────────────────
        if (! Schema::hasTable('marketing_templates')) {
            Schema::create('marketing_templates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('channel', 20)->index();                  // email | sms | whatsapp
                $table->string('description')->nullable();
                $table->string('status', 20)->default('draft')->index(); // draft | active
                $table->unsignedInteger('current_version')->default(0);
                $table->json('content');                                 // channel-shaped body (see VariableMapper)
                $table->json('variables')->nullable();                   // extracted {{var}} names
                $table->uuid('created_by_uuid')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('marketing_template_versions')) {
            Schema::create('marketing_template_versions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('template_id');
                $table->unsignedInteger('version');
                $table->string('channel', 20);
                $table->json('content');
                $table->json('variables')->nullable();
                $table->uuid('created_by_uuid')->nullable();
                $table->timestamps();

                $table->unique(['template_id', 'version']);
                $table->index('template_id');
                $table->foreign('template_id')->references('id')->on('marketing_templates')->cascadeOnDelete();
            });
        }

        // ── Campaigns ───────────────────────────────────────────────────────
        if (! Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('description')->nullable();
                $table->unsignedBigInteger('audience_id')->nullable();
                $table->unsignedBigInteger('audience_version_id')->nullable(); // pinned snapshot
                $table->json('channels');                                // ['email','sms','whatsapp']
                $table->string('status', 20)->default('draft');          // draft|scheduled|running|paused|completed|cancelled (indexed via composite below)
                $table->string('schedule_type', 20)->default('now');     // now|once|daily|weekly|monthly|cron
                $table->timestamp('scheduled_at')->nullable();
                $table->string('cron_expression')->nullable();
                $table->string('timezone', 64)->default('Asia/Kolkata');
                $table->unsignedInteger('batch_size')->default(1000);
                $table->unsignedInteger('throttle_per_minute')->nullable();
                $table->unsignedInteger('send_delay_seconds')->default(0);
                $table->integer('priority')->default(0);
                $table->unsignedInteger('max_retries')->default(3);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable()->index();   // scheduler polls this
                $table->uuid('created_by_uuid')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'next_run_at']);
                $table->foreign('audience_id')->references('id')->on('marketing_audiences')->nullOnDelete();
                $table->foreign('audience_version_id')->references('id')->on('marketing_audience_versions')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('marketing_campaign_templates')) {
            Schema::create('marketing_campaign_templates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('template_id');
                $table->string('channel', 20);
                $table->timestamps();

                $table->unique(['campaign_id', 'channel']);              // one template per channel
                $table->index('campaign_id');
                $table->foreign('campaign_id')->references('id')->on('marketing_campaigns')->cascadeOnDelete();
                $table->foreign('template_id')->references('id')->on('marketing_templates')->cascadeOnDelete();
            });
        }

        // ── Campaign Runs (one execution instance of a campaign) ─────────────
        if (! Schema::hasTable('marketing_campaign_runs')) {
            Schema::create('marketing_campaign_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('audience_version_id')->nullable();
                $table->string('status', 20)->default('pending')->index(); // pending|materializing|queued|processing|completed|failed|cancelled
                $table->string('trigger', 20)->default('manual');        // manual|scheduled|retry
                $table->unsignedBigInteger('total_recipients')->default(0);
                $table->unsignedBigInteger('total_messages')->default(0);
                $table->json('counts')->nullable();                      // {sent,failed,delivered,read,...}
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error')->nullable();
                $table->uuid('created_by_uuid')->nullable();
                $table->timestamps();

                $table->index(['campaign_id', 'status']);
                $table->foreign('campaign_id')->references('id')->on('marketing_campaigns')->cascadeOnDelete();
            });
        }

        // ── Campaign Jobs (a batch = one Send*Job unit of work) ──────────────
        if (! Schema::hasTable('marketing_campaign_jobs')) {
            Schema::create('marketing_campaign_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedInteger('batch_number');
                $table->string('channel', 20);
                $table->unsignedInteger('size')->default(0);
                $table->string('status', 20)->default('pending');        // pending|queued|processing|completed|failed
                $table->unsignedInteger('attempt')->default(0);
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                // (run_id, status) also serves lookups by run_id alone (leftmost prefix).
                $table->index(['run_id', 'status']);
                $table->foreign('run_id')->references('id')->on('marketing_campaign_runs')->cascadeOnDelete();
            });
        }

        // ── Notifications (materialized per-recipient-per-channel work item) ─
        if (! Schema::hasTable('marketing_notifications')) {
            Schema::create('marketing_notifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('channel', 20);
                $table->string('recipient');                             // email or phone
                $table->json('recipient_ref')->nullable();               // the frozen audience row
                $table->unsignedBigInteger('template_id')->nullable();
                $table->string('rendered_subject')->nullable();
                $table->longText('rendered_body')->nullable();
                $table->string('status', 20)->default('queued');         // queued|processing|sent|delivered|read|failed|retrying|cancelled
                $table->string('provider')->nullable();
                $table->string('provider_message_id')->nullable()->index();
                $table->json('provider_response')->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'status']);
                $table->index(['campaign_id', 'status']);
                $table->index(['batch_id', 'status']);
                $table->index('status');    // status-only filter (e.g. "all failed") — no composite covers it
                $table->index('sent_at');   // dashboard "sent today / 7d / 30d" windows
                $table->foreign('run_id')->references('id')->on('marketing_campaign_runs')->cascadeOnDelete();
            });
        }

        // ── Delivery Logs (append-only status/provider audit per notification)
        if (! Schema::hasTable('marketing_delivery_logs')) {
            Schema::create('marketing_delivery_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('notification_id');
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->string('channel', 20);
                $table->string('status', 20);
                $table->string('provider')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->json('provider_response')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index('notification_id');
                $table->index(['campaign_id', 'status']);
                $table->index(['campaign_id', 'occurred_at']); // analytics daily time-series
                $table->foreign('notification_id')->references('id')->on('marketing_notifications')->cascadeOnDelete();
            });
        }

        // ── Queue Logs (job lifecycle audit — dispatched/processing/failed) ──
        if (! Schema::hasTable('marketing_queue_logs')) {
            Schema::create('marketing_queue_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('run_id')->nullable();
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('job_class');
                $table->string('queue', 64)->nullable();
                $table->string('event', 20);                             // dispatched|processing|completed|failed|retrying
                $table->unsignedInteger('attempt')->default(1);
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index('run_id');
                $table->index('campaign_id');
            });
        }
    }

    public function down(): void
    {
        // Reverse dependency order (children first).
        Schema::dropIfExists('marketing_queue_logs');
        Schema::dropIfExists('marketing_delivery_logs');
        Schema::dropIfExists('marketing_notifications');
        Schema::dropIfExists('marketing_campaign_jobs');
        Schema::dropIfExists('marketing_campaign_runs');
        Schema::dropIfExists('marketing_campaign_templates');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_template_versions');
        Schema::dropIfExists('marketing_templates');
        Schema::dropIfExists('marketing_audience_versions');
        Schema::dropIfExists('marketing_audiences');
    }
};
