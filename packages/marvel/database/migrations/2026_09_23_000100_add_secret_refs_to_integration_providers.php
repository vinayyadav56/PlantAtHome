<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials move out of this table and into AWS Secrets Manager; the row keeps a reference.
 *
 *   secret_name        the secret holding this row's bag (plantathome/{environment}/{slug});
 *                      null while the bag is still in the encrypted column or nothing is stored
 *   secret_version_id  the Secrets Manager VersionId last written — what the model's saving hook
 *                      watches to bump credentials_version on this path (the Go sync's version)
 *   last_updated_by    who last changed the credentials or settings, for the admin listing
 *
 * `credentials` is deliberately NOT dropped: it is the local-development driver, and until a
 * row has been migrated and purged it is also the rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integration_providers')) {
            return;
        }

        Schema::table('integration_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_providers', 'secret_name')) {
                $table->string('secret_name', 128)->nullable()->after('credentials_version');
            }
            if (!Schema::hasColumn('integration_providers', 'secret_version_id')) {
                $table->string('secret_version_id', 64)->nullable()->after('secret_name');
            }
            if (!Schema::hasColumn('integration_providers', 'last_updated_by')) {
                $table->unsignedBigInteger('last_updated_by')->nullable()->after('secret_version_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('integration_providers')) {
            return;
        }

        Schema::table('integration_providers', function (Blueprint $table) {
            foreach (['secret_name', 'secret_version_id', 'last_updated_by'] as $column) {
                if (Schema::hasColumn('integration_providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
