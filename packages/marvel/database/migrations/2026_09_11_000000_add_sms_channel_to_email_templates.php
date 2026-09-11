<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Airtel DLT SMS templates ride the EXISTING notification-template engine
 * (email_templates + the admin Settings → Notifications page) instead of a
 * parallel system: rows gain a channel, and SMS rows carry the DLT contract —
 * a stable template_code, the Airtel-approved DLT Template ID (entered by the
 * admin AFTER approval; never generated), and the MSG91 Flow ID actually used
 * on the wire. The two IDs are deliberately separate fields.
 *
 * Existing email rows are untouched (channel defaults to 'email'); subject /
 * html_body stay NOT NULL — SMS rows store subject = name and html_body = ''.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }
        Schema::table('email_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('email_templates', 'channel')) {
                $t->string('channel', 12)->default('email'); // email | sms
            }
            if (! Schema::hasColumn('email_templates', 'template_code')) {
                $t->string('template_code', 96)->nullable()->unique();
            }
            if (! Schema::hasColumn('email_templates', 'dlt_template_id')) {
                $t->string('dlt_template_id', 64)->nullable();  // Airtel DLT id (post-approval)
            }
            if (! Schema::hasColumn('email_templates', 'provider_template_id')) {
                $t->string('provider_template_id', 64)->nullable(); // MSG91 Flow ID
            }
        });

        // Deploys run migrate --force only (never db:seed) — seed inline, the
        // same pattern the email engine's own seed migration uses.
        try {
            (new \Marvel\Database\Seeders\SmsTemplateSeeder())->run();
        } catch (\Throwable $e) {
            logger()->warning('sms template seed skipped: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }
        Schema::table('email_templates', function (Blueprint $t) {
            foreach (['channel', 'template_code', 'dlt_template_id', 'provider_template_id'] as $col) {
                if (Schema::hasColumn('email_templates', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
