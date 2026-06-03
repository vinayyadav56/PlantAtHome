<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Request/response audit log — every API request in & out (method, path, payload,
 * response, status, error, duration). Sensitive fields are redacted and bodies
 * truncated by the LogRequests middleware. A 1-row settings table controls the
 * on/off switch and retention.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('request_logs')) {
            Schema::create('request_logs', function (Blueprint $table) {
                $table->id();
                $table->string('method', 10);
                $table->string('path', 512)->index();
                $table->integer('status')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('ip', 64)->nullable();
                $table->mediumText('payload')->nullable();
                $table->mediumText('response')->nullable();
                $table->mediumText('error')->nullable();
                $table->integer('duration_ms')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
        if (!Schema::hasTable('request_log_settings')) {
            Schema::create('request_log_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(true);
                $table->integer('retention_days')->default(7);
                $table->boolean('log_get')->default(false); // GET reads are noisy; off by default
                $table->timestamps();
            });
            DB::table('request_log_settings')->insert([
                'enabled' => true, 'retention_days' => 7, 'log_get' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
        Schema::dropIfExists('request_log_settings');
    }
};
