<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Seeders\EmailEngineSeeder;

/**
 * Register the vendor.kyc_deadline email event on EXISTING databases.
 *
 * EmailEngineSeeder auto-runs only when the email tables are first created, so
 * a template/event added to it later never reaches an already-migrated
 * environment. The seeder itself is idempotent (updateOrCreate by slug/key),
 * so re-running the whole thing is the safe, boring way to deliver one row.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('email_templates') || !Schema::hasTable('email_events')) {
            return; // engine not installed — its own migration seeds on create
        }
        (new EmailEngineSeeder())->run();
    }

    public function down(): void
    {
        // Leave the rows; removing an email template out from under logs that
        // reference it is worse than keeping a harmless orphan.
    }
};
