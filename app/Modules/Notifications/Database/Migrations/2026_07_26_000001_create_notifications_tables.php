<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications (Section 3): per-event templates + a delivery log. Templates are
 * keyed by domain event name + channel; the log records what was rendered and
 * (would be) sent. Delivery is driven by outbox subscribers — never in the
 * request path. Prefixed notif_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notif_templates')) {
            Schema::create('notif_templates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('event_name')->index();
                $table->string('channel');                       // email | sms | whatsapp | push
                $table->string('subject')->nullable();
                $table->text('body');                            // {{var}} placeholders
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['event_name', 'channel']);
            });
        }

        if (! Schema::hasTable('notif_log')) {
            Schema::create('notif_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->uuid('event_id')->index();               // source domain event id
                $table->string('event_name');
                $table->string('channel');
                $table->string('recipient')->nullable();
                $table->string('subject')->nullable();
                $table->text('body');
                $table->string('status')->default('queued');     // queued | sent | failed
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notif_log');
        Schema::dropIfExists('notif_templates');
    }
};
