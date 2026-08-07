<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request-logs v2: observability-center columns, indexes that match the actual
 * filter set, and a slim exceptions table correlated by request_id.
 *
 * Everything is hasColumn/hasTable-guarded so re-running (or the prune-table
 * endpoint's drop-and-recreate) can never fail. Composite indexes exist because
 * the admin's real queries are "errors in the last day" and "module X over
 * time" — the original single-column indexes forced full scans for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_logs')) {
            Schema::table('request_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('request_logs', 'request_id')) {
                    // ULID minted in middleware handle(); the exception handler
                    // writes the same id, which is what makes correlation work.
                    $table->char('request_id', 26)->nullable()->after('id');
                }
                if (!Schema::hasColumn('request_logs', 'module')) {
                    $table->string('module', 32)->nullable()->after('path');
                }
                if (!Schema::hasColumn('request_logs', 'ua')) {
                    $table->string('ua', 255)->nullable()->after('ip');
                }
                if (!Schema::hasColumn('request_logs', 'device')) {
                    $table->string('device', 10)->nullable()->after('ua');
                }
                if (!Schema::hasColumn('request_logs', 'headers')) {
                    // Allowlisted headers only (referer/origin/accept-language/
                    // content-type) — never the Authorization/Cookie family.
                    $table->text('headers')->nullable()->after('device');
                }
            });

            // Index names checked via getConnection so re-runs are safe on MySQL.
            $existing = collect(Schema::getConnection()
                ->select("SHOW INDEX FROM request_logs"))->pluck('Key_name')->unique()->all();
            Schema::table('request_logs', function (Blueprint $table) use ($existing) {
                if (!in_array('request_logs_status_created_at_index', $existing, true)) {
                    $table->index(['status', 'created_at']);
                }
                if (!in_array('request_logs_module_created_at_index', $existing, true)) {
                    $table->index(['module', 'created_at']);
                }
                if (!in_array('request_logs_ip_created_at_index', $existing, true)) {
                    $table->index(['ip', 'created_at']);
                }
                if (!in_array('request_logs_request_id_index', $existing, true)) {
                    $table->index('request_id');
                }
            });
        }

        if (Schema::hasTable('request_log_settings')) {
            Schema::table('request_log_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('request_log_settings', 'store_response_bodies')) {
                    // Success bodies are the bulk of the table and rarely read;
                    // errors always keep theirs regardless of this switch.
                    $table->boolean('store_response_bodies')->default(false);
                }
            });
        }

        if (!Schema::hasTable('request_log_exceptions')) {
            Schema::create('request_log_exceptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('request_id', 26)->nullable()->index();
                $table->string('class', 191);
                $table->string('message', 500)->nullable();
                $table->string('file', 255)->nullable();
                $table->integer('line')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('path', 255)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_log_exceptions');
        // The added columns/indexes are additive and harmless; deliberately not
        // dropped — a rollback must never destroy captured audit data.
    }
};
