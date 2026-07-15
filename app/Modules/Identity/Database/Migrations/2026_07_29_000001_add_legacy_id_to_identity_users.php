<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill support: identity_users rows imported from the legacy users table
 * carry the source row id, making the v2:backfill-users upsert idempotent and
 * verifiable (see App\Shared\Infrastructure\Backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_users') || Schema::hasColumn('identity_users', 'legacy_id')) {
            return;
        }

        Schema::table('identity_users', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->after('uuid');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('identity_users', 'legacy_id')) {
            Schema::table('identity_users', function (Blueprint $table) {
                $table->dropColumn('legacy_id');
            });
        }
    }
};
