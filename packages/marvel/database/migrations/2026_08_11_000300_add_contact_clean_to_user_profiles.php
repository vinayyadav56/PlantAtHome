<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * user_profiles.contact holds phones in whatever format the client sent
 * ("9876543210", "+919876543210", "919876543210" all coexist) and OTP login
 * matched on the RAW string — so the same person typing a different format
 * became a new account. contact_clean = last-10-digits (the same
 * normalization UniquePhone uses) gives otpLogin a deterministic key.
 *
 * Plain index, NOT unique: legacy duplicates may exist; uniqueness is
 * enforced on writes by validation, not by the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('contact_clean', 10)->nullable()->after('contact');
            $table->index('contact_clean');
        });

        DB::table('user_profiles')
            ->select(['id', 'contact'])
            ->whereNotNull('contact')
            ->where('contact', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($profiles) {
                foreach ($profiles as $profile) {
                    $digits = preg_replace('/\D+/', '', (string) $profile->contact);
                    if (strlen($digits) < 10) {
                        continue;
                    }
                    DB::table('user_profiles')->where('id', $profile->id)->update([
                        'contact_clean' => substr($digits, -10),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropIndex(['contact_clean']);
            $table->dropColumn('contact_clean');
        });
    }
};
