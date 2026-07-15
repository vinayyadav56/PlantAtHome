<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — append-only audit trail of coverage mutations
 * (rule_added / rule_removed / sync) with the acting user and a stats payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coverage_audit_logs')) {
            return;
        }

        Schema::create('coverage_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_audit_logs');
    }
};
