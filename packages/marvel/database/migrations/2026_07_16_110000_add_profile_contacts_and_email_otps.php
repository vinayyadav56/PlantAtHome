<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profile-based contact system: a user sets up to 2 phone numbers and 2 email
 * addresses once. The primary email stays on users.email (verified via
 * email_verified_at); a secondary email + its verification timestamp live on the
 * profile. email_otps holds hashed, expiring one-time codes for email
 * verification (mirrors the password_resets pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('user_profiles', 'contact_2')) {
                $table->string('contact_2')->nullable()->after('contact');
            }
            if (!Schema::hasColumn('user_profiles', 'email_2')) {
                $table->string('email_2')->nullable()->after('contact_2');
            }
            if (!Schema::hasColumn('user_profiles', 'email_2_verified_at')) {
                $table->timestamp('email_2_verified_at')->nullable()->after('email_2');
            }
        });

        if (!Schema::hasTable('email_otps')) {
            Schema::create('email_otps', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('code_hash');
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }

        // A customer may now order without a phone (contact comes from their
        // profile when set) — relax the historically NOT NULL column. Raw ALTER
        // avoids the doctrine/dbal dependency that ->change() needs on Laravel 10.
        if (Schema::hasColumn('orders', 'customer_contact')) {
            try {
                DB::statement('ALTER TABLE orders MODIFY customer_contact VARCHAR(255) NULL');
            } catch (\Throwable $e) {
                // non-MySQL or already nullable; the app-level fallback still applies.
            }
        }
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            foreach (['contact_2', 'email_2', 'email_2_verified_at'] as $c) {
                if (Schema::hasColumn('user_profiles', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::dropIfExists('email_otps');
    }
};
